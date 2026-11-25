<?php

declare(strict_types=1);

namespace raspberry\entity\object;

use raspberry\entity\Entity;
use raspberry\entity\EntityIds;
use raspberry\entity\Human;

use raspberry\item\enchantment\Enchantment;

use raspberry\math\Vector3;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\types\EntityMetadata;

use raspberry\player\Player;
use raspberry\player\GameMode;

use raspberry\world\Chunk;
use raspberry\world\World;

use function count;
use function floor;
use function intdiv;
use function max;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function sqrt;

class ExperienceOrb extends Entity{
    use BlockPhysics;

    /** experience comes in orbs of these sizes, the biggest that fit first */
    private const array SIZES = [2477, 1237, 617, 307, 149, 73, 37, 17, 7, 3, 1];

    private const float SIZE = 0.5;
    private const float GRAVITY = 0.03;
    private const float DRAG = 0.02;
    private const float GROUND_FRICTION = 0.6;

    private const int DESPAWN_TICKS = 6000;
    private const int VOID_DEPTH = 64;

    /** orbs fly to a player this close, looking again once a second */
    private const float FOLLOW_RANGE = 8.0;
    private const int SCAN_TICKS = 20;

    /** a player takes an orb at most this often */
    private const int PICKUP_COOLDOWN = 2;

    /** mending repairs this much durability for each point of experience */
    private const int MENDING_REPAIR = 2;

    /** @var array<int, int> */
    private static array $cooldowns = [];

    private int $value;

    /** orbs of the same size that merged into this one */
    private int $count = 1;

    private int $age = 0;

    private ?Player $following = null;

    private bool $viewersUpdated = false;

    public function __construct(World $world, Vector3 $position, int $value, ?Vector3 $motion = null){
        $this->value = $value;
        $this->motion = $motion ?? new Vector3((mt_rand() / mt_getrandmax() * 0.2 - 0.1) * 2, mt_rand() / mt_getrandmax() * 0.4, (mt_rand() / mt_getrandmax() * 0.2 - 0.1) * 2);

        parent::__construct($world, $position);
    }

    public static function award(World $world, Vector3 $position, int $amount) : void{
        while($amount > 0){
            $value = self::SIZES[count(self::SIZES) - 1];

            foreach(self::SIZES as $size){
                if($amount >= $size){
                    $value = $size;
                    break;
                }
            }

            $amount -= $value;
            new self($world, $position, $value);
        }
    }

    public static function fromSave(World $world, CompoundTag $tag) : ?self{
        [$x, $y, $z] = [$tag->getDouble("X"), $tag->getDouble("Y"), $tag->getDouble("Z")];
        $value = $tag->getInt("experience value", 0);

        if($x === null || $y === null || $z === null || $value <= 0){
            return null;
        }

        $orb = new self($world, new Vector3($x, $y, $z), $value, new Vector3(0.0, 0.0, 0.0));
        $orb->count = max(1, $tag->getInt("Count", 1));
        $orb->age = $tag->getShort("Age", 0);

        return $orb;
    }

    public function save() : ?CompoundTag{
        return (new CompoundTag())
            ->setString("identifier", EntityIds::XP_ORB)
            ->setDouble("X", $this->position->x)
            ->setDouble("Y", $this->position->y)
            ->setDouble("Z", $this->position->z)
            ->setInt("experience value", $this->value)
            ->setInt("Count", $this->count)
            ->setShort("Age", $this->age);
    }

    public function getNetworkTypeId() : string{
        return EntityIds::XP_ORB;
    }

    public function getWidth() : float{
        return self::SIZE;
    }

    public function getHeight() : float{
        return self::SIZE;
    }

    public function getValue() : int{
        return $this->value;
    }

    public function createMetadata() : EntityMetadata{
        $metadata = new EntityMetadata();
        $metadata->setLong(EntityMetadata::KEY_FLAGS_TWO, 0);
        $metadata->setFlag(EntityMetadata::FLAG_HAS_GRAVITY);
        $metadata->setFloat(EntityMetadata::KEY_SCALE, 1.0);
        $metadata->setInt(EntityMetadata::KEY_EXPERIENCE_VALUE, $this->value);

        return $metadata;
    }

    public function tick(int $currentTick) : void{
        if($this->closed){
            return;
        }

        if(++$this->age >= self::DESPAWN_TICKS || $this->position->y < $this->world->getMinY() - self::VOID_DEPTH){
            $this->close();
            return;
        }

        if(!$this->world->isChunkLoaded($this->position->getChunkX(), $this->position->getChunkZ())){
            return;
        }

        if(!$this->viewersUpdated || $currentTick % self::SCAN_TICKS === 0){
            $this->viewersUpdated = true;
            $this->updateViewers();
        }

        if($this->isInLava()){
            $this->close();
            return;
        }

        if($this->age % self::SCAN_TICKS === 1){
            $this->scan();
        }

        $this->follow();
        $this->fall(self::GRAVITY, self::DRAG, self::GROUND_FRICTION);
        $this->tryPickup($currentTick);
    }

