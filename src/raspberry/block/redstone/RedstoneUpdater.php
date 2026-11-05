<?php

declare(strict_types=1);

namespace raspberry\block\redstone;

use raspberry\Server;

use raspberry\block\BlockIds;
use raspberry\block\BlockState;
use raspberry\block\BlockBreakInfo;
use raspberry\block\BlockCollision;
use raspberry\block\BlockProperties;
use raspberry\block\entity\Comparator;
use raspberry\block\entity\BlockEntityFactory;

use raspberry\entity\object\ItemEntity;

use raspberry\item\Item;

use raspberry\math\Facing;
use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\player\GameMode;
use raspberry\player\Player;

use raspberry\world\World;

use function array_fill;
use function array_filter;
use function array_key_first;
use function array_pop;
use function array_values;
use function ceil;
use function count;
use function floor;
use function max;
use function min;
use function preg_match;
use function preg_replace;
use function round;
use function sin;
use function str_contains;
use function str_ends_with;

use const M_PI;

class RedstoneUpdater{

    /** updates handled in one go at most, so a runaway machine cannot hang the server */
    private const MAX_UPDATES = 100000;

    /** pieces of wire one network update looks at */
    private const MAX_WIRES = 4096;

    private const TORCH_DELAY = 2;
    private const COMPARATOR_DELAY = 2;
    private const OBSERVER_DELAY = 2;
    private const LAMP_OFF_DELAY = 4;

    /** a torch that turns off this many times within TORCH_BURNOUT_TICKS stays off for a while */
    private const TORCH_BURNOUT_COUNT = 8;
    private const TORCH_BURNOUT_TICKS = 60;
    private const TORCH_BURNOUT_COOLDOWN = 160;

    /** how long a pressure plate stays down after nothing is on it */
    private const PLATE_DELAY = 20;
    private const WEIGHTED_PLATE_DELAY = 10;

    /** the world clock does not run yet, daylight sensors see noon */
    private const TIME_OF_DAY = 6000;

    private const WIRE_SIDES = [
        Facing::NORTH => BlockProperties::REDSTONE_NORTH,
        Facing::SOUTH => BlockProperties::REDSTONE_SOUTH,
        Facing::WEST => BlockProperties::REDSTONE_WEST,
        Facing::EAST => BlockProperties::REDSTONE_EAST
    ];

    /** blocks whose change can change the power of blocks two away */
    private const POWER_BLOCKS = '/^minecraft:(redstone_wire|redstone_block|(unlit_)?redstone_torch|lever|\w+_button|\w+_pressure_plate|(powered|unpowered)_(repeater|comparator)|observer|daylight_detector(_inverted)?|trapped_chest)$/D';

    private const COPPER_BULB = '/^minecraft:(waxed_)?((exposed|weathered|oxidized)_)?copper_bulb$/D';

    private static ?BlockState $air = null;

    private World $world;

    private RedstoneSignal $signal;

    /** @var array<string, array{int, int, int}> */
    private array $queue = [];

    private bool $running = false;

    /** @var array<string, true> */
    private array $settled = [];

    /** @var array<string, array{string, int}> */
    private array $scheduled = [];

    private int $nextTick = 0;

    /** @var array<string, bool> */
    private array $openablePowered = [];

    /** @var array<string, list<int>> */
    private array $torchOffs = [];

    /** @var array<string, array{int, int, int, int}> */
    private array $plates = [];

    public function __construct(World $world){
        $this->world = $world;
        $this->signal = new RedstoneSignal($world);
    }

