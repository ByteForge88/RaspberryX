<?php

declare(strict_types=1);

namespace raspberry\event\inventory;

use raspberry\block\entity\Furnace;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;
use raspberry\event\Event;

use raspberry\item\Item;

use raspberry\world\World;

/**
 * A furnace, blast furnace or smoker is about to burn a piece of fuel. Cancelling it keeps the
 * fuel and the furnace stays out.
 */
class FurnaceBurnEvent extends Event implements Cancellable{
    use CancellableTrait;

    private World $world;

    private Furnace $furnace;

    private Item $fuel;

    private int $burnTicks;

    public function __construct(World $world, Furnace $furnace, Item $fuel, int $burnTicks){
        $this->world = $world;
        $this->furnace = $furnace;
        $this->fuel = $fuel;
        $this->burnTicks = $burnTicks;
    }

    public function getWorld() : World{
        return $this->world;
    }

    public function getFurnace() : Furnace{
        return $this->furnace;
    }

    public function getFuel() : Item{
        return $this->fuel;
    }

    /**
     * How long the fuel burns, in ticks.
     */
    public function getBurnTicks() : int{
        return $this->burnTicks;
    }

    public function setBurnTicks(int $burnTicks) : void{
        $this->burnTicks = $burnTicks;
    }
}