<?php

declare(strict_types=1);

namespace raspberry\entity\projectile;

use raspberry\entity\EntityIds;
use raspberry\entity\Living;
use raspberry\entity\damage\DamageSource;
use raspberry\entity\monster\Endermite;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\types\LevelEvent;

use raspberry\player\Player;

use raspberry\world\GameRules;

use function abs;
use function min;
use function mt_rand;

class EnderPearl extends Projectile{

    public const float VELOCITY = 1.5;

    private const float GRAVITY = 0.025;

    private const float TELEPORT_DAMAGE = 5.0;

    private const int ENDERMITE_CHANCE = 20;

    private const float SURFACE_GAP = 0.001;

    public function getNetworkTypeId() : string{
        return EntityIds::ENDER_PEARL;
    }

    protected function getGravity() : float{
        return self::GRAVITY;
    }

    protected function getDrag() : float{
        return 1.0;
    }

    protected function onHitEntity(Living $target, Vector3 $point) : void{
        $this->land($point);
    }

    protected function onHitBlock(int $x, int $y, int $z, Vector3 $point) : void{
        $this->land(self::getEntryPoint($x, $y, $z, $point, $this->motion));
    }

    /**
     * Where a path that moves by $motion each tick came into the block at x y z, a hair outside it.
     */
    public static function getEntryPoint(int $x, int $y, int $z, Vector3 $point, Vector3 $motion) : Vector3{
        $back = null;

        foreach([[$point->x, $motion->x, $x], [$point->y, $motion->y, $y], [$point->z, $motion->z, $z]] as [$at, $speed, $block]){
            if(abs($speed) < 1.0e-7){
                continue;
            }

            $out = $speed > 0 ? ($at - $block) / $speed : ($at - $block - 1) / $speed;
            $back = $back === null ? $out : min($back, $out);
        }

        if($back === null){
            return $point;
        }

        $back += self::SURFACE_GAP / $motion->length();
        return new Vector3($point->x - $motion->x * $back, $point->y - $motion->y * $back, $point->z - $motion->z * $back);
    }

    private function land(Vector3 $at) : void{
        $owner = $this->getOwner();

        if($owner instanceof Player && $owner->isSpawned() && !$owner->isDead() && $owner->getWorld() === $this->world){
            $from = $owner->getPosition();
            $this->world->broadcastPacket($from->getFloorX(), $from->getFloorZ(), LevelEventPacket::create(LevelEvent::PARTICLES_TELEPORT, $from->x, $from->y, $from->z));

            $owner->teleport($at);

            $to = $owner->getPosition();
            $this->world->broadcastPacket($to->getFloorX(), $to->getFloorZ(), LevelEventPacket::create(LevelEvent::SOUND_TELEPORT_ENDER_PEARL, $to->x, $to->y, $to->z));
            $owner->damage(new DamageSource(DamageSource::CAUSE_FALL, self::TELEPORT_DAMAGE));
        }

        if($this->world->getGameRules()->getBool(GameRules::DO_MOB_SPAWNING) && mt_rand(0, self::ENDERMITE_CHANCE - 1) === 0){
            new Endermite($this->world, $at);
        }

        $this->close();
    }
}