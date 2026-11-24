<?php

declare(strict_types=1);

namespace raspberry\entity\monster;

use raspberry\Server;

use raspberry\entity\EntityIds;
use raspberry\entity\Human;
use raspberry\entity\ai\Behavior;
use raspberry\entity\projectile\Projectile;
use raspberry\entity\projectile\ThrownTrident;

use raspberry\item\Item;
use raspberry\item\ItemIds;
use raspberry\item\ItemSerializer;

use raspberry\math\Vector3;

use raspberry\nbt\NbtException;
use raspberry\nbt\tag\CompoundTag;
use raspberry\nbt\tag\ListTag;
use raspberry\nbt\tag\Tag;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\EntityMetadata;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\world\World;

use function max;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function sqrt;

class Drowned extends Zombie{

    public const float TARGET_RANGE = 12.0;

    private const int RANGED_CHANCE = 25;
    private const int RANGED_OUT_OF = 400;

    private const float FISHING_ROD_CHANCE = 0.0375;
    private const float NAUTILUS_SHELL_CHANCE = 0.08;

    private const float COPPER_CHANCE = 0.11;
    private const float COPPER_LOOTING = 0.02;

    private const float HELD_DROP_CHANCE = 0.085;

    private const int THROW_MIN_TICKS = 20;
    private const int THROW_MAX_TICKS = 60;
    private const float THROW_RADIUS = 10.0;

    private const float MELEE_RANGE = 3.0;
    private const float RANGED_RANGE = 5.0;

    private const int DAYTIME_CHECK_TICKS = 20;

    private ?Item $mainhand = null;

    private ?Item $offhand = null;

    private bool $raising = false;

    private bool $day = false;

    public function __construct(World $world, Vector3 $position, float $yaw = 0.0){
        parent::__construct($world, $position, $yaw);
        $this->rollEquipment();
    }

    private function rollEquipment() : void{
        if(mt_rand(0, self::RANGED_OUT_OF - 1) < self::RANGED_CHANCE){
            $this->mainhand = new Item(ItemIds::TRIDENT);
        }elseif(mt_rand() / mt_getrandmax() < self::FISHING_ROD_CHANCE){
            $this->mainhand = new Item(ItemIds::FISHING_ROD);
        }

        if(mt_rand() / mt_getrandmax() < self::NAUTILUS_SHELL_CHANCE){
            $this->offhand = new Item(ItemIds::NAUTILUS_SHELL);
        }
    }

    public function clearEquipment() : void{
        $this->mainhand = null;
        $this->offhand = null;
    }

    public function getNetworkTypeId() : string{
        return EntityIds::DROWNED;
    }

    protected function getDefaultMovementSpeed() : float{
        return $this->baby ? 0.35 : 0.23;
    }

    protected function getAiFlags() : int{
        return self::AI_AQUATIC | ($this->day ? self::AI_DAYTIME : 0);
    }

    protected function getConversionType() : ?string{
        return null;
    }

    public function getHeldItem() : ?Item{
        return $this->mainhand;
    }

    public function getOffhandItem() : ?Item{
        return $this->offhand;
    }

    public function hasTrident() : bool{
        return $this->mainhand?->getId() === ItemIds::TRIDENT;
    }

    public function getXpReward() : int{
        $xp = parent::getXpReward();

        foreach([$this->mainhand, $this->offhand] as $item){
            if($item !== null){
                $xp += mt_rand(1, 3);
            }
        }

        return $xp;
    }

    public function getBehaviors() : array{
        $attack = $this->hasTrident() ? [
            Behavior::rangedAttack(3, 1.0, self::THROW_MIN_TICKS, self::THROW_MAX_TICKS, self::THROW_RADIUS, self::MELEE_RANGE),
            Behavior::meleeAttack(3, 1.0, false, false, self::RANGED_RANGE)
        ] : [
            Behavior::meleeAttack(3, 1.0)
        ];

        return [
            Behavior::hurtByTarget(1, self::FOLLOW_RANGE),
            Behavior::nearestAttackableTarget(2, self::TARGET_RANGE, true, 10, false, true),
            ...$attack,
            Behavior::randomStroll(7, 1.0),
            Behavior::lookAtPlayer(8, 6.0),
            Behavior::randomLookAround(9)
        ];
    }

