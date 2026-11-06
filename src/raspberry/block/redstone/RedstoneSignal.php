<?php

declare(strict_types=1);

namespace raspberry\block\redstone;

use raspberry\block\BlockIds;
use raspberry\block\BlockState;
use raspberry\block\BlockCollision;
use raspberry\block\BlockProperties;
use raspberry\block\entity\Chest;
use raspberry\block\entity\Crafter;
use raspberry\block\entity\Jukebox;
use raspberry\block\entity\Lectern;
use raspberry\block\entity\Comparator;
use raspberry\block\entity\ContainerBlockEntity;

use raspberry\math\Facing;

use raspberry\world\Chunk;
use raspberry\world\World;

use function count;
use function floor;
use function max;
use function min;
use function preg_match;
use function str_ends_with;
use function str_starts_with;

class RedstoneSignal{

    /** full cubes that do not carry power */
    private const NOT_CONDUCTIVE = '/^minecraft:(redstone_block|observer|(sticky_)?piston|glowstone|sea_lantern|barrier)$/D';

    private const BUTTON = '/_button$/D';

    private const PRESSURE_PLATE = '/_pressure_plate$/D';

    private World $world;

    public function __construct(World $world){
        $this->world = $world;
    }

    /**
     * The block at a position, air when its chunk is not loaded.
     */
    public function getBlock(int $x, int $y, int $z) : BlockState{
        if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y || !$this->world->isChunkLoaded($x >> 4, $z >> 4)){
            return RedstoneUpdater::air();
        }

