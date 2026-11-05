<?php

declare(strict_types=1);

namespace raspberry\item;

use raspberry\item\enchantment\Enchantment;

use raspberry\nbt\NbtSerializer;
use raspberry\nbt\tag\CompoundTag;

use InvalidArgumentException;

use function array_values;
use function count;
use function ksort;
use function max;
use function min;
use function strlen;

class Item{

    public const MAX_COUNT = 255;

    public const MAX_TEXT_LENGTH = 512;

    public const MAX_LORE_LINES = 32;

    public const MAX_ENCHANTMENT_LEVEL = 32767;

    private string $id;

    private int $count;

    private int $meta;

    private int $damage = 0;

    private ?string $customName = null;

    /** @var list<string> */
    private array $lore = [];

    /** @var array<int, int> enchantment ID => level */
    private array $enchantments = [];

    private CompoundTag $extraTags;

    /**
     * @param string $id
     * @param int $count
     * @param int $meta
     */
    public function __construct(string $id, int $count = 1, int $meta = 0){
        if($id === ""){
            throw new InvalidArgumentException("Item ID cannot be empty");
        }

        if($meta < 0 || $meta > 32767){
            throw new InvalidArgumentException("Item data value must be between 0 and 32767, got {$meta}");
        }

        $this->id = $id;
        $this->meta = $meta;
        $this->extraTags = new CompoundTag();
        $this->setCount($count);
    }

    /**
     * An empty slot.
     */
    public static function air() : self{
        return new self(ItemIds::AIR, 0);
    }

    /**
     * The item's ID, e.g. "minecraft:diamond_sword".
     */
    public function getId() : string{
        return $this->id;
    }

    /**
     * The vanilla English name, e.g. "Diamond Sword", whatever the custom name is.
     */
    public function getName() : string{
        return ItemNames::get($this->id);
    }

    /**
     * What players see: the custom name when there is one, else the vanilla name.
     */
    public function getDisplayName() : string{
        return $this->customName ?? $this->getName();
    }

    public function getMeta() : int{
        return $this->meta;
    }

    /**
     * True for an empty slot: air, or nothing left in the stack.
     */
    public function isNull() : bool{
        return $this->id === ItemIds::AIR || $this->count <= 0;
    }

    public function getCount() : int{
        return $this->count;
    }

    public function setCount(int $count) : self{
        if($count < 0 || $count > self::MAX_COUNT){
            throw new InvalidArgumentException("Item count must be between 0 and " . self::MAX_COUNT . ", got {$count}");
        }

        $this->count = $count;
        return $this;
    }

    /**
     * Takes $count items off this stack and returns them as a new stack.
     */
    public function pop(int $count = 1) : self{
        if($count < 0 || $count > $this->count){
            throw new InvalidArgumentException("Cannot take {$count} items from a stack of {$this->count}");
        }

        $popped = clone $this;
        $popped->count = $count;
        $this->count -= $count;

        return $popped;
    }

    public function getMaxStackSize() : int{
        return ItemProperties::getMaxStackSize($this->id);
    }

    /**
     * How many uses the item has, 0 when it never wears out.
     */
    public function getMaxDurability() : int{
        return ItemProperties::getMaxDurability($this->id);
    }

    public function hasDurability() : bool{
        return $this->getMaxDurability() > 0;
    }

    /**
     * Uses taken off the item's durability, 0 for a new item.
     */
    public function getDamage() : int{
        return $this->damage;
    }

    public function setDamage(int $damage) : self{
        $this->damage = max(0, min($damage, $this->getMaxDurability()));
        return $this;
    }

    /**
     * True once a tool or armor piece has no uses left.
     */
    public function isBroken() : bool{
        return $this->hasDurability() && $this->damage >= $this->getMaxDurability();
    }

    public function hasCustomName() : bool{
        return $this->customName !== null;
    }

    public function getCustomName() : ?string{
        return $this->customName;
    }

