<?php

declare(strict_types=1);

namespace raspberry\entity\monster;

use raspberry\Server;

use raspberry\entity\Human;
use raspberry\entity\EntityIds;
use raspberry\entity\ai\Behavior;
use raspberry\entity\projectile\Arrow;
use raspberry\entity\projectile\Projectile;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\EntityMetadata;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\world\Difficulty;

use function max;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function sqrt;

class Skeleton extends Monster{

    public const float FOLLOW_RANGE = 16.0;

    public const float SHOOT_RADIUS = 15.0;

    private const float BOW_DROP_CHANCE = 0.085;

    private bool $drawing = false;

    public function getNetworkTypeId() : string{
        return EntityIds::SKELETON;
    }

    protected function getBaseWidth() : float{
        return 0.6;
    }

    protected function getBaseHeight() : float{
        return 1.99;
    }

    protected function getDefaultMaxHealth() : float{
        return 20.0;
    }

    protected function getDefaultMovementSpeed() : float{
        return 0.25;
    }

    protected function getAttackDamage() : float{
        return 2.0;
    }

    public function isUndead() : bool{
        return true;
    }

    protected function burnsInDaylight() : bool{
        return true;
    }

    public function getHeldItem() : ?Item{
        return new Item(ItemIds::BOW);
    }

    public function getBehaviors() : array{
        $interval = Server::getInstance()->getDifficulty() === Difficulty::HARD ? 20 : 40;

        return [
            Behavior::hurtByTarget(1, self::FOLLOW_RANGE),
            Behavior::nearestAttackableTarget(2, self::FOLLOW_RANGE),
            Behavior::rangedBowAttack(4, 1.0, $interval, self::SHOOT_RADIUS),
            Behavior::randomStroll(5, 1.0),
            Behavior::lookAtPlayer(6, 8.0),
            Behavior::randomLookAround(6)
        ];
    }

    public function createMetadata() : EntityMetadata{
        $metadata = parent::createMetadata();
        $metadata->setFlag(EntityMetadata::FLAG_USING_ITEM, $this->drawing);
        return $metadata;
    }

    public function onEngineEvent(int $kind, float $value, int $x, int $y, int $z, int $other) : void{
        if($kind === self::EVENT_USE_ITEM){
            $this->drawing = $value > 0;
            $this->sendMetadata();
            return;
        }

        if($kind === self::EVENT_SHOOT){
            $target = $this->world->getEntity($other);

            if($target !== null && !$target->isClosed()){
                $this->shootAt($target->getPosition(), $target instanceof Human ? Human::HEIGHT : $target->getHeight(), $value);
            }

            return;
        }

        parent::onEngineEvent($kind, $value, $x, $y, $z, $other);
    }

    private function shootAt(Vector3 $target, float $height, float $power) : void{
        $difficulty = Server::getInstance()->getDifficulty()->value;
        $from = new Vector3($this->position->x, $this->position->y + $this->getEyeHeight() - 0.1, $this->position->z);

        $dx = $target->x - $from->x;
        $dy = $target->y + $height / 3 - $from->y;
        $dz = $target->z - $from->z;
        $flat = sqrt($dx * $dx + $dz * $dz);

        $arrow = new Arrow($this->world, $from, Projectile::aim($dx, $dy + $flat * 0.2, $dz, 1.6, 14 - $difficulty * 4), $this);
        $arrow->setBaseDamage($power * 2.0 + $difficulty * 0.11 + (mt_rand() / mt_getrandmax() - mt_rand() / mt_getrandmax()) * 0.57425);

        $packet = LevelSoundEventPacket::create(LevelSoundEvent::BOW, $from->x, $from->y, $from->z);
        $packet->entityType = EntityIds::SKELETON;
        $this->world->broadcastPacket($from->getFloorX(), $from->getFloorZ(), $packet);
    }

    protected function getDrops(int $looting) : array{
        $drops = [];

        foreach([ItemIds::BONE, ItemIds::ARROW] as $id){
            $count = self::lootCount(0, 2, $looting);

            if($count > 0){
                $drops[] = new Item($id, $count);
            }
        }

        if($this->getKiller() !== null && mt_rand() / mt_getrandmax() < self::BOW_DROP_CHANCE + 0.01 * $looting){
            $bow = new Item(ItemIds::BOW);
            $max = $bow->getMaxDurability();
            $drops[] = $bow->setDamage(min($max - 1, $max - mt_rand(0, mt_rand(0, max($max - 3, 1) - 1))));
        }

        return $drops;
    }
}