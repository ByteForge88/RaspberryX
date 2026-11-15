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
 * A furnace, blast furnace or smoker finished cooking one item. Change what comes out with
 * setResult(). Cancelling it keeps the input and starts over.
 */
class FurnaceSmeltEvent extends Event implements Cancellable{
    use CancellableTrait;

    private World $world;

    private Furnace $furnace;

    private Item $input;

    private Item $result;

    public function __construct(World $world, Furnace $furnace, Item $input, Item $result){
        $this->world = $world;
        $this->furnace = $furnace;
        $this->input = $input;
        $this->result = $result;
    }

    public function getWorld() : World{
        return $this->world;
    }

    public function getFurnace() : Furnace{
        return $this->furnace;
    }

    public function getInput() : Item{
        return $this->input;
    }

    public function getResult() : Item{
        return $this->result;
    }

    public function setResult(Item $result) : void{
        $this->result = $result;
    }
}