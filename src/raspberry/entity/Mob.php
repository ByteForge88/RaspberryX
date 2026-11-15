<?php

declare(strict_types=1);

namespace raspberry\entity;

use raspberry\block\BlockCollision;

use raspberry\entity\ai\Behavior;
use raspberry\entity\damage\DamageSource;
use raspberry\entity\effect\Effect;

use raspberry\item\enchantment\Enchantment;
use raspberry\item\Item;

use raspberry\math\Vector3;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\ActorEventPacket;
use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\SetActorDataPacket;
use raspberry\network\mcpe\protocol\types\EntityMetadata;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\player\Player;

use raspberry\world\Chunk;
use raspberry\world\World;

use raspberry\event\entity\EntityDeathEvent;

use function ceil;
use function crc32;
use function max;
use function mt_rand;

abstract class Mob extends Living{

    /** how far away a player can still feed, shear or milk a mob */
    public const INTERACT_REACH = 6.0;

    /** what the native side reports about where a mob is */
    public const STATE_ON_GROUND = 1;
    public const STATE_IN_WATER = 2;
    public const STATE_HEAD_IN_WATER = 4;
    public const STATE_IN_LAVA = 8;
    public const STATE_HAZARD = 16;
    public const STATE_IN_FIRE = 32;

    /** flags the native side takes */
    public const AI_BABY = 1;
    public const AI_IN_LOVE = 2;
    public const AI_SLOW_FALL = 4;
    public const AI_DISABLED = 8;
    public const AI_ON_FIRE = 16;
    public const AI_AVOID_WATER = 32;

    public const EVENT_FALL = 1;
    public const EVENT_EAT_START = 2;
    public const EVENT_EAT_BLOCK = 3;
    public const EVENT_BRED = 4;

    public const BABY_SCALE = 0.5;

    private const VIEWER_UPDATE_TICKS = 20;

    /** how long a dead mob lies there before it goes up in smoke */
    private const DEATH_TICKS = 20;

    /** a kill counts as a player's for this long after their last hit */
    private const PLAYER_KILL_TICKS = 100;

    private const SAFE_FALL = 3.0;

    protected float $headYaw;

    protected int $state = 0;

    protected bool $baby = false;

    protected int $ticksLived = 0;

    protected int $deathTicks = 0;

    protected ?Player $lastPlayerAttacker = null;

    protected int $lastPlayerHit = 0;

    private int $ambientDelay;

    public function __construct(World $world, Vector3 $position, float $yaw = 0.0){
        parent::__construct($world, $position);

        $this->yaw = $yaw;
        $this->headYaw = $yaw;
        $this->maxHealth = $this->getDefaultMaxHealth();
        $this->health = $this->maxHealth;
        $this->movementSpeed = $this->getDefaultMovementSpeed();
        $this->ambientDelay = $this->nextAmbientDelay();

        $world->getMobEngine()->add($this);
    }

    /** an EntityIds constant */
    abstract public function getNetworkTypeId() : string;

    abstract protected function getBaseWidth() : float;

    abstract protected function getBaseHeight() : float;

    abstract protected function getDefaultMaxHealth() : float;

    abstract protected function getDefaultMovementSpeed() : float;

    /**
     * @return list<Behavior>
     */
    abstract public function getBehaviors() : array;

    /**
     * @return list<Item>
     */
    abstract protected function getDrops(int $looting) : array;

    public function getXpReward() : int{
        return 0;
    }

    /** AI_ flags that are always set for this kind of mob */
    protected function getAiFlags() : int{
        return 0;
    }

    public function getEngineFlags() : int{
        return $this->getAiFlags() | ($this->baby ? self::AI_BABY : 0) | ($this->isOnFire() ? self::AI_ON_FIRE : 0);
    }

    /** two mobs of one kind can breed, and babies follow adults of their kind */
    public function getKind() : int{
        return crc32($this->getNetworkTypeId());
    }

    public function getScale() : float{
        return $this->baby ? self::BABY_SCALE : 1.0;
    }

    public function getWidth() : float{
        return $this->getBaseWidth() * $this->getScale();
    }