    public static function air() : BlockState{
        return self::$air ??= Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::AIR);
    }

    public function getSignal() : RedstoneSignal{
        return $this->signal;
    }

    /**
     * Called after a block changed.
     */
    public function onBlockChanged(int $x, int $y, int $z, BlockState $old, BlockState $new) : void{
        $far = preg_match(self::POWER_BLOCKS, $old->getName()) === 1 || preg_match(self::POWER_BLOCKS, $new->getName()) === 1 || $this->signal->isConductor($old) !== $this->signal->isConductor($new);

        $this->notify($x, $y, $z, $far);
        $this->triggerObservers($x, $y, $z);
        $this->run();
    }

    /**
     * Makes the blocks around x y z look at their power again, for changes that are not block
     * changes (a comparator's level, a container's contents).
     */
    public function update(int $x, int $y, int $z) : void{
        $this->notify($x, $y, $z, true);
        $this->run();
    }

    /**
     * Presses pressure plates under players and dropped items, and lets go of the ones
     * nothing is on anymore.
     */
    public function tick(int $currentTick) : void{
        $found = [];

        foreach($this->world->getEntities() as $entity){
            $isItem = $entity instanceof ItemEntity;

            if($entity->isClosed() || (!$isItem && (!$entity instanceof Player || $entity->isDead() || $entity->getGameMode() === GameMode::SPECTATOR))){
                continue;
            }

            $position = $entity->getPosition();
            [$x, $y, $z] = [(int) floor($position->x), (int) floor($position->y), (int) floor($position->z)];
            $name = $this->signal->getBlock($x, $y, $z)->getName();

            if(!str_ends_with($name, "_pressure_plate") || ($isItem && preg_match('/^minecraft:(stone|polished_blackstone)_pressure_plate$/D', $name) === 1)){
                continue;
            }

            $key = self::key($x, $y, $z);
            $found[$key] ??= [$x, $y, $z, 0];
            ++$found[$key][3];
        }

        foreach($found as $key => [$x, $y, $z, $count]){
            $this->setPlate($x, $y, $z, $count);
            $this->plates[$key] = [$x, $y, $z, $currentTick];
        }

        foreach($this->plates as $key => [$x, $y, $z, $lastTick]){
            $name = $this->signal->getBlock($x, $y, $z)->getName();
            $delay = str_contains($name, "weighted") ? self::WEIGHTED_PLATE_DELAY : self::PLATE_DELAY;

            if(!isset($found[$key]) && $currentTick - $lastTick >= $delay){
                unset($this->plates[$key]);
                $this->setPlate($x, $y, $z, 0);
            }
        }
    }

    private function notify(int $x, int $y, int $z, bool $far) : void{
        $this->enqueue($x, $y, $z);

        foreach(Facing::ALL as $face){
            [$dx, $dy, $dz] = Facing::offset($face);
            $this->enqueue($x + $dx, $y + $dy, $z + $dz);

            if($far){
                foreach(Facing::ALL as $next){
                    [$nx, $ny, $nz] = Facing::offset($next);
                    $this->enqueue($x + $dx + $nx, $y + $dy + $ny, $z + $dz + $nz);
                }
            }
        }
    }

    private function enqueue(int $x, int $y, int $z) : void{
        $this->queue[self::key($x, $y, $z)] ??= [$x, $y, $z];
    }

    private function run() : void{
        if($this->running){
            return;
        }

        $this->running = true;

        try{
            for($count = 0; $this->queue !== [] && $count < self::MAX_UPDATES; ++$count){
                $key = array_key_first($this->queue);
                [$x, $y, $z] = $this->queue[$key];
                unset($this->queue[$key]);

                $this->updateBlock($x, $y, $z);
            }

            $this->queue = [];
        }finally{
            $this->running = false;
            $this->settled = [];
        }
    }

    private function updateBlock(int $x, int $y, int $z) : void{
        $block = $this->signal->getBlock($x, $y, $z);
        $name = $block->getName();

        if(!$this->hasSupport($x, $y, $z, $block)){
            $this->popOff($x, $y, $z, $block);
            return;
        }

        match(true){
            $name === BlockIds::REDSTONE_WIRE => $this->updateWire($x, $y, $z),
            str_ends_with($name, "_repeater") => $this->updateRepeater($x, $y, $z, $block),
            str_ends_with($name, "_comparator") => $this->updateComparator($x, $y, $z, $block),
            $name === BlockIds::REDSTONE_TORCH, $name === BlockIds::UNLIT_REDSTONE_TORCH => $this->updateTorch($x, $y, $z, $block),
            $name === BlockIds::REDSTONE_LAMP, $name === BlockIds::LIT_REDSTONE_LAMP => $this->updateLamp($x, $y, $z, $block),
            $name === BlockIds::DAYLIGHT_DETECTOR, $name === BlockIds::DAYLIGHT_DETECTOR_INVERTED => $this->updateDaylightDetector($x, $y, $z, $block),
            preg_match(self::COPPER_BULB, $name) === 1 => $this->updateCopperBulb($x, $y, $z, $block),
            $block->hasProperty(BlockProperties::DOOR_HINGE_BIT), preg_match('/(trapdoor|fence_gate)$/D', $name) === 1 => $this->updateOpenable($x, $y, $z, $block),
            default => null
        };
    }

    /**
     * Works out the power of every piece of wire connected to this one: each gets the power
     * put into it from outside, or one less than its strongest neighbor.
     */
    private function updateWire(int $x, int $y, int $z) : void{
        if(isset($this->settled[self::key($x, $y, $z)])){
            return;
        }

        $network = [];
        $todo = [[$x, $y, $z]];

        while(($position = array_pop($todo)) !== null && count($network) < self::MAX_WIRES){
            $key = self::key(...$position);

            if(isset($network[$key]) || $this->signal->getBlock(...$position)->getName() !== BlockIds::REDSTONE_WIRE){
                continue;
            }

            $network[$key] = $position;

            foreach($this->signal->getWireLinks(...$position) as $link){
                $todo[] = $link;
            }
        }

        $levels = [];
        $buckets = array_fill(0, 16, []);

        foreach($network as $key => $position){
            $levels[$key] = $this->signal->getWireInput(...$position);
            $buckets[$levels[$key]][] = $key;
            $this->settled[$key] = true;
        }

        for($level = 15; $level > 1; --$level){
            foreach($buckets[$level] as $key){
                if($levels[$key] !== $level){
                    continue;
                }

                foreach($this->signal->getWireLinks(...$network[$key]) as $link){
                    $linkKey = self::key(...$link);

                    if(isset($levels[$linkKey]) && $levels[$linkKey] < $level - 1){
                        $levels[$linkKey] = $level - 1;
                        $buckets[$level - 1][] = $linkKey;
                    }
                }
            }
        }

        foreach($network as $key => [$wx, $wy, $wz]){
            $state = $this->signal->getBlock($wx, $wy, $wz);
            $updated = $state->withProperty(BlockProperties::REDSTONE_SIGNAL, $levels[$key]);

            foreach($this->signal->getWireConnections($wx, $wy, $wz) as $face => $connection){
                $updated = $updated->withProperty(self::WIRE_SIDES[$face], $connection);
            }

            if(!$updated->equals($state)){
                $this->world->setBlock($wx, $wy, $wz, $updated);
            }
        }
    }

    private function updateRepeater(int $x, int $y, int $z, BlockState $block) : void{
        if($this->isRepeaterLocked($x, $y, $z, $block)){
            return;
        }

        $powered = $this->signal->getPowerFrom($x, $y, $z, Facing::opposite(RedstoneSignal::getFacing($block))) > 0;

        if($powered !== ($block->getName() === BlockIds::POWERED_REPEATER)){
            $this->schedule($x, $y, $z, ((int) $block->getProperty(BlockProperties::REPEATER_DELAY) + 1) * 2);
        }
    }

    private function tickRepeater(int $x, int $y, int $z, BlockState $block) : void{
        if($this->isRepeaterLocked($x, $y, $z, $block)){
            return;
        }

        $powered = $this->signal->getPowerFrom($x, $y, $z, Facing::opposite(RedstoneSignal::getFacing($block))) > 0;

        if($powered !== ($block->getName() === BlockIds::POWERED_REPEATER)){
            $this->world->setBlock($x, $y, $z, self::rename($block, $powered ? BlockIds::POWERED_REPEATER : BlockIds::UNPOWERED_REPEATER));
        }
    }

    /**
     * A repeater stays as it is while a powered repeater or comparator points into its side.
     */
    private function isRepeaterLocked(int $x, int $y, int $z, BlockState $block) : bool{
        $facing = RedstoneSignal::getFacing($block);

        foreach([Facing::rotateLeft($facing), Facing::rotateRight($facing)] as $side){
            [$dx, , $dz] = Facing::offset($side);
            $name = $this->signal->getBlock($x + $dx, $y, $z + $dz)->getName();

            if((str_ends_with($name, "_repeater") || str_ends_with($name, "_comparator")) && $this->signal->emit($x + $dx, $y, $z + $dz, Facing::opposite($side)) > 0){
                return true;
            }
        }

        return false;
    }

    private function updateComparator(int $x, int $y, int $z, BlockState $block) : void{
        $output = $this->getComparatorResult($x, $y, $z, $block);

        if($output !== $this->signal->getComparatorOutput($x, $y, $z) || ($output > 0) !== ($block->getName() === BlockIds::POWERED_COMPARATOR)){
            $this->schedule($x, $y, $z, self::COMPARATOR_DELAY);
        }
    }

    private function tickComparator(int $x, int $y, int $z, BlockState $block) : void{
        $output = $this->getComparatorResult($x, $y, $z, $block);
        $entity = $this->world->getBlockEntity($x, $y, $z);

        if(!$entity instanceof Comparator){
            $entity = BlockEntityFactory::create($block->getName(), $x, $y, $z);

            if(!$entity instanceof Comparator){
                return;
            }

            $this->world->addBlockEntity($entity);
        }

        $changed = $entity->getOutput() !== $output;
        $entity->setOutput($output);

        $state = self::rename($block, $output > 0 ? BlockIds::POWERED_COMPARATOR : BlockIds::UNPOWERED_COMPARATOR)->withProperty(BlockProperties::OUTPUT_LIT_BIT, $output > 0);

        if(!$state->equals($block)){
            $this->world->setBlock($x, $y, $z, $state);
        }elseif($changed){
            $this->notify($x, $y, $z, true);
        }
    }

    /**
     * The comparator's output: the power or container behind it, compared with (or less) the
     * strongest side input.
     */
    private function getComparatorResult(int $x, int $y, int $z, BlockState $block) : int{
        $facing = RedstoneSignal::getFacing($block);
        [$dx, , $dz] = Facing::offset($facing);
        [$bx, $bz] = [$x - $dx, $z - $dz];
        $rear = $this->signal->getPowerFrom($x, $y, $z, Facing::opposite($facing));
        $containerSignal = $this->signal->getContainerSignal($bx, $y, $bz);

        if($containerSignal === null && $rear < 15 && $this->signal->isConductor($this->signal->getBlock($bx, $y, $bz))){
            $containerSignal = $this->signal->getContainerSignal($bx - $dx, $y, $bz - $dz);
        }

        if($containerSignal !== null){
            $rear = $containerSignal;
        }

        $side = 0;

        foreach([Facing::rotateLeft($facing), Facing::rotateRight($facing)] as $face){
            [$sx, , $sz] = Facing::offset($face);
            $neighbor = $this->signal->getBlock($x + $sx, $y, $z + $sz);
            $name = $neighbor->getName();

            $side = max($side, match(true){
                $name === BlockIds::REDSTONE_WIRE => (int) $neighbor->getProperty(BlockProperties::REDSTONE_SIGNAL),
                $name === BlockIds::REDSTONE_BLOCK => 15,
                str_ends_with($name, "_repeater"), str_ends_with($name, "_comparator") => $this->signal->emit($x + $sx, $y, $z + $sz, Facing::opposite($face)),
                default => 0
            });
        }

        if($block->getProperty(BlockProperties::OUTPUT_SUBTRACT_BIT) === true){
            return max($rear - $side, 0);
        }

        return $rear >= $side ? $rear : 0;
    }

    /**
     * A torch turns off while the block it is on gets power.
     */
    private function updateTorch(int $x, int $y, int $z, BlockState $block) : void{
        if($this->isTorchBlockPowered($x, $y, $z, $block) === ($block->getName() === BlockIds::REDSTONE_TORCH)){
            $this->schedule($x, $y, $z, self::TORCH_DELAY);
        }
    }

    private function tickTorch(int $x, int $y, int $z, BlockState $block) : void{
        $key = self::key($x, $y, $z);
        $now = $this->world->getCurrentTick();
        $offs = array_values(array_filter($this->torchOffs[$key] ?? [], fn(int $tick) : bool => $now - $tick < self::TORCH_BURNOUT_TICKS));
        $lit = $block->getName() === BlockIds::REDSTONE_TORCH;
        $powered = $this->isTorchBlockPowered($x, $y, $z, $block);

        if($lit && $powered){
            $offs[] = $now;
            $this->world->setBlock($x, $y, $z, self::rename($block, BlockIds::UNLIT_REDSTONE_TORCH));
        }elseif(!$lit && !$powered){
            if(count($offs) >= self::TORCH_BURNOUT_COUNT){
                //burnt out: try again later
                $this->schedule($x, $y, $z, self::TORCH_BURNOUT_COOLDOWN);
            }else{
                $this->world->setBlock($x, $y, $z, self::rename($block, BlockIds::REDSTONE_TORCH));
            }
        }

        if($offs === []){
            unset($this->torchOffs[$key]);
        }else{
            $this->torchOffs[$key] = $offs;
        }
    }

    private function isTorchBlockPowered(int $x, int $y, int $z, BlockState $block) : bool{
        [$dx, $dy, $dz] = Facing::offset(RedstoneSignal::getAttachment($block));
        [$ax, $ay, $az] = [$x + $dx, $y + $dy, $z + $dz];

        return $this->signal->isConductor($this->signal->getBlock($ax, $ay, $az)) && $this->signal->getPowerInto($ax, $ay, $az) > 0;
    }

    private function updateLamp(int $x, int $y, int $z, BlockState $block) : void{
        $powered = $this->signal->getReceivedPower($x, $y, $z) > 0;
        $lit = $block->getName() === BlockIds::LIT_REDSTONE_LAMP;

        if($powered && !$lit){
            $this->world->setBlock($x, $y, $z, self::rename($block, BlockIds::LIT_REDSTONE_LAMP));
        }elseif(!$powered && $lit){
            $this->schedule($x, $y, $z, self::LAMP_OFF_DELAY);
        }
    }

    private function tickLamp(int $x, int $y, int $z, BlockState $block) : void{
        if($block->getName() === BlockIds::LIT_REDSTONE_LAMP && $this->signal->getReceivedPower($x, $y, $z) === 0){
            $this->world->setBlock($x, $y, $z, self::rename($block, BlockIds::REDSTONE_LAMP));
        }
    }

    private function updateDaylightDetector(int $x, int $y, int $z, BlockState $block) : void{
        $daylight = (int) round(15 * max(0.0, sin(M_PI * self::TIME_OF_DAY / 12000)));
        $level = $block->getName() === BlockIds::DAYLIGHT_DETECTOR_INVERTED ? 15 - $daylight : $daylight;

        if((int) $block->getProperty(BlockProperties::REDSTONE_SIGNAL) !== $level){
            $this->world->setBlock($x, $y, $z, $block->withProperty(BlockProperties::REDSTONE_SIGNAL, $level));
        }
    }

    /**
     * Copper bulbs switch on or off each time they get power.
     */
    private function updateCopperBulb(int $x, int $y, int $z, BlockState $block) : void{
        $powered = $this->signal->getReceivedPower($x, $y, $z) > 0;

        if($powered === ($block->getProperty(BlockProperties::POWERED_BIT) === true)){
            return;
        }

        $state = $block->withProperty(BlockProperties::POWERED_BIT, $powered);

        if($powered){
            $lit = $block->getProperty(BlockProperties::LIT) !== true;
            $state = $state->withProperty(BlockProperties::LIT, $lit);
            $this->playSound($lit ? LevelSoundEvent::BLOCK_COPPER_BULB_TURN_ON : LevelSoundEvent::BLOCK_COPPER_BULB_TURN_OFF, $x, $y, $z);
        }

        $this->world->setBlock($x, $y, $z, $state);
    }

    /**
     * Doors, trapdoors and fence gates open when they get power and close when it goes. They
     * only change when the power does, so one opened by hand stays open.
     */
    private function updateOpenable(int $x, int $y, int $z, BlockState $block) : void{
        $door = $block->hasProperty(BlockProperties::DOOR_HINGE_BIT);
        $lowerY = $door && $block->getProperty(BlockProperties::UPPER_BLOCK_BIT) === true ? $y - 1 : $y;
        $powered = $this->signal->getReceivedPower($x, $lowerY, $z) > 0 || ($door && $this->signal->getReceivedPower($x, $lowerY + 1, $z) > 0);
        $key = self::key($x, $lowerY, $z);
        $was = $this->openablePowered[$key] ?? false;

        if($powered){
            $this->openablePowered[$key] = true;
        }else{
            unset($this->openablePowered[$key]);
        }

        if($was === $powered){
            return;
        }

        foreach($door ? [$lowerY, $lowerY + 1] : [$y] as $partY){
            $part = $this->signal->getBlock($x, $partY, $z);

            if($part->getName() === $block->getName() && $part->getProperty(BlockProperties::OPEN_BIT) !== $powered){
                $this->world->setBlock($x, $partY, $z, $part->withProperty(BlockProperties::OPEN_BIT, $powered));
            }
        }

        $sound = match(true){
            $door => $powered ? LevelSoundEvent::DOOR_OPEN : LevelSoundEvent::DOOR_CLOSE,
            str_ends_with($block->getName(), "trapdoor") => $powered ? LevelSoundEvent::TRAPDOOR_OPEN : LevelSoundEvent::TRAPDOOR_CLOSE,
            default => $powered ? LevelSoundEvent::FENCE_GATE_OPEN : LevelSoundEvent::FENCE_GATE_CLOSE
        };

        $this->playSound($sound, $x, $lowerY, $z);
    }

    /**
     * Observers watching x y z send a short pulse out of their back.
     */
    private function triggerObservers(int $x, int $y, int $z) : void{
        foreach(Facing::ALL as $face){
            [$dx, $dy, $dz] = Facing::offset($face);
            [$ox, $oy, $oz] = [$x + $dx, $y + $dy, $z + $dz];
            $observer = $this->signal->getBlock($ox, $oy, $oz);

            if($observer->getName() === BlockIds::OBSERVER && RedstoneSignal::getObserverFacing($observer) === Facing::opposite($face) && $observer->getProperty(BlockProperties::POWERED_BIT) !== true){
                $this->schedule($ox, $oy, $oz, self::OBSERVER_DELAY);
            }
        }
    }

    private function tickObserver(int $x, int $y, int $z, BlockState $block) : void{
        $powered = $block->getProperty(BlockProperties::POWERED_BIT) !== true;
        $this->world->setBlock($x, $y, $z, $block->withProperty(BlockProperties::POWERED_BIT, $powered));

        if($powered){
            $this->schedule($x, $y, $z, self::OBSERVER_DELAY);
        }
    }

    private function setPlate(int $x, int $y, int $z, int $count) : void{
        $block = $this->signal->getBlock($x, $y, $z);
        $name = $block->getName();

        if(!str_ends_with($name, "_pressure_plate")){
            return;
        }

        $level = match(true){
            $count === 0 => 0,
            $name === BlockIds::LIGHT_WEIGHTED_PRESSURE_PLATE => min($count, 15),
            $name === BlockIds::HEAVY_WEIGHTED_PRESSURE_PLATE => min((int) ceil($count / 10), 15),
            default => 15
        };
        $current = (int) $block->getProperty(BlockProperties::REDSTONE_SIGNAL);

        if($level === $current){
            return;
        }

        $this->world->setBlock($x, $y, $z, $block->withProperty(BlockProperties::REDSTONE_SIGNAL, $level));

        if(($level > 0) !== ($current > 0)){
            $this->playSound($level > 0 ? LevelSoundEvent::PRESSURE_PLATE_CLICK_ON : LevelSoundEvent::PRESSURE_PLATE_CLICK_OFF, $x, $y, $z);
        }
    }

    /**
     * Ticks the block at x y z after $delay game ticks, once. A tick waiting for a block that
     * was replaced by another kind is dropped.
     */
    private function schedule(int $x, int $y, int $z, int $delay) : void{
        $key = self::key($x, $y, $z);
        $kind = self::kind($this->signal->getBlock($x, $y, $z)->getName());

        if(($this->scheduled[$key][0] ?? null) === $kind){
            return;
        }

        $id = ++$this->nextTick;
        $this->scheduled[$key] = [$kind, $id];

        $this->world->scheduleUpdate($delay, function() use ($x, $y, $z, $key, $id, $kind) : void{
            if(($this->scheduled[$key][1] ?? null) !== $id){
                return;
            }

            unset($this->scheduled[$key]);

            $block = $this->signal->getBlock($x, $y, $z);
            $name = $block->getName();

            if(self::kind($name) !== $kind){
                return;
            }

            match(true){
                str_ends_with($name, "_repeater") => $this->tickRepeater($x, $y, $z, $block),
                str_ends_with($name, "_comparator") => $this->tickComparator($x, $y, $z, $block),
                $name === BlockIds::REDSTONE_TORCH, $name === BlockIds::UNLIT_REDSTONE_TORCH => $this->tickTorch($x, $y, $z, $block),
                $name === BlockIds::LIT_REDSTONE_LAMP => $this->tickLamp($x, $y, $z, $block),
                $name === BlockIds::OBSERVER => $this->tickObserver($x, $y, $z, $block),
                default => null
            };

            $this->run();
        });
    }

    /**
     * Wire, torches, levers, buttons, plates, repeaters and comparators break off when the
     * block holding them goes.
     */
    private function hasSupport(int $x, int $y, int $z, BlockState $block) : bool{
        $name = $block->getName();

        if($name === BlockIds::REDSTONE_WIRE || str_ends_with($name, "_repeater") || str_ends_with($name, "_comparator") || str_ends_with($name, "_pressure_plate")){
            return BlockCollision::isSolid($this->signal->getBlock($x, $y - 1, $z));
        }

        if($name === BlockIds::REDSTONE_TORCH || $name === BlockIds::UNLIT_REDSTONE_TORCH || $name === BlockIds::LEVER || str_ends_with($name, "_button")){
            [$dx, $dy, $dz] = Facing::offset(RedstoneSignal::getAttachment($block));

            return BlockCollision::isSolid($this->signal->getBlock($x + $dx, $y + $dy, $z + $dz));
        }

        return true;
    }

    private function popOff(int $x, int $y, int $z, BlockState $block) : void{
        $drops = BlockBreakInfo::get($block->getName())->getDrops($block, Item::air());
        $this->world->setBlock($x, $y, $z, self::air());

        foreach($drops as $drop){
            $this->world->dropItem(new Vector3($x + 0.5, $y + 0.5, $z + 0.5), $drop);
        }
    }

    private function playSound(string $sound, int $x, int $y, int $z) : void{
        $this->world->broadcastPacket($x, $z, LevelSoundEventPacket::create($sound, $x + 0.5, $y + 0.5, $z + 0.5));
    }

    /**
     * The same block under another name (lit and unlit lamps, powered repeaters...).
     */
    private static function rename(BlockState $block, string $name) : BlockState{
        if($block->getName() === $name){
            return $block;
        }

        $state = Server::getInstance()->getBlockStateRegistry()->getDefaultState($name);

        foreach($block->getProperties() as $property => $value){
            if($state->hasProperty($property)){
                $state = $state->withProperty($property, $value);
            }
        }

        return $state;
    }

    /**
     * The block without its on or off part of the name: lit and unlit torches are one kind.
     */
    private static function kind(string $name) : string{
        return preg_replace('/^minecraft:(unpowered_|powered_|unlit_|lit_)/', 'minecraft:', $name) ?? $name;
    }

    private static function key(int $x, int $y, int $z) : string{
        return "{$x}:{$y}:{$z}";
    }
}