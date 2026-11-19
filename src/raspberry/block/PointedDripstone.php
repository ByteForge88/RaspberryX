<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\Server;

use raspberry\entity\object\FallingBlock;

use raspberry\math\Facing;
use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\world\Chunk;
use raspberry\world\World;

use JsonException;

use function max;
use function mt_getrandmax;
use function mt_rand;

class PointedDripstone{

    public const string TIP = "tip";
    public const string FRUSTUM = "frustum";
    public const string MIDDLE = "middle";
    public const string BASE = "base";
    public const string MERGE = "merge";

    /** stalactites fall two ticks after losing their hold, stalagmites break after one */
    public const int FALL_DELAY = 2;
    public const int BREAK_DELAY = 1;

    public const float FALL_MAX_DAMAGE = 40.0;

    private const float WATER_DRIP_CHANCE = 0.17578125;
    private const float LAVA_DRIP_CHANCE = 0.05859375;
    private const float GROW_CHANCE = 0.011;

    /** how far up and down a stalactite is followed */
    private const int REACH = 11;
    private const int GROW_REACH = 7;
    private const int STALAGMITE_REACH = 10;

    private function __construct(){
    }

    public static function is(BlockState $block) : bool{
        return $block->getName() === BlockIds::POINTED_DRIPSTONE;
    }

    public static function isHanging(BlockState $block) : bool{
        return self::is($block) && $block->getProperty(BlockProperties::HANGING) === true;
    }

    private static function points(BlockState $block, bool $down) : bool{
        return self::is($block) && self::isHanging($block) === $down;
    }

    private static function isTip(BlockState $block, bool $merged) : bool{
        $thickness = $block->getProperty(BlockProperties::DRIPSTONE_THICKNESS);

        return self::is($block) && ($thickness === self::TIP || ($merged && $thickness === self::MERGE));
    }

    /**
     * The dripstone a player places at x y z: hanging when clicked from below, unless there is
     * nothing above to hang from. Sneaking keeps two tips from merging.
     *
     * @throws JsonException
     */
    public static function getPlacedState(World $world, int $x, int $y, int $z, int $face, bool $merge) : ?BlockState{
        $down = $face === Facing::DOWN;

        if(!self::canHold($world, $x, $y, $z, $down)){
            $down = !$down;

            if(!self::canHold($world, $x, $y, $z, $down)){
                return null;
            }
        }

        return self::state($down, self::getThickness($world, $x, $y, $z, $down, $merge));
    }

    /**
     * Whether a dripstone pointing up or down at x y z has something to stand on or hang from.
     */
    public static function canHold(World $world, int $x, int $y, int $z, bool $down) : bool{
        $baseY = $down ? $y + 1 : $y - 1;

        if($baseY < Chunk::MIN_Y || $baseY > Chunk::MAX_Y){
            return false;
        }

        $base = $world->getBlock($x, $baseY, $z);

        return BlockCollision::isFullCube($base) || self::points($base, $down);
    }

    public static function stays(World $world, int $x, int $y, int $z, BlockState $block) : bool{
        return self::canHold($world, $x, $y, $z, self::isHanging($block));
    }

    /**
     * Tip, frustum, middle or base, from the dripstone around it.
     */
    public static function getThickness(World $world, int $x, int $y, int $z, bool $down, bool $merge) : string{
        $ahead = self::at($world, $x, $down ? $y - 1 : $y + 1, $z);

        if(self::points($ahead, !$down)){
            return !$merge && $ahead->getProperty(BlockProperties::DRIPSTONE_THICKNESS) !== self::MERGE ? self::TIP : self::MERGE;
        }

        if(!self::points($ahead, $down)){
            return self::TIP;
        }

        $thickness = $ahead->getProperty(BlockProperties::DRIPSTONE_THICKNESS);

        if($thickness === self::TIP || $thickness === self::MERGE){
            return self::FRUSTUM;
        }

        return self::points(self::at($world, $x, $down ? $y + 1 : $y - 1, $z), $down) ? self::MIDDLE : self::BASE;
    }