    public function createMetadata() : EntityMetadata{
        $metadata = parent::createMetadata();
        $metadata->setFlag(EntityMetadata::FLAG_USING_ITEM, $this->raising);
        return $metadata;
    }

    public function tick(int $currentTick) : void{
        parent::tick($currentTick);

        if($this->closed || !$this->isAlive() || $this->ticksLived % self::DAYTIME_CHECK_TICKS !== 0){
            return;
        }

        $day = $this->world->isDaytime();

        if($day !== $this->day){
            $this->day = $day;
            $this->world->getMobEngine()->update($this);
        }
    }

    public function onEngineEvent(int $kind, float $value, int $x, int $y, int $z, int $other) : void{
        if($kind === self::EVENT_USE_ITEM){
            $this->raising = $value > 0;
            $this->sendMetadata();
            return;
        }

        if($kind === self::EVENT_SHOOT){
            $target = $this->world->getEntity($other);

            if($target !== null && !$target->isClosed() && $this->hasTrident()){
                $this->throwAt($target->getPosition(), $target instanceof Human ? Human::HEIGHT : $target->getHeight());
            }

            return;
        }

        parent::onEngineEvent($kind, $value, $x, $y, $z, $other);
    }

    private function throwAt(Vector3 $target, float $height) : void{
        $difficulty = Server::getInstance()->getDifficulty()->value;
        $from = new Vector3($this->position->x, $this->position->y + $this->getEyeHeight() - 0.1, $this->position->z);

        $dx = $target->x - $from->x;
        $dy = $target->y + $height / 3 - $from->y;
        $dz = $target->z - $from->z;
        $flat = sqrt($dx * $dx + $dz * $dz);

        new ThrownTrident($this->world, $from, Projectile::aim($dx, $dy + $flat * 0.2, $dz, 1.6, 14 - $difficulty * 4), $this);

        $this->world->broadcastPacket($from->getFloorX(), $from->getFloorZ(), LevelSoundEventPacket::create(LevelSoundEvent::ITEM_TRIDENT_THROW, $from->x, $from->y, $from->z));
    }

    protected function getDrops(int $looting) : array{
        $drops = [];
        $flesh = self::lootCount(0, 2, $looting);

        if($flesh > 0){
            $drops[] = new Item(ItemIds::ROTTEN_FLESH, $flesh);
        }

        $killed = $this->getKiller() !== null;

        if($killed && mt_rand() / mt_getrandmax() < self::COPPER_CHANCE + self::COPPER_LOOTING * $looting){
            $drops[] = new Item(ItemIds::COPPER_INGOT);
        }

        if($this->mainhand !== null && $killed && mt_rand() / mt_getrandmax() < self::HELD_DROP_CHANCE + 0.01 * $looting){
            $held = clone $this->mainhand;

            if($held->hasDurability()){
                $max = $held->getMaxDurability();
                $held->setDamage(min($max - 1, $max - mt_rand(0, mt_rand(0, max($max - 3, 1) - 1))));
            }

            $drops[] = $held;
        }

        if($this->offhand !== null){
            $drops[] = clone $this->offhand;
        }

        return $drops;
    }

    public function save() : ?CompoundTag{
        $tag = parent::save();
        $tag?->setTag("Mainhand", self::writeEquipment($this->mainhand));
        $tag?->setTag("Offhand", self::writeEquipment($this->offhand));
        return $tag;
    }

    public function load(CompoundTag $tag) : void{
        parent::load($tag);
        $this->mainhand = self::readEquipment($tag->getListTag("Mainhand"));
        $this->offhand = self::readEquipment($tag->getListTag("Offhand"));
    }

    private static function writeEquipment(?Item $item) : ListTag{
        $list = new ListTag([], Tag::TAG_COMPOUND);

        if($item !== null){
            $list->push(ItemSerializer::serialize($item));
        }

        return $list;
    }

    private static function readEquipment(?ListTag $list) : ?Item{
        $entry = $list?->getValue()[0] ?? null;

        if(!$entry instanceof CompoundTag){
            return null;
        }

        try{
            $item = ItemSerializer::deserialize($entry);
        }catch(NbtException){
            return null;
        }

        return $item->isNull() ? null : $item;
    }
}