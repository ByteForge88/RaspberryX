<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\math\Vector3;

use raspberry\world\Chunk;
use raspberry\world\World;

use JsonException;

use function floor;
use function preg_match;

class BigDripleaf{

    public const string NONE = "none";
    public const string UNSTABLE = "unstable";
    public const string PARTIAL_TILT = "partial_tilt";
    public const string FULL_TILT = "full_tilt";

    /** how long each tilt lasts before the next one */
    private const array DELAYS = [self::UNSTABLE => 10, self::PARTIAL_TILT => 10, self::FULL_TILT => 100];

    /** standing above this much of the block counts as standing on the leaf */
    public const float LEAF_TOP = 0.6875;

    private const string PLACEABLE = '/^minecraft:(dirt|grass_block|podzol|coarse_dirt|mycelium|dirt_with_roots|moss_block|mud|muddy_mangrove_roots|clay|farmland)$/D';

    private function __construct(){
    }

    public static function is(BlockState $block) : bool{
        return $block->getName() === BlockIds::BIG_DRIPLEAF;
    }

    public static function isHead(BlockState $block) : bool{
        return self::is($block) && $block->getProperty(BlockProperties::BIG_DRIPLEAF_HEAD) === true;
    }

    /**
     * A leaf placed on top of another dripleaf faces the same way.
     */
    public static function getPlacedState(World $world, int $x, int $y, int $z, BlockState $state) : BlockState{
        $state = $state->withProperty(BlockProperties::BIG_DRIPLEAF_HEAD, true)->withProperty(BlockProperties::BIG_DRIPLEAF_TILT, self::NONE);
        $below = $y > Chunk::MIN_Y ? $world->getBlock($x, $y - 1, $z) : null;

        if($below !== null && self::is($below)){
            $state = $state->withProperty(BlockProperties::MINECRAFT_CARDINAL_DIRECTION, $below->getProperty(BlockProperties::MINECRAFT_CARDINAL_DIRECTION));
        }

        return $state;
    }

    /**
     * The leaf stands on dirt, clay, moss or more dripleaf; the stem also needs dripleaf above.
     */
    public static function stays(World $world, int $x, int $y, int $z, BlockState $block) : bool{
        if($y <= Chunk::MIN_Y){
            return false;
        }

        $below = $world->getBlock($x, $y - 1, $z);

        if(!self::is($below) && preg_match(self::PLACEABLE, $below->getName()) !== 1){
            return false;
        }

        return self::isHead($block) || ($y < Chunk::MAX_Y && self::is($world->getBlock($x, $y + 1, $z)));
    }

    /**
     * Something next to the dripleaf at x y z changed.
     *
     * @throws JsonException
     */
    public static function onNeighborChanged(World $world, int $x, int $y, int $z, BlockState $block) : void{
        if(!self::stays($world, $x, $y, $z, $block)){
            BlockSupport::popOff($world, $x, $y, $z, $block);
            return;
        }

        //a leaf with another leaf put on top becomes stem
        if(self::isHead($block) && $y < Chunk::MAX_Y && self::is($world->getBlock($x, $y + 1, $z))){
            $world->setBlock($x, $y, $z, $block->withProperty(BlockProperties::BIG_DRIPLEAF_HEAD, false)->withProperty(BlockProperties::BIG_DRIPLEAF_TILT, self::NONE));
            return;
        }

        if(self::isHead($block) && $block->getProperty(BlockProperties::BIG_DRIPLEAF_TILT) !== self::NONE && self::isPowered($world, $x, $y, $z)){
            self::setTilt($world, $x, $y, $z, $block, self::NONE);
        }
    }

    /**
     * A player or a mob on the ground at $position, a leaf under it starts tipping over.
     *
     * @throws JsonException
     */
    public static function onStandingAt(World $world, Vector3 $position) : void{
        $x = $position->getFloorX();
        $y = (int) floor($position->y - 0.2);
        $z = $position->getFloorZ();

        if($y < Chunk::MIN_Y || !$world->isChunkLoaded($x >> 4, $z >> 4)){
            return;
        }

        $block = $world->getBlock($x, $y, $z);

        if(self::isHead($block) && $position->y > $y + self::LEAF_TOP){
            self::onSteppedOn($world, $x, $y, $z, $block);
        }
    }

