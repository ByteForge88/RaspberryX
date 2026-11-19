<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\Server;

use raspberry\entity\object\FallingBlock;

use raspberry\math\Facing;
use raspberry\math\Vector3;

use raspberry\world\Chunk;
use raspberry\world\World;

use JsonException;

use function min;

class Scaffolding{

    public const int MAX_STABILITY = 7;

    public const int DELAY = 1;

    private const array SIDES = [[0, 1, 0], [0, -1, 0], [1, 0, 0], [-1, 0, 0], [0, 0, 1], [0, 0, -1]];

    private const array HORIZONTAL = [[1, 0], [-1, 0], [0, 1], [0, -1]];

    private const array BURNS = [
        BlockIds::LAVA => true,
        BlockIds::FLOWING_LAVA => true,
        BlockIds::FIRE => true,
        BlockIds::SOUL_FIRE => true
    ];

    private function __construct(){
    }

    public static function is(BlockState $block) : bool{
        return $block->getName() === BlockIds::SCAFFOLDING;
    }

    public static function getStability(World $world, int $x, int $y, int $z) : int{
        $stability = self::MAX_STABILITY;

        if($y > Chunk::MIN_Y){
            $below = $world->getBlock($x, $y - 1, $z);

            if(self::is($below)){
                $stability = (int) $below->getProperty(BlockProperties::STABILITY);
            }elseif(BlockCollision::isSolid($below)){
                return 0;
            }
        }

        foreach(self::HORIZONTAL as [$dx, $dz]){
            if($stability <= 1){
                break;
            }

            if(!$world->isChunkLoaded(($x + $dx) >> 4, ($z + $dz) >> 4)){
                continue;
            }

            $side = $world->getBlock($x + $dx, $y, $z + $dz);

            if(self::is($side)){
                $stability = min($stability, (int) $side->getProperty(BlockProperties::STABILITY) + 1);
            }
        }

        return $stability;
    }

    /**
     * @return array{int, int, int}|null
     */
    public static function getPlacement(World $world, int $x, int $y, int $z, int $face, bool $clickedReplaceable) : ?array{
        $clicked = $world->getBlock($x, $y, $z);

        if(!self::is($clicked) || $face !== Facing::DOWN){
            if(!$clickedReplaceable || self::is($clicked)){
                [$dx, $dy, $dz] = Facing::offset($face);
                [$x, $y, $z] = [$x + $dx, $y + $dy, $z + $dz];
            }

            if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y || !$world->isChunkLoaded($x >> 4, $z >> 4)){
                return null;
            }
        }

        while(self::is($world->getBlock($x, $y, $z))){
            if(++$y > Chunk::MAX_Y){
                return null;
            }
        }

        return [$x, $y, $z];
    }

    /**
     * The scaffolding to place at x y z, null when nothing would hold it there.
     */
    public static function getPlacedState(World $world, int $x, int $y, int $z) : ?BlockState{
        $stability = self::getStability($world, $x, $y, $z);

        if($stability >= self::MAX_STABILITY){
            return null;
        }

        return Server::getInstance()->getBlockStateRegistry()->get(BlockIds::SCAFFOLDING, [
            BlockProperties::STABILITY => $stability,
            BlockProperties::STABILITY_CHECK => false
        ]);
    }

    /**
     * A piece checks whether it still holds after something around it changed.
     *
     * @throws JsonException
     */
    public static function tick(World $world, int $x, int $y, int $z, BlockState $block) : void{
        foreach(self::SIDES as [$dx, $dy, $dz]){
            $ny = $y + $dy;

            if($ny >= Chunk::MIN_Y && $ny <= Chunk::MAX_Y && $world->isChunkLoaded(($x + $dx) >> 4, ($z + $dz) >> 4) && isset(self::BURNS[$world->getBlock($x + $dx, $ny, $z + $dz)->getName()])){
                BlockSupport::popOff($world, $x, $y, $z, $block);
                return;
            }
        }

        $old = (int) $block->getProperty(BlockProperties::STABILITY);
        $stability = self::getStability($world, $x, $y, $z);

        if($stability < self::MAX_STABILITY){
            if($stability !== $old || $block->getProperty(BlockProperties::STABILITY_CHECK) !== true){
                $world->setBlock($x, $y, $z, $block->withProperty(BlockProperties::STABILITY, $stability)->withProperty(BlockProperties::STABILITY_CHECK, true));
            }

            return;
        }

        if($old < self::MAX_STABILITY){
            BlockSupport::popOff($world, $x, $y, $z, $block);
            return;
        }

        if($y > Chunk::MIN_Y && FallingBlocks::canFallThrough($world->getBlock($x, $y - 1, $z))){
            $world->setBlock($x, $y, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::AIR));
            new FallingBlock($world, new Vector3($x + 0.5, $y, $z + 0.5), $block);
            return;
        }

        BlockSupport::popOff($world, $x, $y, $z, $block);
    }

    /**
     * Something changed at x y z: the scaffolding around it checks itself again.
     */
    public static function onBlockChanged(World $world, int $x, int $y, int $z, BlockState $old, BlockState $new) : void{
        if(self::is($old) && self::is($new)){
            if($old->getProperty(BlockProperties::STABILITY) === $new->getProperty(BlockProperties::STABILITY)){
                return;
            }
        }elseif(self::is($new)){
            $world->getBlockTicker()->schedule($x, $y, $z, self::DELAY);
        }

        if(self::is($old) || self::is($new)){
            foreach(self::SIDES as [$dx, $dy, $dz]){
                self::scheduleIfScaffolding($world, $x + $dx, $y + $dy, $z + $dz);
            }

            return;
        }

        if(BlockCollision::isSolid($old) !== BlockCollision::isSolid($new)){
            self::scheduleIfScaffolding($world, $x, $y + 1, $z);
        }

        if(isset(self::BURNS[$new->getName()]) && !isset(self::BURNS[$old->getName()])){
            foreach(self::SIDES as [$dx, $dy, $dz]){
                self::scheduleIfScaffolding($world, $x + $dx, $y + $dy, $z + $dz);
            }
        }
    }

    private static function scheduleIfScaffolding(World $world, int $x, int $y, int $z) : void{
        if($y >= Chunk::MIN_Y && $y <= Chunk::MAX_Y && $world->isChunkLoaded($x >> 4, $z >> 4) && self::is($world->getBlock($x, $y, $z))){
            $world->getBlockTicker()->schedule($x, $y, $z, self::DELAY);
        }
    }
}