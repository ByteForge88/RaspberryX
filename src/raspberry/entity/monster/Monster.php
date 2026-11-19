<?php

declare(strict_types=1);

namespace raspberry\entity\monster;

use raspberry\Server;

use raspberry\entity\Living;
use raspberry\entity\Mob;
use raspberry\entity\damage\DamageSource;
use raspberry\entity\effect\Effect;

use raspberry\nbt\tag\CompoundTag;

use raspberry\player\GameMode;

use raspberry\world\Difficulty;

use function floor;
use function max;
use function mt_getrandmax;
use function mt_rand;

abstract class Monster extends Mob{

    private const int DESPAWN_FAR = 128;

    private const int DESPAWN_NEAR = 32;

    private const int DESPAWN_IDLE = 600;
    private const int DESPAWN_CHANCE = 800;

    protected bool $persistent = true;

    protected int $idleTicks = 0;

    abstract protected function getAttackDamage() : float;

    public function getXpReward() : int{
        return 5;
    }

    protected function dropsLootAsBaby() : bool{
        return true;
    }

    public function isPersistent() : bool{
        return $this->persistent;
    }

    public function setPersistent(bool $persistent) : void{
        $this->persistent = $persistent;
    }

    protected function burnsInDaylight() : bool{
        return false;
    }

    public function tick(int $currentTick) : void{
        parent::tick($currentTick);

        if($this->closed || !$this->isAlive()){
            return;
        }

        if(Server::getInstance()->getDifficulty() === Difficulty::PEACEFUL){
            $this->close();
            return;
        }

        ++$this->idleTicks;

        if($this->checkDespawn()){
            return;
        }

        if($this->burnsInDaylight() && $this->isSunBurnTick()){
            $this->setOnFire(8);
        }
    }

    /**
     * True when the monster went away, far from every player.
     */
    private function checkDespawn() : bool{
        if($this->persistent){
            $this->idleTicks = 0;
            return false;
        }

        $nearest = null;

        foreach($this->world->getPlayers() as $player){
            if(!$player->isSpawned() || $player->getGameMode() === GameMode::SPECTATOR){
                continue;
            }

            $distance = $player->getPosition()->distanceSquared($this->position);

            if($nearest === null || $distance < $nearest){
                $nearest = $distance;
            }
        }

        if($nearest === null){
            return false;
        }

        if($nearest > self::DESPAWN_FAR ** 2 || ($nearest > self::DESPAWN_NEAR ** 2 && $this->idleTicks > self::DESPAWN_IDLE && mt_rand(0, self::DESPAWN_CHANCE - 1) === 0)){
            $this->close();
            return true;
        }

        if($nearest < self::DESPAWN_NEAR ** 2){
            $this->idleTicks = 0;
        }

        return false;
    }

    /**
     * In daylight, under the open sky and dry, a sunlit monster catches fire now and then.
     */
    private function isSunBurnTick() : bool{
        if(!$this->world->isDaytime() || $this->isInWater()){
            return false;
        }

        $x = $this->position->getFloorX();
        $y = (int) floor($this->position->y + $this->getEyeHeight());
        $z = $this->position->getFloorZ();

        if(!$this->world->isChunkLoaded($x >> 4, $z >> 4) || $this->world->isRainingAt($x, $y, $z)){
            return false;
        }

        $brightness = $this->getBrightness();

        return $brightness > 0.5 && mt_rand() / mt_getrandmax() * 30.0 < ($brightness - 0.4) * 2.0 && $this->world->canSeeSky($x, $y, $z);
    }

    /**
     * How bright it is at the monster's eyes, 0 to 1: 0.5 and up is light level 12 and up.
     */
    protected function getBrightness() : float{
        $x = $this->position->getFloorX();
        $y = (int) floor($this->position->y + $this->getEyeHeight());
        $z = $this->position->getFloorZ();

        if(!$this->world->isChunkLoaded($x >> 4, $z >> 4)){
            return 0.0;
        }

        $light = $this->world->getLight($x, $y, $z) / 15;

        return $light / (4.0 - 3.0 * $light);
    }

    protected function onDamaged(DamageSource $source) : void{
        parent::onDamaged($source);
        $this->idleTicks = 0;
    }

    protected function onAttack(Living $target) : void{
        $damage = $this->getAttackDamage();
        $damage += 3.0 * $this->effects->getLevel(Effect::STRENGTH);
        $damage = max(0.0, $damage - 4.0 * $this->effects->getLevel(Effect::WEAKNESS));

        if($damage <= 0.0 || !$target->damage(new DamageSource(DamageSource::CAUSE_ENTITY_ATTACK, $damage, $this))){
            return;
        }

        if($target->wasLastHitFresh()){
            $to = $target->getPosition();
            $target->knockBack($to->x - $this->position->x, $to->z - $this->position->z);
        }

        $this->onHurtTarget($target);
    }

    /**
     * The attack landed.
     */
    protected function onHurtTarget(Living $target) : void{

    }

    /**
     * How hard the world is here, for the extra things harder worlds bring: the least regional
     * difficulty of the difficulty, 0 in peaceful.
     */
    protected static function getEffectiveDifficulty() : float{
        return match(Server::getInstance()->getDifficulty()){
            Difficulty::PEACEFUL => 0.0,
            Difficulty::EASY => 0.75,
            Difficulty::NORMAL => 1.5,
            Difficulty::HARD => 2.25
        };
    }

    public function save() : ?CompoundTag{
        $tag = parent::save();
        $tag?->setByte("Persistent", $this->persistent ? 1 : 0);
        return $tag;
    }

    public function load(CompoundTag $tag) : void{
        parent::load($tag);
        $this->persistent = $tag->getByte("Persistent", 1) === 1;
    }
}