    /**
     * A player or a mob stands on the leaf at x y z, it starts tipping over.
     *
     * @throws JsonException
     */
    public static function onSteppedOn(World $world, int $x, int $y, int $z, BlockState $block) : void{
        if(!self::isHead($block) || $block->getProperty(BlockProperties::BIG_DRIPLEAF_TILT) !== self::NONE || self::isPowered($world, $x, $y, $z)){
            return;
        }

        self::setTilt($world, $x, $y, $z, $block, self::UNSTABLE);
    }

    /**
     * @throws JsonException
     */
    public static function tick(World $world, int $x, int $y, int $z, BlockState $block) : void{
        if(!self::isHead($block)){
            return;
        }

        $tilt = $block->getProperty(BlockProperties::BIG_DRIPLEAF_TILT);

        if(self::isPowered($world, $x, $y, $z)){
            if($tilt !== self::NONE){
                self::setTilt($world, $x, $y, $z, $block, self::NONE);
            }

            return;
        }

        $next = match($tilt){
            self::UNSTABLE => self::PARTIAL_TILT,
            self::PARTIAL_TILT => self::FULL_TILT,
            self::FULL_TILT => self::NONE,
            default => null
        };

        if($next !== null){
            self::setTilt($world, $x, $y, $z, $block, $next);
        }
    }

    /**
     * Bone meal grows the plant one block taller when there is room above its leaf.
     *
     * @throws JsonException
     */
    public static function grow(World $world, int $x, int $y, int $z, BlockState $block) : bool{
        while(!self::isHead($block)){
            if(++$y > Chunk::MAX_Y){
                return false;
            }

            $block = $world->getBlock($x, $y, $z);

            if(!self::is($block)){
                return false;
            }
        }

        if($y >= Chunk::MAX_Y){
            return false;
        }

        $above = $world->getBlock($x, $y + 1, $z);

        if($above->getName() !== BlockIds::AIR && !($above->getName() === BlockIds::WATER && (int) $above->getProperty(BlockProperties::LIQUID_DEPTH) === 0)){
            return false;
        }

        $leaf = $block->withProperty(BlockProperties::BIG_DRIPLEAF_TILT, self::NONE);
        $world->setBlock($x, $y + 1, $z, $leaf);
        $world->setBlock($x, $y, $z, $leaf->withProperty(BlockProperties::BIG_DRIPLEAF_HEAD, false));

        return true;
    }

    /**
     * @throws JsonException
     */
    private static function setTilt(World $world, int $x, int $y, int $z, BlockState $block, string $tilt) : void{
        $old = $block->getProperty(BlockProperties::BIG_DRIPLEAF_TILT);
        $world->setBlock($x, $y, $z, $block->withProperty(BlockProperties::BIG_DRIPLEAF_TILT, $tilt));

        if($tilt === self::PARTIAL_TILT || $tilt === self::FULL_TILT){
            self::sound($world, $x, $y, $z, LevelSoundEvent::TILT_DOWN_BIG_DRIPLEAF);
        }elseif($tilt === self::NONE && $old !== self::NONE){
            self::sound($world, $x, $y, $z, LevelSoundEvent::TILT_UP_BIG_DRIPLEAF);
        }

        if(isset(self::DELAYS[$tilt])){
            $world->getBlockTicker()->schedule($x, $y, $z, self::DELAYS[$tilt]);
        }
    }

    private static function isPowered(World $world, int $x, int $y, int $z) : bool{
        return $world->getRedstoneUpdater()->getSignal()->getReceivedPower($x, $y, $z) > 0;
    }

    private static function sound(World $world, int $x, int $y, int $z, string $sound) : void{
        $world->broadcastPacket($x, $z, LevelSoundEventPacket::create($sound, $x + 0.5, $y + 0.5, $z + 0.5, -1));
    }
}