    /**
     * Picks the player to fly to and takes in orbs of the same size right next to it.
     */
    private function scan() : void{
        $nearest = null;
        $distance = self::FOLLOW_RANGE ** 2;

        foreach($this->world->getPlayers() as $player){
            if(!$player->isSpawned() || $player->isDead() || $player->getGameMode() === GameMode::SPECTATOR){
                continue;
            }

            $d = $player->getPosition()->distanceSquared($this->position);

            if($d < $distance){
                $distance = $d;
                $nearest = $player;
            }
        }

        $this->following = $nearest;

        foreach($this->world->getEntities() as $entity){
            if($entity instanceof self && $entity !== $this && !$entity->closed && $entity->value === $this->value && $entity->position->distanceSquared($this->position) < 0.25){
                $this->count += $entity->count;
                $entity->close();
            }
        }
    }

    private function follow() : void{
        $player = $this->following;

        if($player === null || !$player->isOnline() || $player->isDead() || $player->getWorld() !== $this->world){
            $this->following = null;
            return;
        }

        $target = $player->getPosition();
        $dx = $target->x - $this->position->x;
        $dy = $target->y + $player->getEyeHeight() / 2 - $this->position->y;
        $dz = $target->z - $this->position->z;
        $distance = $dx * $dx + $dy * $dy + $dz * $dz;

        if($distance >= self::FOLLOW_RANGE ** 2 || $distance < 1.0e-7){
            return;
        }

        $length = sqrt($distance);
        $pull = (1 - $length / self::FOLLOW_RANGE) ** 2 * 0.1;

        $this->motion = new Vector3($this->motion->x + $dx / $length * $pull, $this->motion->y + $dy / $length * $pull, $this->motion->z + $dz / $length * $pull);
    }

    private function tryPickup(int $currentTick) : void{
        $half = self::SIZE / 2;
        $position = $this->position;

        foreach($this->world->getPlayers() as $player){
            if(!$player->isSpawned() || $player->isDead() || $player->getGameMode() === GameMode::SPECTATOR || (self::$cooldowns[$player->getId()] ?? 0) > $currentTick){
                continue;
            }

            $feet = $player->getPosition();
            $reach = Human::WIDTH / 2 + 1 + $half;

            if($position->x + $half < $feet->x - $reach || $position->x - $half > $feet->x + $reach || $position->z + $half < $feet->z - $reach || $position->z - $half > $feet->z + $reach ||
                $position->y + self::SIZE < $feet->y - 0.5 || $position->y > $feet->y + Human::HEIGHT + 0.5){
                continue;
            }

            self::$cooldowns[$player->getId()] = $currentTick + self::PICKUP_COOLDOWN;

            $left = self::mend($player, $this->value);

            if($left > 0){
                $player->addXp($left);
            }

            if(--$this->count <= 0){
                $this->close();
            }

            return;
        }
    }

    /**
     * Mending items the player wears or holds take the experience as durability first, the rest
     * is what is left for the player.
     */
    private static function mend(Player $player, int $value) : int{
        $damaged = [];
        $inventories = [[$player->getInventory(), $player->getInventory()->getHeldSlot()], [$player->getOffHandInventory(), 0]];

        foreach($inventories as [$inventory, $slot]){
            $item = $inventory->getItem($slot);

            if($item->getDamage() > 0 && $item->getEnchantmentLevel(Enchantment::MENDING) > 0){
                $damaged[] = [$inventory, $slot];
            }
        }

        $armor = $player->getArmorInventory();

        for($slot = 0; $slot < $armor->getSize(); ++$slot){
            $item = $armor->getItem($slot);

            if($item->getDamage() > 0 && $item->getEnchantmentLevel(Enchantment::MENDING) > 0){
                $damaged[] = [$armor, $slot];
            }
        }

        if($damaged === []){
            return $value;
        }

        [$inventory, $slot] = $damaged[mt_rand(0, count($damaged) - 1)];
        $item = $inventory->getItem($slot);
        $most = $value * self::MENDING_REPAIR;
        $repair = min($most, $item->getDamage());

        $inventory->setItem($slot, (clone $item)->setDamage($item->getDamage() - $repair));

        $left = $value - intdiv($repair * $value, $most);

        return $left > 0 && $repair > 0 ? self::mend($player, $left) : $left;
    }

    private function isInLava() : bool{
        $y = (int) floor($this->position->y);

        if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y){
            return false;
        }

        $name = $this->world->getBlock($this->position->getFloorX(), $y, $this->position->getFloorZ())->getName();

        return $name === "minecraft:lava" || $name === "minecraft:flowing_lava";
    }
}