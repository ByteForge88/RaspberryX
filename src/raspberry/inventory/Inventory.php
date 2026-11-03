<?php

declare(strict_types=1);

namespace raspberry\inventory;

use raspberry\item\Item;

use InvalidArgumentException;

use function array_values;
use function min;
use function spl_object_id;

class Inventory{

    private int $size;

    /** @var array<int, Item> slot => item, empty slots are left out */
    private array $slots = [];

    /** @var array<int, InventoryListener> */
    private array $listeners = [];

    public function __construct(int $size){
        if($size < 1){
            throw new InvalidArgumentException("An inventory needs at least one slot");
        }

        $this->size = $size;
    }

    public function getSize() : int{
        return $this->size;
    }

    public function getItem(int $slot) : Item{
        $this->checkSlot($slot);

        return isset($this->slots[$slot]) ? clone $this->slots[$slot] : Item::air();
    }

    public function setItem(int $slot, Item $item) : void{
        $this->checkSlot($slot);

        $old = $this->slots[$slot] ?? Item::air();

        if($item->isNull()){
            unset($this->slots[$slot]);
        }else{
            $this->slots[$slot] = clone $item;
        }

        foreach($this->listeners as $listener){
            $listener->onSlotChange($this, $slot, $old);
        }
    }

    public function isSlotEmpty(int $slot) : bool{
        $this->checkSlot($slot);

        return !isset($this->slots[$slot]);
    }

    public function clear(int $slot) : void{
        $this->setItem($slot, Item::air());
    }

    /**
     * @return array<int, Item> slot => item, without empty slots unless $includeEmpty
     */
    public function getContents(bool $includeEmpty = false) : array{
        $contents = [];

        for($slot = 0; $slot < $this->size; ++$slot){
            if(isset($this->slots[$slot])){
                $contents[$slot] = clone $this->slots[$slot];
            }elseif($includeEmpty){
                $contents[$slot] = Item::air();
            }
        }

        return $contents;
    }

    /**
     * Replaces everything: slots missing from $items are emptied.
     *
     * @param array<int, Item> $items slot => item
     */
    public function setContents(array $items) : void{
        $slots = [];

        foreach($items as $slot => $item){
            $this->checkSlot($slot);

            if(!$item->isNull()){
                $slots[$slot] = clone $item;
            }
        }

        $this->slots = $slots;

        foreach($this->listeners as $listener){
            $listener->onContentChange($this);
        }
    }

    public function clearAll() : void{
        $this->setContents([]);
    }

    /**
     * The first empty slot, -1 when the inventory is full.
     */
    public function firstEmpty() : int{
        for($slot = 0; $slot < $this->size; ++$slot){
            if(!isset($this->slots[$slot])){
                return $slot;
            }
        }

        return -1;
    }

    /**
     * Adds items, filling stacks of the same item first and then empty slots.
     *
     * @return list<Item> what did not fit, empty when everything was added
     */
    public function addItem(Item ...$items) : array{
        $leftover = [];

        foreach($items as $item){
            $item = clone $item;

            foreach([false, true] as $emptySlots){
                for($slot = 0; $slot < $this->size && $item->getCount() > 0; ++$slot){
                    $existing = $this->slots[$slot] ?? null;

                    if($emptySlots ? $existing !== null : ($existing === null || !$existing->canStackWith($item))){
                        continue;
                    }

                    $count = $existing?->getCount() ?? 0;
                    $moved = min($item->getMaxStackSize() - $count, $item->getCount());

                    if($moved > 0){
                        $this->setItem($slot, (clone $item)->setCount($count + $moved));
                        $item->setCount($item->getCount() - $moved);
                    }
                }
            }

            if($item->getCount() > 0){
                $leftover[] = $item;
            }
        }

        return $leftover;
    }

    /**
     * Whether all of $item (its full count) would fit.
     */
    public function canAddItem(Item $item) : bool{
        $space = 0;

        for($slot = 0; $slot < $this->size; ++$slot){
            $existing = $this->slots[$slot] ?? null;

            if($existing === null){
                $space += $item->getMaxStackSize();
            }elseif($existing->canStackWith($item)){
                $space += $item->getMaxStackSize() - $existing->getCount();
            }

            if($space >= $item->getCount()){
                return true;
            }
        }

        return false;
    }

    /**
     * Removes items matching $filter, up to $max of them (-1 for all).
     *
     * @param callable(Item) : bool $filter
     *
     * @return int how many were removed
     */
    public function removeMatching(callable $filter, int $max = -1) : int{
        $removed = 0;

        for($slot = 0; $slot < $this->size && ($max < 0 || $removed < $max); ++$slot){
            $item = $this->slots[$slot] ?? null;

            if($item === null || !$filter($item)){
                continue;
            }

            $take = $max < 0 ? $item->getCount() : min($item->getCount(), $max - $removed);
            $this->setItem($slot, (clone $item)->setCount($item->getCount() - $take));
            $removed += $take;
        }

        return $removed;
    }

    /**
     * How many items match $filter.
     *
     * @param callable(Item) : bool $filter
     */
    public function countMatching(callable $filter) : int{
        $count = 0;

        foreach($this->slots as $item){
            if($filter($item)){
                $count += $item->getCount();
            }
        }

        return $count;
    }

    public function addListener(InventoryListener $listener) : void{
        $this->listeners[spl_object_id($listener)] = $listener;
    }

    public function removeListener(InventoryListener $listener) : void{
        unset($this->listeners[spl_object_id($listener)]);
    }

    /**
     * @return list<InventoryListener>
     */
    public function getListeners() : array{
        return array_values($this->listeners);
    }

    private function checkSlot(int $slot) : void{
        if($slot < 0 || $slot >= $this->size){
            throw new InvalidArgumentException("Slot {$slot} does not exist, the inventory has {$this->size} slots");
        }
    }
}