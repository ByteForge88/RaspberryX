<?php

declare(strict_types=1);

namespace raspberry\block;

use Closure;

use raspberry\Server;

use raspberry\block\redstone\RedstoneSignal;

use raspberry\entity\Entity;
use raspberry\entity\Living;

use raspberry\math\Facing;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\player\GameMode;
use raspberry\player\Player;

use raspberry\world\World;

use function floor;
use function max;

class Tripwire{

    public const int MAX_LENGTH = 42;

    public const int HOOK_DELAY = 10;

    private const int HOLD_TICKS = 10;

    private const float ATTACHED_BOTTOM = 0.0625;
    private const float ATTACHED_TOP = 0.15625;
    private const float LOOSE_TOP = 0.5;
    private const float MIN_HEIGHT = 0.25;

    private const array CONNECTIONS = [
        Facing::NORTH => BlockProperties::MINECRAFT_CONNECTION_NORTH,
        Facing::SOUTH => BlockProperties::MINECRAFT_CONNECTION_SOUTH,
        Facing::WEST => BlockProperties::MINECRAFT_CONNECTION_WEST,
        Facing::EAST => BlockProperties::MINECRAFT_CONNECTION_EAST
    ];

    private World $world;

    private RedstoneSignal $signal;

    private Closure $schedule;

    /** @var array<string, array{int, int, int, int}> */
    private array $pressed = [];

    /**
     * @param Closure(int, int, int) : void $schedule
     */
    public function __construct(World $world, RedstoneSignal $signal, Closure $schedule){
        $this->world = $world;
        $this->signal = $signal;
        $this->schedule = $schedule;
    }

    public static function isWire(string $name) : bool{
        return $name === BlockIds::TRIP_WIRE;
    }

    public static function isHook(string $name) : bool{
        return $name === BlockIds::TRIPWIRE_HOOK;
    }

    public static function getHookFacing(BlockState $hook) : int{
        return Facing::fromLegacyDirection((int) $hook->getProperty(BlockProperties::DIRECTION));
    }

    public static function isPowered(BlockState $block) : bool{
        return $block->getProperty(BlockProperties::POWERED_BIT) === true;
    }

    public static function disarm(World $world, int $x, int $y, int $z) : void{
        $wire = $world->getBlock($x, $y, $z);

        if(self::isWire($wire->getName())){
            $world->setBlock($x, $y, $z, $wire->withProperty(BlockProperties::DISARMED_BIT, true));
        }
    }

    public function onBlockChanged(int $x, int $y, int $z, BlockState $old, BlockState $new) : void{
        $oldName = $old->getName();
        $newName = $new->getName();

        if($oldName === $newName){
            return;
        }

        if(self::isWire($newName)){
            $wire = $this->withConnections($x, $y, $z, $new);
            $this->set($x, $y, $z, $wire);
            $this->updateSource($x, $y, $z, $wire);
        }elseif(self::isWire($oldName)){
            unset($this->pressed["{$x}:{$y}:{$z}"]);
            $this->updateSource($x, $y, $z, $old->withProperty(BlockProperties::POWERED_BIT, true));
        }

        if(self::isHook($newName)){
            $this->calculate($x, $y, $z, $new, false, -1, null);
        }elseif(self::isHook($oldName) && ($old->getProperty(BlockProperties::ATTACHED_BIT) === true || self::isPowered($old))){
            $this->calculate($x, $y, $z, $old, true, -1, null);
        }
    }

    public function updateShape(int $x, int $y, int $z, BlockState $wire) : void{
        $this->set($x, $y, $z, $this->withConnections($x, $y, $z, $wire));
    }

    public function tickHook(int $x, int $y, int $z, BlockState $hook) : void{
        $this->calculate($x, $y, $z, $hook, false, -1, null);
    }

    public function touch(Entity $entity, int $currentTick) : void{
        if($entity instanceof Player && ($entity->isDead() || $entity->getGameMode() === GameMode::SPECTATOR)){
            return;
        }

        if($entity instanceof Living && !$entity->isAlive()){
            return;
        }

        $position = $entity->getPosition();
        $half = $entity->getWidth() / 2;
        $y = (int) floor($position->y);
        $top = $position->y + max($entity->getHeight(), self::MIN_HEIGHT);

        for($x = (int) floor($position->x - $half), $maxX = (int) floor($position->x + $half); $x <= $maxX; ++$x){
            for($z = (int) floor($position->z - $half), $maxZ = (int) floor($position->z + $half); $z <= $maxZ; ++$z){
                $wire = $this->signal->getBlock($x, $y, $z);

                if(!self::isWire($wire->getName())){
                    continue;
                }

                $attached = $wire->getProperty(BlockProperties::ATTACHED_BIT) === true;
                $bottom = $y + ($attached ? self::ATTACHED_BOTTOM : 0.0);

                if($position->y >= $y + ($attached ? self::ATTACHED_TOP : self::LOOSE_TOP) || $top < $bottom){
                    continue;
                }

                $this->pressed["{$x}:{$y}:{$z}"] = [$x, $y, $z, $currentTick];

                if(!self::isPowered($wire)){
                    $this->setWirePowered($x, $y, $z, $wire, true);
                }
            }
        }
    }

    public function release(int $currentTick) : void{
        foreach($this->pressed as $key => [$x, $y, $z, $lastTick]){
            if($currentTick - $lastTick < self::HOLD_TICKS){
                continue;
            }

            unset($this->pressed[$key]);
            $wire = $this->signal->getBlock($x, $y, $z);

            if(self::isWire($wire->getName()) && self::isPowered($wire)){
                $this->setWirePowered($x, $y, $z, $wire, false);
            }
        }
    }

