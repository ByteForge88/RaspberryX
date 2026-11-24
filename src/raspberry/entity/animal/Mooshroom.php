<?php

declare(strict_types=1);

namespace raspberry\entity\animal;

use raspberry\entity\EntityIds;
use raspberry\entity\ai\Behavior;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\types\EntityMetadata;
use raspberry\network\mcpe\protocol\types\LevelEvent;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;
use raspberry\network\mcpe\protocol\types\ParticleType;

use raspberry\player\Player;

use function mt_getrandmax;
use function mt_rand;

class Mooshroom extends Animal{

    public const int RED = 0;
    public const int BROWN = 1;

    private const int SHEAR_MUSHROOMS = 5;

    private const float MUTATION_CHANCE = 0.00098;

    private int $variant = self::RED;

    public function getNetworkTypeId() : string{
        return EntityIds::MOOSHROOM;
    }

    protected function getBaseWidth() : float{
        return 0.9;
    }

    protected function getBaseHeight() : float{
        return 1.3;
    }

    protected function getDefaultMaxHealth() : float{
        return 10.0;
    }

    protected function getDefaultMovementSpeed() : float{
        return 0.25;
    }

    protected function getAiFlags() : int{
        return self::AI_AVOID_WATER;
    }

    public function getBreedItems() : array{
        return [ItemIds::WHEAT];
    }

    public function getBehaviors() : array{
        return [
            Behavior::float(0),
            Behavior::panic(1, 1.25),
            Behavior::breed(3, 1.0),
            Behavior::tempt(4, 1.25, [ItemIds::WHEAT]),
            Behavior::followParent(5, 1.1),
            Behavior::randomStroll(6, 0.8),
            Behavior::lookAtPlayer(7),
            Behavior::randomLookAround(9)
        ];
    }

    public function getVariant() : int{
        return $this->variant;
    }

    public function setVariant(int $variant) : void{
        if($this->variant !== $variant){
            $this->variant = $variant;
            $this->sendMetadata();
        }
    }

    public function createMetadata() : EntityMetadata{
        $metadata = parent::createMetadata();
        $metadata->setInt(EntityMetadata::KEY_VARIANT, $this->variant);

        return $metadata;
    }

    public function strikeByLightning() : void{
        $this->setVariant($this->variant === self::RED ? self::BROWN : self::RED);
        $this->playSound(LevelSoundEvent::CONVERT_MOOSHROOM);
    }

    protected function getDrops(int $looting) : array{
        return [
            new Item(ItemIds::LEATHER, self::lootCount(0, 2, $looting)),
            new Item($this->isOnFire() ? ItemIds::COOKED_BEEF : ItemIds::BEEF, self::lootCount(1, 3, $looting))
        ];
    }

    private function getHeld(Player $player) : ?string{
        return $this->baby ? null : $player->getInventory()->getItemInHand()->getId();
    }

    public function getInteractText(Player $player) : string{
        return match($this->getHeld($player)){
            ItemIds::SHEARS => "action.interact.mooshear",
            ItemIds::BOWL => "action.interact.moostew",
            ItemIds::BUCKET => "action.interact.milk",
            default => parent::getInteractText($player)
        };
    }

    public function onInteract(Player $player) : bool{
        $held = $this->getHeld($player);

        if($held === ItemIds::SHEARS){
            $this->shear($player);
            return true;
        }

        if($held === ItemIds::BOWL || $held === ItemIds::BUCKET){
            $this->playSound($held === ItemIds::BOWL ? LevelSoundEvent::MILK_SUSPICIOUSLY : LevelSoundEvent::MILK);

            if(!$player->getGameMode()->hasInstantBuild()){
                self::useUpHeld($player);
                self::give($player, new Item($held === ItemIds::BOWL ? ItemIds::MUSHROOM_STEW : ItemIds::MILK_BUCKET));
            }

            return true;
        }

        return parent::onInteract($player);
    }

    private function shear(Player $player) : void{
        $this->playSound(LevelSoundEvent::SHEAR);
        $player->wearHeld(1);

        $top = $this->position->add(0, $this->getHeight(), 0);
        $mushroom = $this->variant === self::BROWN ? ItemIds::BROWN_MUSHROOM : ItemIds::RED_MUSHROOM;

        for($i = 0; $i < self::SHEAR_MUSHROOMS; ++$i){
            $this->world->dropItem($top, new Item($mushroom));
        }

        $this->world->broadcastPacket($this->position->getFloorX(), $this->position->getFloorZ(), LevelEventPacket::create(LevelEvent::PARTICLE_LEGACY_EVENT | ParticleType::LARGE_EXPLODE, $this->position->x, $this->position->y + $this->getHeight() / 2, $this->position->z));

        $cow = new Cow($this->world, $this->position, $this->yaw);
        $cow->setHealth($this->getHealth());
        $this->close();
    }

    protected function inherit(Animal $first, Animal $second) : void{
        if(!$first instanceof self || !$second instanceof self){
            return;
        }

        $this->variant = (mt_rand(0, 1) === 0 ? $first : $second)->variant;

        if($first->variant === $second->variant && mt_rand() / mt_getrandmax() < self::MUTATION_CHANCE){
            $this->variant = $this->variant === self::RED ? self::BROWN : self::RED;
        }
    }

    public function save() : ?CompoundTag{
        $tag = parent::save();
        $tag?->setInt("Variant", $this->variant);
        return $tag;
    }

    public function load(CompoundTag $tag) : void{
        parent::load($tag);
        $this->variant = $tag->getInt("Variant", self::RED) === self::BROWN ? self::BROWN : self::RED;
    }
}