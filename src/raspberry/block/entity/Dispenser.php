<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\block\Dispensing;
use raspberry\block\BlockProperties;

use raspberry\item\Item;

use raspberry\math\Facing;

use raspberry\world\World;

use function mt_rand;

class Dispenser extends ContainerBlockEntity{

    public const SIZE = 9;

    public function __construct(int $x, int $y, int $z){
        parent::__construct($x, $y, $z, self::SIZE);
    }

    public function getSaveId() : string{
        return "Dispenser";
    }

    /**
     * Fires one item from a random slot, called when the block gets a redstone pulse.
     */
    public function activate(World $world) : void{
        $slot = $this->getRandomSlot();

        if($slot === null){
            Dispensing::playFailSound($world, $this->getX(), $this->getY(), $this->getZ());
            return;
        }

        $block = $world->getBlock($this->getX(), $this->getY(), $this->getZ());
        $facing = $block->hasProperty(BlockProperties::FACING_DIRECTION) ? (int) $block->getProperty(BlockProperties::FACING_DIRECTION) : Facing::DOWN;
        $left = $this->dispense($world, clone $this->getInventory()->getItem($slot), $facing);

        if($left === null){
            Dispensing::playFailSound($world, $this->getX(), $this->getY(), $this->getZ());
            return;
        }

        $this->getInventory()->setItem($slot, $left);
    }

    /**
     * What is left of the stack after firing one, null when nothing could be done with it.
     */
    protected function dispense(World $world, Item $item, int $facing) : ?Item{
        return Dispensing::dispense($world, $this, $item, $facing);
    }

    private function getRandomSlot() : ?int{
        $picked = null;
        $seen = 0;

        //every filled slot gets the same chance
        foreach($this->getInventory()->getContents() as $slot => $item){
            if(!$item->isNull() && mt_rand(0, $seen++) === 0){
                $picked = $slot;
            }
        }

        return $picked;
    }
}