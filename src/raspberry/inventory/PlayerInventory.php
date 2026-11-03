<?php

declare(strict_types=1);

namespace raspberry\inventory;

use raspberry\item\Item;

use Closure;
use InvalidArgumentException;

class PlayerInventory extends Inventory{

    public const SIZE = 36;

    public const HOTBAR_SIZE = 9;

    private int $heldSlot = 0;

    /** @var list<Closure(int) : void> */
    private array $heldSlotListeners = [];

    public function __construct(){
        parent::__construct(self::SIZE);
    }

    public function getHeldSlot() : int{
        return $this->heldSlot;
    }

    public function setHeldSlot(int $slot) : void{
        if($slot < 0 || $slot >= self::HOTBAR_SIZE){
            throw new InvalidArgumentException("Hotbar slot must be between 0 and " . (self::HOTBAR_SIZE - 1) . ", got {$slot}");
        }

        if($slot === $this->heldSlot){
            return;
        }

        $this->heldSlot = $slot;

        foreach($this->heldSlotListeners as $listener){
            $listener($slot);
        }
    }

    public function getItemInHand() : Item{
        return $this->getItem($this->heldSlot);
    }

    public function setItemInHand(Item $item) : void{
        $this->setItem($this->heldSlot, $item);
    }

    /**
     * Called with the new slot whenever another hotbar slot is selected.
     *
     * @param Closure(int) : void $listener
     */
    public function addHeldSlotListener(Closure $listener) : void{
        $this->heldSlotListeners[] = $listener;
    }
}