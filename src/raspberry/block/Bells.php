<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\block\entity\Bell;

use raspberry\math\Facing;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\world\World;

class Bells{

    private const float TOP_HIT = 0.8124;

    private function __construct(){
    }

    public static function canHit(int $face, float $clickY) : bool{
        return Facing::isHorizontal($face) && $clickY <= self::TOP_HIT;
    }

    public static function ring(World $world, int $x, int $y, int $z, int $face) : void{
        $bell = $world->getBlockEntity($x, $y, $z);

        if(!$bell instanceof Bell){
            $bell = new Bell($x, $y, $z);
            $world->addBlockEntity($bell);
        }

        $bell->ring(Facing::isHorizontal($face) ? Facing::toLegacyDirection($face) : 0);
        $world->sendBlockEntity($bell);
        $world->broadcastPacket($x, $z, LevelSoundEventPacket::create(LevelSoundEvent::BLOCK_BELL_HIT, $x + 0.5, $y + 0.5, $z + 0.5));
    }

    public static function updatePower(World $world, int $x, int $y, int $z, BlockState $bell, bool $powered) : void{
        if(($bell->getProperty(BlockProperties::TOGGLE_BIT) === true) === $powered){
            return;
        }

        $world->setBlock($x, $y, $z, $bell->withProperty(BlockProperties::TOGGLE_BIT, $powered));

        if($powered){
            self::ring($world, $x, $y, $z, Facing::fromLegacyDirection((int) $bell->getProperty(BlockProperties::DIRECTION)));
        }
    }
}