        return $this->world->getBlock($x, $y, $z);
    }

    /**
     * Solid opaque blocks pass strong power on to what is next to them.
     */
    public function isConductor(BlockState $block) : bool{
        return BlockCollision::isFullCube($block) && preg_match(self::NOT_CONDUCTIVE, $block->getName()) !== 1;
    }

    /**
     * Power the block at x y z gives to its neighbor in $direction. Weak power works the
     * neighbor directly but is not passed on by a solid block.
     */
    public function emit(int $x, int $y, int $z, int $direction) : int{
        $block = $this->getBlock($x, $y, $z);
        $name = $block->getName();

        return match(true){
            $name === BlockIds::REDSTONE_WIRE => $this->wireEmits($x, $y, $z, $block, $direction) ? (int) $block->getProperty(BlockProperties::REDSTONE_SIGNAL) : 0,
            $name === BlockIds::REDSTONE_BLOCK => 15,
            $name === BlockIds::REDSTONE_TORCH => $direction === self::getAttachment($block) ? 0 : 15,
            $name === BlockIds::LEVER => $block->getProperty(BlockProperties::OPEN_BIT) === true ? 15 : 0,
            preg_match(self::BUTTON, $name) === 1 => $block->getProperty(BlockProperties::BUTTON_PRESSED_BIT) === true ? 15 : 0,
            preg_match(self::PRESSURE_PLATE, $name) === 1, $name === BlockIds::DAYLIGHT_DETECTOR, $name === BlockIds::DAYLIGHT_DETECTOR_INVERTED => (int) $block->getProperty(BlockProperties::REDSTONE_SIGNAL),
            $name === BlockIds::POWERED_REPEATER => $direction === self::getFacing($block) ? 15 : 0,
            $name === BlockIds::POWERED_COMPARATOR, $name === BlockIds::UNPOWERED_COMPARATOR => $direction === self::getFacing($block) ? $this->getComparatorOutput($x, $y, $z) : 0,
            $name === BlockIds::OBSERVER => $block->getProperty(BlockProperties::POWERED_BIT) === true && $direction === Facing::opposite(self::getObserverFacing($block)) ? 15 : 0,
            $name === BlockIds::TRAPPED_CHEST => $this->getTrappedChestPower($x, $y, $z),
            $name === BlockIds::LECTERN => $block->getProperty(BlockProperties::POWERED_BIT) === true ? 15 : 0,
            default => 0
        };
    }

    /**
     * Strong power the block at x y z puts into the block in $direction. A solid block that
     * gets strong power gives it to everything around it.
     */
    public function emitStrong(int $x, int $y, int $z, int $direction) : int{
        $block = $this->getBlock($x, $y, $z);
        $name = $block->getName();

        return match(true){
            $name === BlockIds::REDSTONE_TORCH => $direction === Facing::UP ? 15 : 0,
            $name === BlockIds::LEVER, preg_match(self::BUTTON, $name) === 1 => $direction === self::getAttachment($block) ? $this->emit($x, $y, $z, $direction) : 0,
            preg_match(self::PRESSURE_PLATE, $name) === 1, $name === BlockIds::TRAPPED_CHEST, $name === BlockIds::LECTERN => $direction === Facing::DOWN ? $this->emit($x, $y, $z, $direction) : 0,
            $name === BlockIds::POWERED_REPEATER, $name === BlockIds::POWERED_COMPARATOR, $name === BlockIds::UNPOWERED_COMPARATOR, $name === BlockIds::OBSERVER => $this->emit($x, $y, $z, $direction),
            default => 0
        };
    }

    /**
     * Strong power going into the solid block at x y z, which it passes on to everything.
     */
    public function getStrongPowerInto(int $x, int $y, int $z) : int{
        $power = 0;

        foreach(Facing::ALL as $face){
            [$dx, $dy, $dz] = Facing::offset($face);
            $power = max($power, $this->emitStrong($x + $dx, $y + $dy, $z + $dz, Facing::opposite($face)));

            if($power >= 15){
                break;
            }
        }

        return $power;
    }

    /**
     * Any power going into the solid block at x y z: strong power, or wire pointing into it.
     * That is enough to work a lamp or door next to it, but not to power wire.
     */
    public function getPowerInto(int $x, int $y, int $z) : int{
        $power = $this->getStrongPowerInto($x, $y, $z);

        foreach(Facing::ALL as $face){
            [$dx, $dy, $dz] = Facing::offset($face);

            if($power < 15 && $this->getBlock($x + $dx, $y + $dy, $z + $dz)->getName() === BlockIds::REDSTONE_WIRE){
                $power = max($power, $this->emit($x + $dx, $y + $dy, $z + $dz, Facing::opposite($face)));
            }
        }

        return $power;
    }

    /**
     * The strongest power reaching the block at x y z from any side, directly or through a
     * solid block next to it.
     */
    public function getReceivedPower(int $x, int $y, int $z) : int{
        $power = 0;

        foreach(Facing::ALL as $face){
            $power = max($power, $this->getPowerFrom($x, $y, $z, $face));

            if($power >= 15){
                break;
            }
        }

        return $power;
    }

    /**
     * Power reaching the block at x y z from its neighbor on side $face.
     */
    public function getPowerFrom(int $x, int $y, int $z, int $face) : int{
        [$dx, $dy, $dz] = Facing::offset($face);
        [$nx, $ny, $nz] = [$x + $dx, $y + $dy, $z + $dz];
        $power = $this->emit($nx, $ny, $nz, Facing::opposite($face));

        if($power < 15 && $this->isConductor($this->getBlock($nx, $ny, $nz))){
            $power = max($power, $this->getPowerInto($nx, $ny, $nz));
        }

        return $power;
    }

    /**
     * What powers a piece of wire from outside its own network.
     */
    public function getWireInput(int $x, int $y, int $z) : int{
        $power = 0;

        foreach(Facing::ALL as $face){
            [$dx, $dy, $dz] = Facing::offset($face);
            [$nx, $ny, $nz] = [$x + $dx, $y + $dy, $z + $dz];
            $neighbor = $this->getBlock($nx, $ny, $nz);

            if($neighbor->getName() === BlockIds::REDSTONE_WIRE){
                continue;
            }

            $power = max($power, $this->emit($nx, $ny, $nz, Facing::opposite($face)));

            if($this->isConductor($neighbor)){
                $power = max($power, $this->getStrongPowerInto($nx, $ny, $nz));
            }
        }

        return $power;
    }

    /**
     * The wire this wire connects to, also up and down a block.
     *
     * @return list<array{int, int, int}>
     */
    public function getWireLinks(int $x, int $y, int $z) : array{
        $links = [];
        $coveredAbove = $this->isConductor($this->getBlock($x, $y + 1, $z));

        foreach(Facing::HORIZONTAL as $face){
            [$dx, , $dz] = Facing::offset($face);
            [$nx, $nz] = [$x + $dx, $z + $dz];
            $side = $this->getBlock($nx, $y, $nz);

            if($side->getName() === BlockIds::REDSTONE_WIRE){
                $links[] = [$nx, $y, $nz];
                continue;
            }

            if(!$coveredAbove && $this->getBlock($nx, $y + 1, $nz)->getName() === BlockIds::REDSTONE_WIRE){
                $links[] = [$nx, $y + 1, $nz];
            }

            if(!$this->isConductor($side) && $this->getBlock($nx, $y - 1, $nz)->getName() === BlockIds::REDSTONE_WIRE){
                $links[] = [$nx, $y - 1, $nz];
            }
        }

        return $links;
    }

    /**
     * How the wire looks on each side: "none", "side" or "up" (climbing the block next to it).
     *
     * @return array<int, string>
     */
    public function getWireConnections(int $x, int $y, int $z) : array{
        $connections = [];
        $coveredAbove = $this->isConductor($this->getBlock($x, $y + 1, $z));

        foreach(Facing::HORIZONTAL as $face){
            [$dx, , $dz] = Facing::offset($face);
            [$nx, $nz] = [$x + $dx, $z + $dz];
            $side = $this->getBlock($nx, $y, $nz);

            $connections[$face] = match(true){
                !$coveredAbove && $this->getBlock($nx, $y + 1, $nz)->getName() === BlockIds::REDSTONE_WIRE => "up",
                $this->connectsToWire($side, Facing::opposite($face)),
                    !$this->isConductor($side) && $this->getBlock($nx, $y - 1, $nz)->getName() === BlockIds::REDSTONE_WIRE => "side",
                default => "none"
            };
        }

        return $connections;
    }

    /**
     * Whether $block, reached from wire on its side $face, makes the wire turn toward it.
     */
    public function connectsToWire(BlockState $block, int $face) : bool{
        $name = $block->getName();

        return match(true){
            $name === BlockIds::REDSTONE_WIRE, $name === BlockIds::REDSTONE_BLOCK, $name === BlockIds::REDSTONE_TORCH, $name === BlockIds::UNLIT_REDSTONE_TORCH,
                $name === BlockIds::LEVER, $name === BlockIds::DAYLIGHT_DETECTOR, $name === BlockIds::DAYLIGHT_DETECTOR_INVERTED, $name === BlockIds::TARGET,
                $name === BlockIds::TRAPPED_CHEST, preg_match(self::BUTTON, $name) === 1, preg_match(self::PRESSURE_PLATE, $name) === 1,
            str_ends_with($name, "_comparator") => true,
            str_ends_with($name, "_repeater") => Facing::axis(self::getFacing($block)) === Facing::axis($face),
            $name === BlockIds::OBSERVER => Facing::opposite(self::getObserverFacing($block)) === $face,
            default => false
        };
    }

    /**
     * The signal a comparator reads from a container, cake, composter... behind it, null when
     * it reads nothing there.
     */
    public function getContainerSignal(int $x, int $y, int $z) : ?int{
        $block = $this->getBlock($x, $y, $z);
        $name = $block->getName();

        if($name === BlockIds::CAKE){
            return 14 - 2 * (int) $block->getProperty(BlockProperties::BITE_COUNTER);
        }

        if($name === BlockIds::COMPOSTER){
            return (int) $block->getProperty(BlockProperties::COMPOSTER_FILL_LEVEL);
        }

        if($name === BlockIds::END_PORTAL_FRAME){
            return $block->getProperty(BlockProperties::END_PORTAL_EYE_BIT) === true ? 15 : 0;
        }

        $entity = $this->world->isChunkLoaded($x >> 4, $z >> 4) ? $this->world->getBlockEntity($x, $y, $z) : null;

        if($entity instanceof Jukebox || $entity instanceof Lectern || $entity instanceof Crafter){
            return $entity->getSignal();
        }

        if(!$entity instanceof ContainerBlockEntity){
            return null;
        }

        $inventories = [$entity->getInventory()];

        if($entity instanceof Chest && ($pair = $entity->getPair()) !== null && $this->world->isChunkLoaded($pair[0] >> 4, $pair[1] >> 4)){
            $other = $this->world->getBlockEntity($pair[0], $y, $pair[1]);

            if($other instanceof Chest){
                $inventories[] = $other->getInventory();
            }
        }

        $fullness = 0.0;
        $slots = 0;
        $any = false;

        foreach($inventories as $inventory){
            $slots += $inventory->getSize();

            foreach($inventory->getContents() as $item){
                $fullness += $item->getCount() / $item->getMaxStackSize();
                $any = true;
            }
        }

        return $any ? (int) floor(1 + $fullness / $slots * 14) : 0;
    }

    public function getComparatorOutput(int $x, int $y, int $z) : int{
        $entity = $this->world->getBlockEntity($x, $y, $z);

        return $entity instanceof Comparator ? $entity->getOutput() : 0;
    }

    /**
     * The direction a repeater or comparator sends power, also the way the player faced
     * when placing it.
     */
    public static function getFacing(BlockState $block) : int{
        return Facing::fromName((string) $block->getProperty(BlockProperties::MINECRAFT_CARDINAL_DIRECTION));
    }

    /**
     * The side an observer watches.
     */
    public static function getObserverFacing(BlockState $block) : int{
        return Facing::fromName((string) $block->getProperty(BlockProperties::MINECRAFT_FACING_DIRECTION));
    }

    /**
     * The direction from a torch, lever or button to the block it hangs on.
     */
    public static function getAttachment(BlockState $block) : int{
        if($block->hasProperty(BlockProperties::TORCH_FACING_DIRECTION)){
            $value = (string) $block->getProperty(BlockProperties::TORCH_FACING_DIRECTION);

            return $value === "top" || $value === "unknown" ? Facing::DOWN : Facing::fromName($value);
        }

        if($block->hasProperty(BlockProperties::LEVER_DIRECTION)){
            $value = (string) $block->getProperty(BlockProperties::LEVER_DIRECTION);

            return match(true){
                str_starts_with($value, "up") => Facing::DOWN,
                str_starts_with($value, "down") => Facing::UP,
                default => Facing::opposite(Facing::fromName($value))
            };
        }

        if($block->hasProperty(BlockProperties::FACING_DIRECTION)){
            return Facing::opposite((int) $block->getProperty(BlockProperties::FACING_DIRECTION));
        }

        return Facing::DOWN;
    }

    /**
     * A wire gives power down, and sideways where it points: the sides it connects to, both
     * ends of a straight line, or every side for a lone dot.
     */
    private function wireEmits(int $x, int $y, int $z, BlockState $wire, int $direction) : bool{
        if($direction === Facing::DOWN){
            return true;
        }

        if($direction === Facing::UP){
            return false;
        }

        $connections = $this->getWireConnections($x, $y, $z);
        $connected = [];

        foreach($connections as $face => $connection){
            if($connection !== "none"){
                $connected[] = $face;
            }
        }

        if($connected === [] || $connections[$direction] !== "none"){
            return true;
        }

        //a line that ends here keeps going straight
        return count($connected) === 1 && $connected[0] === Facing::opposite($direction);
    }

    private function getTrappedChestPower(int $x, int $y, int $z) : int{
        $entity = $this->world->getBlockEntity($x, $y, $z);

        return $entity instanceof Chest ? min(count($entity->getViewers()), 15) : 0;
    }
}