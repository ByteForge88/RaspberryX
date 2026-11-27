<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\Server;

use raspberry\math\Facing;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\world\World;

use JsonException;

class EndPortals{

    private function __construct(){
    }

    /**
     * @throws JsonException
     */
    public static function insertEye(World $world, int $x, int $y, int $z) : bool{
        $frame = $world->getBlock($x, $y, $z);

        if($frame->getName() !== BlockIds::END_PORTAL_FRAME || $frame->getProperty(BlockProperties::END_PORTAL_EYE_BIT) === true){
            return false;
        }

        $world->setBlock($x, $y, $z, $frame->withProperty(BlockProperties::END_PORTAL_EYE_BIT, true));
        $world->broadcastPacket($x, $z, LevelSoundEventPacket::create(LevelSoundEvent::BLOCK_END_PORTAL_FRAME_FILL, $x + 0.5, $y + 0.5, $z + 0.5));

        $facing = Facing::fromName((string) $frame->getProperty(BlockProperties::MINECRAFT_CARDINAL_DIRECTION));
        $center = self::findCenter($world, $x, $y, $z, $facing);

        if($center !== null){
            self::open($world, $center[0], $y, $center[1]);
        }

        return true;
    }

    /**
     * The clicked frame can be any of the three along its side.
     *
     * @return array{int, int}|null
     */
    private static function findCenter(World $world, int $x, int $y, int $z, int $facing) : ?array{
        [$dx, , $dz] = Facing::offset($facing);
        [$sx, , $sz] = Facing::offset(Facing::rotateRight($facing));

        foreach([0, -1, 1] as $offset){
            $cx = $x + $dx * 2 + $sx * $offset;
            $cz = $z + $dz * 2 + $sz * $offset;

            foreach(Facing::HORIZONTAL as $side){
                [$ox, , $oz] = Facing::offset($side);
                $inward = Facing::name(Facing::opposite($side));

                for($along = -1; $along <= 1; ++$along){
                    $frame = $world->getBlock($cx + $ox * 2 + $oz * $along, $y, $cz + $oz * 2 + $ox * $along);

                    if($frame->getName() !== BlockIds::END_PORTAL_FRAME || $frame->getProperty(BlockProperties::END_PORTAL_EYE_BIT) !== true || $frame->getProperty(BlockProperties::MINECRAFT_CARDINAL_DIRECTION) !== $inward){
                        continue 3;
                    }
                }
            }

            return [$cx, $cz];
        }

        return null;
    }

    /**
     * @throws JsonException
     */
    private static function open(World $world, int $x, int $y, int $z) : void{
        $server = Server::getInstance();
        $portal = $server->getBlockStateRegistry()->getDefaultState(BlockIds::END_PORTAL);

        for($dx = -1; $dx <= 1; ++$dx){
            for($dz = -1; $dz <= 1; ++$dz){
                $world->setBlock($x + $dx, $y, $z + $dz, $portal);
            }
        }

        $sound = LevelSoundEventPacket::create(LevelSoundEvent::BLOCK_END_PORTAL_SPAWN, $x + 0.5, $y + 0.5, $z + 0.5);
        $sound->disableRelativeVolume = true;

        foreach($server->getOnlinePlayers() as $player){
            if($player->isSpawned()){
                $player->getNetworkSession()->sendDataPacket($sound);
            }
        }
    }
}