    /**
     * A block above or below dripstone changed: it falls or breaks when it lost its hold, or
     * takes the shape that fits.
     *
     * @throws JsonException
     */
    public static function onBlockChanged(World $world, int $x, int $y, int $z) : void{
        foreach([$y + 1, $y - 1] as $ny){
            if($ny < Chunk::MIN_Y || $ny > Chunk::MAX_Y || !$world->isChunkLoaded($x >> 4, $z >> 4)){
                continue;
            }

            $block = $world->getBlock($x, $ny, $z);

            if(!self::is($block)){
                continue;
            }

            $down = self::isHanging($block);

            if(!self::stays($world, $x, $ny, $z, $block)){
                $world->getBlockTicker()->schedule($x, $ny, $z, $down ? self::FALL_DELAY : self::BREAK_DELAY);
                continue;
            }

            $thickness = self::getThickness($world, $x, $ny, $z, $down, $block->getProperty(BlockProperties::DRIPSTONE_THICKNESS) === self::MERGE);

            if($thickness !== $block->getProperty(BlockProperties::DRIPSTONE_THICKNESS)){
                $world->setBlock($x, $ny, $z, $block->withProperty(BlockProperties::DRIPSTONE_THICKNESS, $thickness));
            }
        }
    }

    /**
     * @throws JsonException
     */
    public static function tick(World $world, int $x, int $y, int $z, BlockState $block) : void{
        if(self::stays($world, $x, $y, $z, $block)){
            return;
        }

        if(!self::isHanging($block)){
            BlockSupport::popOff($world, $x, $y, $z, $block);
            return;
        }

        //the whole stalactite comes down, the tip hurts what it lands on
        $air = Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::AIR);

