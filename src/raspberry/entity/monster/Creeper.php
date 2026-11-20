<?php

declare(strict_types=1);

namespace raspberry\entity\monster;

use raspberry\entity\EntityIds;
use raspberry\entity\ai\Behavior;

use raspberry\event\entity\ExplosionPrimeEvent;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\types\EntityMetadata;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\player\Player;

use raspberry\world\Explosion;
use raspberry\world\GameRules;

use function count;
use function max;
use function mt_rand;

class Creeper extends Monster{

    public const float FOLLOW_RANGE = 16.0;

    public const int MAX_SWELL = 30;

    public const float EXPLOSION_RADIUS = 3.0;

    private const array MUSIC_DISCS = [
        "minecraft:music_disc_13",
        "minecraft:music_disc_cat",
        "minecraft:music_disc_blocks",
        "minecraft:music_disc_chirp",
        "minecraft:music_disc_far",
        "minecraft:music_disc_mall",
        "minecraft:music_disc_mellohi",
        "minecraft:music_disc_stal",
        "minecraft:music_disc_strad",
        "minecraft:music_disc_ward",
        "minecraft:music_disc_11",
        "minecraft:music_disc_wait"
    ];

    private int $swell = 0;

    /** 1 while it swells up, -1 while it calms down */
    private int $swellDir = -1;

    private bool $ignited = false;

    private bool $powered = false;

    public function getNetworkTypeId() : string{
        return EntityIds::CREEPER;
    }

    protected function getBaseWidth() : float{
        return 0.6;
    }

    protected function getBaseHeight() : float{
        return 1.7;
    }

    protected function getDefaultMaxHealth() : float{
        return 20.0;
    }

    protected function getDefaultMovementSpeed() : float{
        return 0.25;
    }

    /** it only walks up to its target, the swelling does the rest */
    protected function getAttackDamage() : float{
        return 0.0;
    }

    public function getBehaviors() : array{
        return [
            Behavior::float(1),
            Behavior::nearestAttackableTarget(1, self::FOLLOW_RANGE),
            Behavior::swell(2),
            Behavior::hurtByTarget(2, self::FOLLOW_RANGE),
            Behavior::meleeAttack(4, 1.0),
            Behavior::randomStroll(5, 0.8),
            Behavior::lookAtPlayer(6, 8.0),
            Behavior::randomLookAround(6)
        ];
    }

    public function isPowered() : bool{
        return $this->powered;
    }

    /**
     * A charged creeper, struck by lightning, blows up twice as big.
     */
    public function setPowered(bool $powered) : void{
        if($this->powered !== $powered){
            $this->powered = $powered;
            $this->sendMetadata();
        }
    }

    public function isIgnited() : bool{
        return $this->ignited;
    }

    /**
     * Lit with flint and steel it swells up and blows no matter what.
     */
    public function ignite() : void{
        $this->ignited = true;
    }

    public function getSwell() : int{
        return $this->swell;
    }

    public function createMetadata() : EntityMetadata{
        $metadata = parent::createMetadata();
        $metadata->setFlag(EntityMetadata::FLAG_POWERED, $this->powered);
        $metadata->setFlag(EntityMetadata::FLAG_IGNITED, $this->swellDir > 0);
        $metadata->setInt(EntityMetadata::KEY_FUSE_TIME, self::MAX_SWELL - $this->swell);
        return $metadata;
    }

    public function onEngineEvent(int $kind, float $value, int $x, int $y, int $z, int $other) : void{
        if($kind === self::EVENT_SWELL){
            $this->setSwellDir($value > 0 ? 1 : -1);
            return;
        }

        parent::onEngineEvent($kind, $value, $x, $y, $z, $other);
    }

    private function setSwellDir(int $direction) : void{
        if($this->swellDir === $direction){
            return;
        }

        $this->swellDir = $direction;

        if($direction > 0 && $this->swell === 0){
            $this->playSound(LevelSoundEvent::FUSE);
        }

        $this->sendMetadata();
    }

    public function tick(int $currentTick) : void{
        parent::tick($currentTick);

        if($this->closed || !$this->isAlive()){
            return;
        }

        if($this->ignited){
            $this->setSwellDir(1);
        }

        $this->swell = max(0, $this->swell + $this->swellDir);

        if($this->swell >= self::MAX_SWELL){
            $this->explode();
        }
    }

    private function explode() : void{
        $radius = self::EXPLOSION_RADIUS * ($this->powered ? 2 : 1);
        $event = new ExplosionPrimeEvent($this, $radius);
        $event->call();

        if($event->isCancelled()){
            $this->swell = 0;
            $this->ignited = false;
            $this->setSwellDir(-1);
            return;
        }

        $this->close();

        $explosion = new Explosion($this->world, $this->position, $event->getRadius(), $this);
        $explosion->setBlockBreaking($event->isBlockBreaking() && $this->world->getGameRules()->getBool(GameRules::MOB_GRIEFING));
        $explosion->explode();
    }

    public function onInteract(Player $player) : bool{
        if($player->getInventory()->getItemInHand()->getId() !== ItemIds::FLINT_AND_STEEL || $this->ignited){
            return parent::onInteract($player);
        }

        $this->playSound(LevelSoundEvent::IGNITE);
        $this->ignite();
        $player->wearHeld(1);

        return true;
    }

    public function getInteractText(Player $player) : string{
        return $player->getInventory()->getItemInHand()->getId() === ItemIds::FLINT_AND_STEEL && !$this->ignited ? "action.interact.creeper" : parent::getInteractText($player);
    }

    protected function getDrops(int $looting) : array{
        $gunpowder = self::lootCount(0, 2, $looting);
        $drops = $gunpowder > 0 ? [new Item(ItemIds::GUNPOWDER, $gunpowder)] : [];

        if($this->lastDamageSource?->getAttacker() instanceof Skeleton){
            $drops[] = new Item(self::MUSIC_DISCS[mt_rand(0, count(self::MUSIC_DISCS) - 1)]);
        }

        return $drops;
    }

    public function save() : ?CompoundTag{
        $tag = parent::save();
        $tag?->setByte("powered", $this->powered ? 1 : 0);
        $tag?->setByte("ignited", $this->ignited ? 1 : 0);
        return $tag;
    }

    public function load(CompoundTag $tag) : void{
        parent::load($tag);
        $this->powered = $tag->getByte("powered", 0) === 1;
        $this->ignited = $tag->getByte("ignited", 0) === 1;
    }
}