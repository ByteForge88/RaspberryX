<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\Server;

use raspberry\block\BlockIds;
use raspberry\block\BlockProperties;

use raspberry\item\Item;
use raspberry\item\ItemSerializer;

use raspberry\math\Vector3;

use raspberry\nbt\NbtException;
use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelEvent;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\world\World;

use function array_values;
use function max;
use function mt_getrandmax;
use function mt_rand;

class Campfire extends BlockEntity implements TickableBlockEntity{

    public const SLOTS = 4;

    /** food takes 30 seconds */
    public const COOK_TICKS = 600;

    /** how often the fire crackles, about once every three seconds */
    private const CRACKLE_CHANCE = 0.016;

    /** @var array<int, Item> slot => raw food */
    private array $items = [];

    /** @var array<int, int> slot => ticks it has cooked */
    private array $cookTimes = [];

    public function getSaveId() : string{
        return "Campfire";
    }

    public static function isCampfire(string $blockName) : bool{
        return $blockName === BlockIds::CAMPFIRE || $blockName === BlockIds::SOUL_CAMPFIRE;
    }

    /**
     * @return array<int, Item>
     */
    public function getItems() : array{
        return $this->items;
    }

    /**
     * What $item cooks into on this campfire, null when it cannot go on.
     */
    public function getCooked(World $world, Item $item) : ?Item{
        $block = $world->getBlock($this->getX(), $this->getY(), $this->getZ());

        return Server::getInstance()->getCraftingManager()->getFurnaceRecipe($item, $block->getName() === BlockIds::SOUL_CAMPFIRE ? "soul_campfire" : "campfire")?->getOutput();
    }

    /**
     * Puts one of $item in a free slot. False when the campfire is full or cannot cook it.
     */
    public function addItem(World $world, Item $item) : bool{
        if($this->getCooked($world, $item) === null){
            return false;
        }

        for($slot = 0; $slot < self::SLOTS; ++$slot){
            if(!isset($this->items[$slot])){
                [$x, $y, $z] = [$this->getX(), $this->getY(), $this->getZ()];
                $this->items[$slot] = (clone $item)->setCount(1);
                $this->cookTimes[$slot] = 0;
                $this->setChanged();

                $world->sendBlockEntity($this);
                $world->broadcastPacket($x, $z, LevelEventPacket::create(LevelEvent::SOUND_ADD_ITEM, $x + 0.5, $y + 0.5, $z + 0.5));
                return true;
            }
        }

        return false;
    }

    /**
     * Puts the fire out, the food on it stays but starts over.
     */
    public function extinguish(World $world) : bool{
        [$x, $y, $z] = [$this->getX(), $this->getY(), $this->getZ()];
        $block = $world->getBlock($x, $y, $z);

        if(!self::isCampfire($block->getName()) || $block->getProperty(BlockProperties::EXTINGUISHED) === true){
            return false;
        }

        foreach($this->cookTimes as $slot => $time){
            $this->cookTimes[$slot] = 0;
        }

        $this->setChanged();
        $world->setBlock($x, $y, $z, $block->withProperty(BlockProperties::EXTINGUISHED, true));
        $world->broadcastPacket($x, $z, LevelSoundEventPacket::create(LevelSoundEvent::EXTINGUISH_FIRE, $x + 0.5, $y + 0.5, $z + 0.5));

        return true;
    }

    public function tick(World $world, int $currentTick) : void{
        [$x, $y, $z] = [$this->getX(), $this->getY(), $this->getZ()];
        $block = $world->getBlock($x, $y, $z);

        if(!self::isCampfire($block->getName()) || $block->getProperty(BlockProperties::EXTINGUISHED) === true){
            return;
        }

        if(mt_rand() / mt_getrandmax() < self::CRACKLE_CHANCE){
            $world->broadcastPacket($x, $z, LevelSoundEventPacket::create(LevelSoundEvent::BLOCK_CAMPFIRE_CRACKLE, $x + 0.5, $y + 0.5, $z + 0.5));
        }

        if($this->items === []){
            return;
        }

        $done = false;

        foreach($this->items as $slot => $item){
            if(++$this->cookTimes[$slot] < self::COOK_TICKS){
                continue;
            }

            $cooked = $this->getCooked($world, $item);
            unset($this->items[$slot], $this->cookTimes[$slot]);
            $done = true;

            if($cooked !== null){
                $world->dropItem(new Vector3($x + 0.5, $y + 0.5, $z + 0.5), clone $cooked);
            }
        }

        $this->setChanged();

        if($done){
            $world->sendBlockEntity($this);
        }
    }

    public function readData(CompoundTag $nbt) : void{
        $this->items = [];
        $this->cookTimes = [];

        for($slot = 0; $slot < self::SLOTS; ++$slot){
            $tag = $nbt->getCompoundTag("Item" . ($slot + 1));

            try{
                $item = $tag === null ? null : ItemSerializer::deserialize($tag);
            }catch(NbtException){
                $item = null;
            }

            if($item !== null && !$item->isNull()){
                $this->items[$slot] = $item->setCount(1);
                $this->cookTimes[$slot] = max(0, $nbt->getInt("ItemTime" . ($slot + 1), 0) ?? 0);
            }
        }
    }

    public function writeData(CompoundTag $nbt) : void{
        foreach($this->items as $slot => $item){
            $nbt->setTag("Item" . ($slot + 1), ItemSerializer::serialize($item));
            $nbt->setInt("ItemTime" . ($slot + 1), $this->cookTimes[$slot]);
        }
    }

    public function onBreak(World $world) : array{
        $items = array_values($this->items);
        $this->items = [];
        $this->cookTimes = [];

        return $items;
    }
}