        for($by = $y; $by >= Chunk::MIN_Y && self::points($block, true); --$by){
            $world->setBlock($x, $by, $z, $air);
            $falling = new FallingBlock($world, new Vector3($x + 0.5, $by, $z + 0.5), $block);

            //a longer stalactite hurts more for each block it falls
            if(self::isTip($block, true)){
                $falling->setHurtsEntities((float) max(1 + $y - $by, 6), self::FALL_MAX_DAMAGE);
                break;
            }

            $block = $by > Chunk::MIN_Y ? $world->getBlock($x, $by - 1, $z) : $air;
        }
    }

    /**
     * Drips onto cauldrons and grows, only the top of a stalactite does anything.
     *
     * @throws JsonException
     */
    public static function onRandomTick(World $world, int $x, int $y, int $z, BlockState $block) : void{
        if(!self::isHanging($block) || ($y < Chunk::MAX_Y && self::is($world->getBlock($x, $y + 1, $z)))){
            return;
        }

        self::drip($world, $x, $y, $z, $block, mt_rand() / mt_getrandmax());

        if(mt_rand() / mt_getrandmax() < self::GROW_CHANCE){
            self::grow($world, $x, $y, $z, $block);
        }
    }

    /**
     * Water above the ceiling drips into a cauldron below or turns mud into clay, lava only
     * fills cauldrons.
     *
     * @throws JsonException
     */
    private static function drip(World $world, int $x, int $y, int $z, BlockState $block, float $chance) : void{
        if($chance > self::WATER_DRIP_CHANCE){
            return;
        }

        $rootY = $y + 1;

        if($rootY > Chunk::MAX_Y - 1){
            return;
        }

        $source = $world->getBlock($x, $rootY + 1, $z);
        $liquid = match(true){
            $source->getName() === BlockIds::MUD, self::isSource($source, BlockIds::WATER) => Cauldrons::LIQUID_WATER,
            self::isSource($source, BlockIds::LAVA) => Cauldrons::LIQUID_LAVA,
            default => null
        };

        if($liquid === null || $chance >= ($liquid === Cauldrons::LIQUID_WATER ? self::WATER_DRIP_CHANCE : self::LAVA_DRIP_CHANCE)){
            return;
        }

        $tipY = self::findTip($world, $x, $y, $z, self::REACH);

        if($tipY === null){
            return;
        }

        if($source->getName() === BlockIds::MUD){
            $world->setBlock($x, $rootY + 1, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::CLAY));
            self::sound($world, $x, $tipY, $z, LevelSoundEvent::DRIP_WATER_POINTED_DRIPSTONE);
            return;
        }

        for($cy = $tipY - 1; $cy >= max(Chunk::MIN_Y, $tipY - self::REACH); --$cy){
            $below = $world->getBlock($x, $cy, $z);

            if($below->getName() === BlockIds::CAULDRON){
                if(Cauldrons::catchDrip($world, $x, $cy, $z, $below, $liquid)){
                    self::sound($world, $x, $cy, $z, $liquid === Cauldrons::LIQUID_WATER ? LevelSoundEvent::CAULDRON_DRIP_WATER_POINTED_DRIPSTONE : LevelSoundEvent::CAULDRON_DRIP_LAVA_POINTED_DRIPSTONE);
                }

                return;
            }

            if(BlockCollision::isSolid($below) || self::isSource($below, BlockIds::WATER) || self::isSource($below, BlockIds::LAVA)){
                return;
            }
        }
    }

    /**
     * Under a dripstone block with still water on top the stalactite gets longer, or the
     * stalagmite under it grows up to meet it.
     *
     * @throws JsonException
     */
    private static function grow(World $world, int $x, int $y, int $z, BlockState $block) : void{
        if($y + 2 > Chunk::MAX_Y || $world->getBlock($x, $y + 1, $z)->getName() !== BlockIds::DRIPSTONE_BLOCK || !self::isSource($world->getBlock($x, $y + 2, $z), BlockIds::WATER)){
            return;
        }

        $tipY = self::findTip($world, $x, $y, $z, self::GROW_REACH);

        if($tipY === null || $tipY <= Chunk::MIN_Y){
            return;
        }

        $tip = $world->getBlock($x, $tipY, $z);

        if($tip->getProperty(BlockProperties::DRIPSTONE_THICKNESS) !== self::TIP || !self::canTipGrow($world, $x, $tipY, $z, true)){
            return;
        }

        if(mt_rand(0, 1) === 0){
            self::extend($world, $x, $tipY - 1, $z, true);
            return;
        }

        for($by = $tipY - 1; $by > max(Chunk::MIN_Y, $tipY - 1 - self::STALAGMITE_REACH); --$by){
            $below = $world->getBlock($x, $by, $z);

            if(self::isLiquid($below)){
                return;
            }

            if(self::points($below, false) && self::isTip($below, false) && self::canTipGrow($world, $x, $by, $z, false)){
                self::extend($world, $x, $by + 1, $z, false);
                return;
            }

            if(self::canHold($world, $x, $by, $z, false) && !self::isLiquid($world->getBlock($x, $by - 1, $z))){
                self::extend($world, $x, $by, $z, false);
                return;
            }

            if(BlockCollision::isSolid($below)){
                return;
            }
        }
    }

    private static function canTipGrow(World $world, int $x, int $y, int $z, bool $down) : bool{
        $ahead = self::at($world, $x, $down ? $y - 1 : $y + 1, $z);

        return $ahead->getName() === BlockIds::AIR || (self::points($ahead, !$down) && self::isTip($ahead, false));
    }

    /**
     * A new tip at x y z pointing up or down, or the two tips meeting there merge.
     *
     * @throws JsonException
     */
    private static function extend(World $world, int $x, int $y, int $z, bool $down) : void{
        $here = $world->getBlock($x, $y, $z);

        if(self::points($here, !$down) && self::isTip($here, false)){
            $world->setBlock($x, $y, $z, $here->withProperty(BlockProperties::DRIPSTONE_THICKNESS, self::MERGE));
            $otherY = $down ? $y + 1 : $y - 1;
            $other = $world->getBlock($x, $otherY, $z);

            if(self::is($other)){
                $world->setBlock($x, $otherY, $z, $other->withProperty(BlockProperties::DRIPSTONE_THICKNESS, self::MERGE));
            }

            return;
        }

        if($here->getName() === BlockIds::AIR || self::isSource($here, BlockIds::WATER)){
            $world->setBlock($x, $y, $z, self::state($down, self::TIP));
        }
    }

    /**
     * The y of the tip of the stalactite hanging at x y z.
     */
    private static function findTip(World $world, int $x, int $y, int $z, int $reach) : ?int{
        for($ty = $y; $ty > max(Chunk::MIN_Y - 1, $y - $reach); --$ty){
            $block = $world->getBlock($x, $ty, $z);

            if(!self::points($block, true)){
                return null;
            }

            if(self::isTip($block, false)){
                return $ty;
            }
        }

        return null;
    }

    private static function isSource(BlockState $block, string $liquid) : bool{
        return $block->getName() === $liquid && (int) $block->getProperty(BlockProperties::LIQUID_DEPTH) === 0;
    }

    private static function isLiquid(BlockState $block) : bool{
        return $block->hasProperty(BlockProperties::LIQUID_DEPTH);
    }

    private static function at(World $world, int $x, int $y, int $z) : BlockState{
        if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y || !$world->isChunkLoaded($x >> 4, $z >> 4)){
            return Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::AIR);
        }

        return $world->getBlock($x, $y, $z);
    }

    private static function state(bool $down, string $thickness) : BlockState{
        return Server::getInstance()->getBlockStateRegistry()->get(BlockIds::POINTED_DRIPSTONE, [
            BlockProperties::HANGING => $down,
            BlockProperties::DRIPSTONE_THICKNESS => $thickness
        ]);
    }

    private static function sound(World $world, int $x, int $y, int $z, string $sound) : void{
        $world->broadcastPacket($x, $z, LevelSoundEventPacket::create($sound, $x + 0.5, $y + 0.5, $z + 0.5, -1));
    }
}