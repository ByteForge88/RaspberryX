<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\Server;

use raspberry\entity\object\PrimedTNT;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\world\World;

use function mt_rand;

class TNT{

    private function __construct(){
    }

    public static function ignite(World $world, int $x, int $y, int $z, int $fuse = PrimedTnt::DEFAULT_FUSE) : bool{
        if($world->getBlock($x, $y, $z)->getName() !== BlockIds::TNT){
            return false;
        }

        $world->setBlock($x, $y, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::AIR));
        new PrimedTnt($world, new Vector3($x + 0.5, $y, $z + 0.5), $fuse);
        $world->broadcastPacket($x, $z, LevelSoundEventPacket::create(LevelSoundEvent::FUSE, $x + 0.5, $y + 0.5, $z + 0.5));

        return true;
    }

    public static function chainFuse() : int{
        return mt_rand(PrimedTnt::DEFAULT_FUSE / 8, PrimedTnt::DEFAULT_FUSE / 4 + PrimedTnt::DEFAULT_FUSE / 8 - 1);
    }
}