    private function setWirePowered(int $x, int $y, int $z, BlockState $wire, bool $powered) : void{
        $wire = $wire->withProperty(BlockProperties::POWERED_BIT, $powered);
        $this->world->setBlock($x, $y, $z, $wire);
        $this->updateSource($x, $y, $z, $wire);
    }

    private function updateSource(int $x, int $y, int $z, BlockState $wire) : void{
        foreach([Facing::SOUTH, Facing::WEST] as $direction){
            [$dx, , $dz] = Facing::offset($direction);

            for($i = 1; $i < self::MAX_LENGTH; ++$i){
                [$px, $pz] = [$x + $dx * $i, $z + $dz * $i];
                $block = $this->signal->getBlock($px, $y, $pz);

                if(self::isHook($block->getName())){
                    if(self::getHookFacing($block) === Facing::opposite($direction)){
                        $this->calculate($px, $y, $pz, $block, false, $i, $wire);
                    }

                    break;
                }

                if(!self::isWire($block->getName())){
                    break;
                }
            }
        }
    }

    private function calculate(int $x, int $y, int $z, BlockState $hook, bool $removed, int $source, ?BlockState $sourceState) : void{
        $facing = self::getHookFacing($hook);
        $wasAttached = $hook->getProperty(BlockProperties::ATTACHED_BIT) === true;
        $wasPowered = self::isPowered($hook);
        [$dx, , $dz] = Facing::offset($facing);

        $unbroken = !$removed;
        $powered = false;
        $other = 0;
        $wires = [];

        for($j = 1; $j < self::MAX_LENGTH; ++$j){
            $block = $this->signal->getBlock($x + $dx * $j, $y, $z + $dz * $j);

            if(self::isHook($block->getName())){
                if(self::getHookFacing($block) === Facing::opposite($facing)){
                    $other = $j;
                }

                break;
            }

            if(!self::isWire($block->getName()) && $j !== $source){
                $unbroken = false;
                continue;
            }

            if($j === $source && $sourceState !== null){
                $block = $sourceState;
            }

            $armed = $block->getProperty(BlockProperties::DISARMED_BIT) !== true;
            $powered = $powered || ($armed && self::isPowered($block));
            $wires[$j] = true;

            if($j === $source){
                ($this->schedule)($x, $y, $z);
                $unbroken = $unbroken && $armed;
            }
        }

        $unbroken = $unbroken && $other > 1;
        $powered = $powered && $unbroken;

        if($other > 0){
            [$ox, $oz] = [$x + $dx * $other, $z + $dz * $other];
            $this->set($ox, $y, $oz, $this->hookState(Facing::opposite($facing), $unbroken, $powered));
            $this->playSound($ox, $y, $oz, $unbroken, $powered, $wasAttached, $wasPowered);
        }

        $this->playSound($x, $y, $z, $unbroken, $powered, $wasAttached, $wasPowered);

        if(!$removed){
            $this->set($x, $y, $z, $this->hookState($facing, $unbroken, $powered));
        }

        if($wasAttached === $unbroken){
            return;
        }

        for($k = 1; $k < $other; ++$k){
            [$px, $pz] = [$x + $dx * $k, $z + $dz * $k];
            $wire = $this->signal->getBlock($px, $y, $pz);

            if(isset($wires[$k]) && self::isWire($wire->getName())){
                $this->set($px, $y, $pz, $wire->withProperty(BlockProperties::ATTACHED_BIT, $unbroken));
            }
        }
    }

    private function hookState(int $facing, bool $attached, bool $powered) : BlockState{
        return Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::TRIPWIRE_HOOK)
            ->withProperty(BlockProperties::DIRECTION, Facing::toLegacyDirection($facing))
            ->withProperty(BlockProperties::ATTACHED_BIT, $attached)
            ->withProperty(BlockProperties::POWERED_BIT, $powered);
    }

    private function withConnections(int $x, int $y, int $z, BlockState $wire) : BlockState{
        foreach(self::CONNECTIONS as $face => $property){
            if(!$wire->hasProperty($property)){
                continue;
            }

            [$dx, , $dz] = Facing::offset($face);
            $block = $this->signal->getBlock($x + $dx, $y, $z + $dz);
            $connects = self::isWire($block->getName()) || (self::isHook($block->getName()) && self::getHookFacing($block) === Facing::opposite($face));
            $wire = $wire->withProperty($property, $connects);
        }

        return $wire;
    }

    private function set(int $x, int $y, int $z, BlockState $block) : void{
        if(!$this->world->getBlock($x, $y, $z)->equals($block)){
            $this->world->setBlock($x, $y, $z, $block);
        }
    }

    private function playSound(int $x, int $y, int $z, bool $attached, bool $powered, bool $wasAttached, bool $wasPowered) : void{
        $sound = match(true){
            $attached && !$wasAttached => LevelSoundEvent::ATTACH,
            !$attached && $wasAttached => LevelSoundEvent::DETACH,
            $powered && !$wasPowered => LevelSoundEvent::POWER_ON,
            !$powered && $wasPowered => LevelSoundEvent::POWER_OFF,
            default => null
        };

        if($sound !== null){
            $this->world->broadcastPacket($x, $z, LevelSoundEventPacket::create($sound, $x + 0.5, $y + 0.5, $z + 0.5));
        }
    }
}