    public function getHeight() : float{
        return $this->getBaseHeight() * $this->getScale();
    }

    public function getEyeHeight() : float{
        return $this->getHeight() * 0.85;
    }

    /** mobs are sent standing on their position, not hanging from their eyes like players */
    public function getNetworkOffset() : float{
        return 0.0;
    }

    public function getHeadYaw() : float{
        return $this->headYaw;
    }

    public function isBaby() : bool{
        return $this->baby;
    }

    public function setBaby(bool $baby) : void{
        if($this->baby !== $baby){
            $this->baby = $baby;
            $this->world->getMobEngine()->update($this);
            $this->sendMetadata();
        }
    }

    public function isOnGround() : bool{
        return ($this->state & self::STATE_ON_GROUND) !== 0;
    }

    public function isInWater() : bool{
        return ($this->state & self::STATE_IN_WATER) !== 0;
    }

    public function isHeadInWater() : bool{
        return ($this->state & self::STATE_HEAD_IN_WATER) !== 0;
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    public function getSyncedProperties() : array{
        return [];
    }

    public function createMetadata() : EntityMetadata{
        $metadata = new EntityMetadata();
        $metadata->setFlag(EntityMetadata::FLAG_HAS_GRAVITY);
        $metadata->setFlag(EntityMetadata::FLAG_HAS_COLLISION);
        $metadata->setFlag(EntityMetadata::FLAG_CAN_CLIMB);
        $metadata->setFlag(EntityMetadata::FLAG_ON_FIRE, $this->isOnFire());
        $metadata->setFlag(EntityMetadata::FLAG_BREATHING, $this->isBreathing());
        $metadata->setFlag(EntityMetadata::FLAG_BABY, $this->baby);
        $metadata->setLong(EntityMetadata::KEY_FLAGS_TWO, 0);
        $metadata->setFloat(EntityMetadata::KEY_SCALE, $this->getScale());
        $metadata->setFloat(EntityMetadata::KEY_WIDTH, $this->getWidth());
        $metadata->setFloat(EntityMetadata::KEY_HEIGHT, $this->getHeight());
        $metadata->setShort(EntityMetadata::KEY_AIR_SUPPLY, $this->getAir());
        $metadata->setShort(EntityMetadata::KEY_AIR_SUPPLY_MAX, self::MAX_AIR);
        $metadata->setInt(EntityMetadata::KEY_VARIANT, 0);
        $metadata->setByte(EntityMetadata::KEY_COLOR_INDEX, 0);

        return $metadata;
    }

    public function sendMetadata() : void{
        if($this->viewers === []){
            return;
        }

        $packet = SetActorDataPacket::create($this->id, $this->createMetadata(), $this->world->getCurrentTick());

        foreach($this->viewers as $viewer){
            $viewer->getNetworkSession()->sendDataPacket($packet);
        }
    }

    protected function broadcastEvent(int $event, int $data = 0) : void{
        foreach($this->viewers as $viewer){
            $viewer->getNetworkSession()->sendActorEvent($this, $event, $data);
        }
    }

    /**
     * Plays one of this mob's own sounds (a LevelSoundEvent constant) to the players nearby.
     */
    public function playSound(string $sound) : void{
        $packet = LevelSoundEventPacket::create($sound, $this->position->x, $this->position->y, $this->position->z);
        $packet->entityType = $this->getNetworkTypeId();
        $packet->babyMob = $this->baby;

        $this->world->broadcastPacket($this->position->getFloorX(), $this->position->getFloorZ(), $packet);
    }

    /** ticks until the next ambient sound: 8 seconds and up to 16 more */
    protected function nextAmbientDelay() : int{
        return 160 + mt_rand(0, 320);
    }

    public function tick(int $currentTick) : void{
        if($this->closed){
            return;
        }

        if(!$this->isAlive()){
            if(++$this->deathTicks >= self::DEATH_TICKS){
                $this->broadcastEvent(ActorEventPacket::DEATH_SMOKE_CLOUD);
                $this->close();
            }

            return;
        }

        parent::tick($currentTick);

        if($this->ticksLived++ % self::VIEWER_UPDATE_TICKS === 0){
            $this->updateViewers();
        }

        $this->tickSurroundings();

        if($this->isAlive() && --$this->ambientDelay <= 0){
            $this->ambientDelay = $this->nextAmbientDelay();
            $this->playSound(LevelSoundEvent::AMBIENT);
        }
    }

    /**
     * Lava, fire, cactus and the like, the void, and being stuck in a block.
     */
    protected function tickSurroundings() : void{
        if(($this->state & self::STATE_IN_LAVA) !== 0){
            $this->setOnFire(15);
            $this->damage(new DamageSource(DamageSource::CAUSE_LAVA, 4.0));
        }

        if(($this->state & self::STATE_IN_FIRE) !== 0){
            $this->setOnFire(8);
            $this->damage(new DamageSource(DamageSource::CAUSE_FIRE, 1.0));
        }

        if(($this->state & self::STATE_HAZARD) !== 0){
            $this->damage(new DamageSource(DamageSource::CAUSE_CONTACT, 1.0));
        }

        if($this->position->y < Chunk::MIN_Y - 64){
            $this->damage(new DamageSource(DamageSource::CAUSE_VOID, 4.0));
        }

        if($this->ticksLived % 10 === 0){
            $eye = $this->getEyePosition();

            if($this->world->isChunkLoaded($eye->getChunkX(), $eye->getChunkZ()) && BlockCollision::isFullCube($this->world->getBlock($eye->getFloorX(), $eye->getFloorY(), $eye->getFloorZ()))){
                $this->damage(new DamageSource(DamageSource::CAUSE_SUFFOCATION, 1.0));
            }
        }
    }

    /**
     * @internal
     */
    public function onEngineMove(float $x, float $y, float $z, float $yaw, float $headYaw, float $pitch, int $state) : void{
        if($this->closed || !$this->isAlive()){
            return;
        }

        $this->position = new Vector3($x, $y, $z);
        $this->yaw = $yaw;
        $this->headYaw = $headYaw;
        $this->pitch = $pitch;
        $this->state = $state;

        foreach($this->viewers as $viewer){
            $viewer->getNetworkSession()->moveEntity($this);
        }
    }

    /**
     * @internal
     */
    public function onEngineEvent(int $kind, float $value, int $x, int $y, int $z, int $other) : void{
        if($kind === self::EVENT_FALL && $this->takesFallDamage()){
            $damage = ceil($value - self::SAFE_FALL);

            if($damage > 0){
                $this->playSound($damage > 4 ? LevelSoundEvent::FALL_BIG : LevelSoundEvent::FALL_SMALL);
                $this->damage(new DamageSource(DamageSource::CAUSE_FALL, $damage));
            }
        }
    }

    protected function takesFallDamage() : bool{
        return true;
    }

    public function setMotion(Vector3 $motion) : void{
        parent::setMotion($motion);
        $this->world->getMobEngine()->setMotion($this, $motion);
    }

    protected function doTeleport(Vector3 $position, ?float $yaw, ?float $pitch, ?World $world) : void{
        $moving = $world !== null && $world !== $this->world;

        if($moving){
            $this->world->getMobEngine()->remove($this);
        }

        parent::doTeleport($position, $yaw, $pitch, $world);
        $this->headYaw = $this->yaw;

        if($moving){
            $this->world->getMobEngine()->add($this);
        }else{
            $this->world->getMobEngine()->teleport($this);
        }
    }

    protected function onFireChanged() : void{
        $this->world->getMobEngine()->update($this);
        $this->sendMetadata();
    }

    protected function onAirChanged() : void{

    }

    protected function onAttributesChanged() : void{
        $this->world->getMobEngine()->update($this);
    }

    protected function updateEffectAttributes() : void{
        $speed = $this->getDefaultMovementSpeed() * (1.0 + 0.2 * $this->effects->getLevel(Effect::SPEED)) * max(0.0, 1.0 - 0.15 * $this->effects->getLevel(Effect::SLOWNESS));
        $maxHealth = $this->getDefaultMaxHealth() + 4.0 * $this->effects->getLevel(Effect::HEALTH_BOOST);

        if($speed !== $this->movementSpeed || $maxHealth !== $this->maxHealth){
            $this->movementSpeed = $speed;
            $this->setMaxHealth($maxHealth);
        }
    }

    protected function onDamaged(DamageSource $source) : void{
        $attacker = $source->getAttacker();

        if($attacker instanceof Player){
            $this->lastPlayerAttacker = $attacker;
            $this->lastPlayerHit = $this->ticksLived;
        }

        $this->broadcastEvent(ActorEventPacket::HURT);
        $this->world->getMobEngine()->hurt($this);
    }

    /**
     * The player whose kill this is, when they hit the mob in its last seconds.
     */
    protected function getKiller() : ?Player{
        $player = $this->lastPlayerAttacker;
        return $player !== null && $player->isOnline() && $this->ticksLived - $this->lastPlayerHit <= self::PLAYER_KILL_TICKS ? $player : null;
    }

    protected function onDeath() : void{
        $this->broadcastEvent(ActorEventPacket::DEATH);
        $this->world->getMobEngine()->remove($this);

        if($this->baby){
            return;
        }

        $killer = $this->getKiller();
        $looting = $killer?->getInventory()->getItemInHand()->getEnchantmentLevel(Enchantment::LOOTING) ?? 0;

        $event = new EntityDeathEvent($this, $this->getDrops($looting), $killer !== null ? $this->getXpReward() : 0, $killer);
        $event->call();

        foreach($event->getDrops() as $item){
            $this->world->dropItem($this->position, $item);
        }

        $killer?->addXp($event->getXp());
    }

    /**
     * How many of an item to drop: between $min and $max, and up to one more per level of looting.
     */
    protected static function lootCount(int $min, int $max, int $looting = 0) : int{
        return mt_rand($min, $max + $looting);
    }

    /**
     * A player used the item they hold on the mob. True when that did something.
     */
    public function onInteract(Player $player) : bool{
        return false;
    }

    /**
     * What the button says on touch screens while $player looks at the mob, "" for no button.
     */
    public function getInteractText(Player $player) : string{
        return "";
    }

    /**
     * Takes one of the item the player holds, outside creative.
     */
    protected static function useUpHeld(Player $player) : void{
        if($player->getGameMode()->hasInstantBuild()){
            return;
        }

        $held = $player->getInventory()->getItemInHand();
        $player->getInventory()->setItemInHand($held->getCount() > 1 ? $held->setCount($held->getCount() - 1) : Item::air());
    }

    /**
     * Gives the player an item, it drops at their feet when there is no room.
     */
    protected static function give(Player $player, Item $item) : void{
        foreach($player->getInventory()->addItem($item) as $left){
            $player->getWorld()->dropItem($player->getPosition(), $left);
        }
    }

    /**
     * What goes into the world's save for this mob, null for one that is not kept.
     */
    public function save() : ?CompoundTag{
        if(!$this->isAlive()){
            return null;
        }

        $tag = new CompoundTag();
        $tag->setString("identifier", $this->getNetworkTypeId());
        $tag->setDouble("X", $this->position->x);
        $tag->setDouble("Y", $this->position->y);
        $tag->setDouble("Z", $this->position->z);
        $tag->setFloat("Yaw", $this->yaw);
        $tag->setFloat("Health", $this->health);
        $tag->setShort("Fire", $this->fireTicks);
        $tag->setShort("Air", $this->air);
        $tag->setByte("IsBaby", $this->baby ? 1 : 0);
        return $tag;
    }

    /**
     * Takes over what save() wrote, the position aside: a loaded mob is made where it stood.
     */
    public function load(CompoundTag $tag) : void{
        $this->health = max(1.0, $tag->getFloat("Health", $this->maxHealth));
        $this->fireTicks = $tag->getShort("Fire", 0);
        $this->air = $tag->getShort("Air", self::MAX_AIR);
        $this->baby = $tag->getByte("IsBaby", 0) === 1;
    }

    public function close() : void{
        if(!$this->closed){
            $this->world->getMobEngine()->remove($this);
        }

        parent::close();
    }
}