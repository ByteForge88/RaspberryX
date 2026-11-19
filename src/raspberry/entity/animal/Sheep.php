<?php

declare(strict_types=1);

namespace raspberry\entity\animal;

use raspberry\block\BlockState;

use raspberry\entity\ai\Behavior;
use raspberry\entity\EntityIds;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\math\Vector3;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\ActorEventPacket;
use raspberry\network\mcpe\protocol\types\EntityMetadata;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\player\Player;

use raspberry\world\World;

use function array_search;
use function mt_rand;

class Sheep extends Animal{

    public const int WHITE = 0;
    public const int ORANGE = 1;
    public const int MAGENTA = 2;
    public const int LIGHT_BLUE = 3;
    public const int YELLOW = 4;
    public const int LIME = 5;
    public const int PINK = 6;
    public const int GRAY = 7;
    public const int LIGHT_GRAY = 8;
    public const int CYAN = 9;
    public const int PURPLE = 10;
    public const int BLUE = 11;
    public const int BROWN = 12;
    public const int GREEN = 13;
    public const int RED = 14;
    public const int BLACK = 15;

    private const array WOOL = [
        ItemIds::WHITE_WOOL, ItemIds::ORANGE_WOOL, ItemIds::MAGENTA_WOOL, ItemIds::LIGHT_BLUE_WOOL,
        ItemIds::YELLOW_WOOL, ItemIds::LIME_WOOL, ItemIds::PINK_WOOL, ItemIds::GRAY_WOOL,
        ItemIds::LIGHT_GRAY_WOOL, ItemIds::CYAN_WOOL, ItemIds::PURPLE_WOOL, ItemIds::BLUE_WOOL,
        ItemIds::BROWN_WOOL, ItemIds::GREEN_WOOL, ItemIds::RED_WOOL, ItemIds::BLACK_WOOL
    ];

    private const array DYES = [
        ItemIds::WHITE_DYE, ItemIds::ORANGE_DYE, ItemIds::MAGENTA_DYE, ItemIds::LIGHT_BLUE_DYE,
        ItemIds::YELLOW_DYE, ItemIds::LIME_DYE, ItemIds::PINK_DYE, ItemIds::GRAY_DYE,
        ItemIds::LIGHT_GRAY_DYE, ItemIds::CYAN_DYE, ItemIds::PURPLE_DYE, ItemIds::BLUE_DYE,
        ItemIds::BROWN_DYE, ItemIds::GREEN_DYE, ItemIds::RED_DYE, ItemIds::BLACK_DYE
    ];

    private const int GRASS_GROWTH = 1200;

    private int $color;

    private bool $sheared = false;

    public function __construct(World $world, Vector3 $position, float $yaw = 0.0){
        parent::__construct($world, $position, $yaw);
        $this->color = self::randomColor();
    }

    public static function randomColor() : int{
        $roll = mt_rand(0, 99999);

        return match(true){
            $roll < 5000 => self::BLACK,
            $roll < 10000 => self::GRAY,
            $roll < 15000 => self::LIGHT_GRAY,
            $roll < 18000 => self::BROWN,
            $roll < 18164 => self::PINK,
            default => self::WHITE
        };
    }

    public function getNetworkTypeId() : string{
        return EntityIds::SHEEP;
    }

    protected function getBaseWidth() : float{
        return 0.9;
    }

    protected function getBaseHeight() : float{
        return 1.3;
    }

    protected function getDefaultMaxHealth() : float{
        return 8.0;
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
            Behavior::eatBlock(6),
            Behavior::randomStroll(7, 0.8),
            Behavior::lookAtPlayer(8),
            Behavior::randomLookAround(9)
        ];
    }

    protected function getDrops(int $looting) : array{
        $drops = [new Item($this->isOnFire() ? ItemIds::COOKED_MUTTON : ItemIds::MUTTON, self::lootCount(1, 2, $looting))];

        if(!$this->sheared){
            $drops[] = new Item(self::WOOL[$this->color]);
        }

        return $drops;
    }

    public function getColor() : int{
        return $this->color;
    }

    public function setColor(int $color) : void{
        $this->color = $color & 15;
        $this->sendMetadata();
    }

    public function isSheared() : bool{
        return $this->sheared;
    }

    public function setSheared(bool $sheared) : void{
        $this->sheared = $sheared;
        $this->sendMetadata();
    }

    public function createMetadata() : EntityMetadata{
        $metadata = parent::createMetadata();
        $metadata->setFlag(EntityMetadata::FLAG_SHEARED, $this->sheared);
        $metadata->setByte(EntityMetadata::KEY_COLOR_INDEX, $this->color);

        return $metadata;
    }

    private function canBeShearedBy(Player $player) : bool{
        return !$this->baby && !$this->sheared && $player->getInventory()->getItemInHand()->getId() === ItemIds::SHEARS;
    }

    private function getDyeColor(Player $player) : ?int{
        $color = array_search($player->getInventory()->getItemInHand()->getId(), self::DYES, true);

        return $color === false || $color === $this->color || $this->sheared ? null : $color;
    }

    public function getInteractText(Player $player) : string{
        return match(true){
            $this->canBeShearedBy($player) => "action.interact.shear",
            $this->getDyeColor($player) !== null => "action.interact.dye",
            default => parent::getInteractText($player)
        };
    }

    public function onInteract(Player $player) : bool{
        if($this->canBeShearedBy($player)){
            $this->setSheared(true);
            $this->playSound(LevelSoundEvent::SHEAR);
            $player->wearHeld(1);

            for($wool = mt_rand(1, 3); $wool > 0; --$wool){
                $this->world->dropItem($this->position->add(0, $this->getHeight(), 0), new Item(self::WOOL[$this->color]));
            }

            return true;
        }

        $color = $this->getDyeColor($player);

        if($color !== null){
            $this->setColor($color);
            self::useUpHeld($player);

            return true;
        }

        return parent::onInteract($player);
    }

    public function onEngineEvent(int $kind, float $value, int $x, int $y, int $z, int $other) : void{
        if($kind === self::EVENT_EAT_START){
            $this->broadcastEvent(ActorEventPacket::EAT_GRASS);
        }elseif($kind === self::EVENT_EAT_BLOCK){
            $this->world->setBlock($x, $y, $z, new BlockState($value > 0 ? "minecraft:air" : "minecraft:dirt"));
            $this->setSheared(false);
            $this->growUp(self::GRASS_GROWTH);
        }else{
            parent::onEngineEvent($kind, $value, $x, $y, $z, $other);
        }
    }

    protected function inherit(Animal $first, Animal $second) : void{
        $parent = mt_rand(0, 1) === 0 ? $first : $second;

        if($parent instanceof self){
            $this->color = $parent->color;
        }
    }

    public function save() : ?CompoundTag{
        $tag = parent::save();
        $tag?->setByte("Color", $this->color);
        $tag?->setByte("Sheared", $this->sheared ? 1 : 0);
        return $tag;
    }

    public function load(CompoundTag $tag) : void{
        parent::load($tag);
        $this->color = $tag->getByte("Color", $this->color) & 15;
        $this->sheared = $tag->getByte("Sheared", 0) === 1;
    }
}