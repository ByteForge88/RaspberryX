<?php

declare(strict_types=1);

namespace raspberry\item;

use raspberry\item\enchantment\Enchantment;

use raspberry\nbt\NbtException;
use raspberry\nbt\NbtSerializer;
use raspberry\nbt\tag\CompoundTag;
use raspberry\nbt\tag\ListTag;
use raspberry\nbt\tag\StringTag;
use raspberry\nbt\tag\Tag;

use InvalidArgumentException;

use function array_map;

class ItemSerializer{

    private function __construct(){
    }

    public static function serialize(Item $item, ?int $slot = null) : CompoundTag{
        $count = $item->getCount();

        $nbt = (new CompoundTag())
            ->setString("Name", $item->getId())
            ->setByte("Count", $count > 127 ? $count - 256 : $count)
            ->setShort("Damage", $item->getMeta())
            ->setByte("WasPickedUp", 0);

        if($slot !== null){
            $nbt->setByte("Slot", $slot);
        }

        $tag = self::serializeTag($item);

        if($tag !== null){
            $nbt->setTag("tag", $tag);
        }

        return $nbt;
    }

    /**
     * The item's own data (the "tag" compound): durability, custom name, lore, enchantments and
     * extra tags. Null when the item has none. Also sent to clients as the item's NBT.
     */
    public static function serializeTag(Item $item) : ?CompoundTag{
        $tag = self::copy($item->getExtraTags());

        if($item->getDamage() > 0){
            $tag->setInt("Damage", $item->getDamage());
        }

        if($item->hasCustomName() || $item->getLore() !== []){
            $display = new CompoundTag();

            if($item->hasCustomName()){
                $display->setString("Name", (string) $item->getCustomName());
            }

            if($item->getLore() !== []){
                $display->setTag("Lore", new ListTag(array_map(fn(string $line) => new StringTag($line), $item->getLore()), Tag::TAG_STRING));
            }

            $tag->setTag("display", $display);
        }

        if($item->hasEnchantments()){
            $enchantments = new ListTag([], Tag::TAG_COMPOUND);

            foreach($item->getEnchantments() as $id => $level){
                $enchantments->push((new CompoundTag())->setShort("id", $id)->setShort("lvl", $level));
            }

            $tag->setTag("ench", $enchantments);
        }

        return $tag->getValue() === [] ? null : $tag;
    }

    /**
     * @throws NbtException
     */
    public static function deserialize(CompoundTag $nbt) : Item{
        $id = $nbt->getString("Name");

        if($id === null){
            throw new NbtException("Item has no ID");
        }

        if($id === ""){
            return Item::air();
        }

        try{
            $item = new Item($id, ($nbt->getByte("Count", 1) ?? 1) & 0xff, $nbt->getShort("Damage", 0) ?? 0);
            $tag = $nbt->getCompoundTag("tag");

            if($tag !== null){
                self::readTag($item, self::copy($tag));
            }
        }catch(InvalidArgumentException $exception){
            throw new NbtException("Invalid item {$id}: " . $exception->getMessage(), 0, $exception);
        }

        return $item;
    }

    private static function readTag(Item $item, CompoundTag $tag) : void{
        $item->setDamage($tag->getInt("Damage", 0) ?? 0);
        $tag->removeTag("Damage");

        $display = $tag->getCompoundTag("display");

        if($display !== null){
            $item->setCustomName($display->getString("Name"));

            $lore = $display->getListTag("Lore");

            if($lore !== null){
                $lines = [];

                foreach($lore->getValue() as $line){
                    if(!$line instanceof StringTag){
                        throw new NbtException("Lore lines must be strings");
                    }

                    $lines[] = $line->getValue();
                }

                $item->setLore($lines);
            }

            $display->removeTag("Name");
            $display->removeTag("Lore");

            if($display->getValue() === []){
                $tag->removeTag("display");
            }
        }

        $enchantments = $tag->getListTag("ench");

        if($enchantments !== null){
            foreach($enchantments->getValue() as $entry){
                if(!$entry instanceof CompoundTag){
                    throw new NbtException("Enchantments must be compounds");
                }

                $enchantment = Enchantment::tryFrom($entry->getShort("id", -1) ?? -1);
                $level = $entry->getShort("lvl", 1) ?? 1;

                if($enchantment !== null && $level >= 1){
                    $item->addEnchantment($enchantment, $level);
                }
            }

            $tag->removeTag("ench");
        }

        foreach($tag->getValue() as $name => $value){
            $item->getExtraTags()->setTag((string) $name, $value);
        }
    }

    private static function copy(CompoundTag $tag) : CompoundTag{
        if($tag->getValue() === []){
            return new CompoundTag();
        }

        $serializer = NbtSerializer::littleEndian();

        return $serializer->read($serializer->write($tag));
    }
}