<?php

declare(strict_types=1);

namespace raspberry\entity\projectile;

use raspberry\entity\EntityIds;
use raspberry\entity\Living;
use raspberry\entity\damage\DamageSource;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\SetActorMotionPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use function mt_getrandmax;
use function mt_rand;

class ThrownTrident extends Projectile{

    public const float DAMAGE = 8.0;

    private const int GROUND_LIFE = 1200;

    private bool $dealtDamage = false;

    /** @var array{int, int, int}|null */
    private ?array $stuckIn = null;

    private int $groundTicks = 0;

    public function getNetworkTypeId() : string{
        return EntityIds::THROWN_TRIDENT;
    }

    public function getWidth() : float{
        return 0.25;
    }

    public function getHeight() : float{
        return 0.35;
    }

    protected function getGravity() : float{
        return 0.1;
    }

    protected function getDrag() : float{
        return 0.99;
    }

    protected function hitsEntities() : bool{
        return !$this->dealtDamage;
    }

    public function tick(int $currentTick) : void{
        if($this->closed || $this->stuckIn === null){
            parent::tick($currentTick);
            return;
        }

        if(++$this->groundTicks >= self::GROUND_LIFE){
            $this->close();
            return;
        }

        [$x, $y, $z] = $this->stuckIn;

        if(!$this->isSolidAt($x, $y, $z)){
            $this->stuckIn = null;
            $this->motion = new Vector3((mt_rand() / mt_getrandmax()) * 0.2, (mt_rand() / mt_getrandmax()) * 0.2, (mt_rand() / mt_getrandmax()) * 0.2);
        }
    }

    protected function onHitEntity(Living $target, Vector3 $point) : void{
        $this->dealtDamage = true;

        if($target->damage(new DamageSource(DamageSource::CAUSE_PROJECTILE, self::DAMAGE, $this->getOwner() ?? $this)) && $target->wasLastHitFresh()){
            $target->knockBack($this->motion->x, $this->motion->z);
        }

        $this->setMotion(new Vector3($this->motion->x * -0.01, $this->motion->y * -0.1, $this->motion->z * -0.01));
        $this->playSound(LevelSoundEvent::ITEM_TRIDENT_HIT);
    }

    protected function onHitBlock(int $x, int $y, int $z, Vector3 $point) : void{
        $length = $this->motion->length();
        $back = $length > 0.0 ? 0.05 / $length : 0.0;

        $this->stuckIn = [$x, $y, $z];
        $this->move($point->add(-$this->motion->x * $back, -$this->motion->y * $back, -$this->motion->z * $back), $this->yaw, $this->pitch);
        $this->motion = new Vector3(0.0, 0.0, 0.0);
        $this->playSound(LevelSoundEvent::ITEM_TRIDENT_HIT_GROUND);

        $packet = SetActorMotionPacket::create($this->id, 0.0, 0.0, 0.0, 0);

        foreach($this->viewers as $viewer){
            $viewer->getNetworkSession()->sendDataPacket($packet);
        }
    }

    private function playSound(string $sound) : void{
        $this->world->broadcastPacket($this->position->getFloorX(), $this->position->getFloorZ(), LevelSoundEventPacket::create($sound, $this->position->x, $this->position->y, $this->position->z));
    }
}