    /**
     * Shown instead of the item's normal name, like renaming in an anvil. Null removes it.
     */
    public function setCustomName(?string $name) : self{
        if($name !== null){
            self::checkText($name, "Custom name");
        }

        $this->customName = $name === "" ? null : $name;
        return $this;
    }

    /**
     * @return list<string>
     */
    public function getLore() : array{
        return $this->lore;
    }

    /**
     * Lines shown under the item's name.
     *
     * @param list<string> $lines
     */
    public function setLore(array $lines) : self{
        if(count($lines) > self::MAX_LORE_LINES){
            throw new InvalidArgumentException("An item can have at most " . self::MAX_LORE_LINES . " lore lines");
        }

        foreach($lines as $line){
            self::checkText($line, "Lore line");
        }

        $this->lore = array_values($lines);
        return $this;
    }

    public function hasEnchantments() : bool{
        return $this->enchantments !== [];
    }

    /**
     * @return array<int, int> enchantment ID => level (use Enchantment::from() on the IDs)
     */
    public function getEnchantments() : array{
        return $this->enchantments;
    }

    public function hasEnchantment(Enchantment $enchantment) : bool{
        return isset($this->enchantments[$enchantment->value]);
    }

    /**
     * The enchantment's level, 0 when the item does not have it.
     */
    public function getEnchantmentLevel(Enchantment $enchantment) : int{
        return $this->enchantments[$enchantment->value] ?? 0;
    }

    /**
     * Adds an enchantment, or changes its level when the item already has it.
     */
    public function addEnchantment(Enchantment $enchantment, int $level) : self{
        if($level < 1 || $level > self::MAX_ENCHANTMENT_LEVEL){
            throw new InvalidArgumentException("Enchantment level must be between 1 and " . self::MAX_ENCHANTMENT_LEVEL . ", got {$level}");
        }

        $this->enchantments[$enchantment->value] = $level;
        ksort($this->enchantments);
        return $this;
    }

    public function removeEnchantment(Enchantment $enchantment) : self{
        unset($this->enchantments[$enchantment->value]);
        return $this;
    }

    public function removeEnchantments() : self{
        $this->enchantments = [];
        return $this;
    }

    /**
     * Item data this class does not handle itself, kept as it was loaded.
     */
    public function getExtraTags() : CompoundTag{
        return $this->extraTags;
    }

    /**
     * A copy as another item, keeping its name, enchantments and other data (a smithing upgrade).
     */
    public function withId(string $id) : self{
        $item = clone $this;
        $item->id = $id;
        $item->meta = 0;
        $item->setDamage($this->damage);
        return $item;
    }

    public function canStackWith(self $other) : bool{
        return $this->id === $other->id &&
            $this->meta === $other->meta &&
            $this->damage === $other->damage &&
            $this->customName === $other->customName &&
            $this->lore === $other->lore &&
            $this->enchantments === $other->enchantments &&
            self::sameTags($this->extraTags, $other->extraTags);
    }

    public function equals(self $other, bool $checkCount = true) : bool{
        return $this->canStackWith($other) && (!$checkCount || $this->count === $other->count);
    }

    public function __clone(){
        $this->extraTags = self::copyTags($this->extraTags);
    }

    public function __toString() : string{
        return "Item({$this->id}" . ($this->meta !== 0 ? ":{$this->meta}" : "") . " x{$this->count})";
    }

    private static function checkText(string $text, string $what) : void{
        if(strlen($text) > self::MAX_TEXT_LENGTH){
            throw new InvalidArgumentException("{$what} is longer than " . self::MAX_TEXT_LENGTH . " bytes");
        }
    }

    private static function sameTags(CompoundTag $a, CompoundTag $b) : bool{
        if($a->getValue() === [] && $b->getValue() === []){
            return true;
        }

        $serializer = NbtSerializer::littleEndian();

        return $serializer->write($a) === $serializer->write($b);
    }

    private static function copyTags(CompoundTag $tags) : CompoundTag{
        if($tags->getValue() === []){
            return new CompoundTag();
        }

        $serializer = NbtSerializer::littleEndian();

        return $serializer->read($serializer->write($tags));
    }
}