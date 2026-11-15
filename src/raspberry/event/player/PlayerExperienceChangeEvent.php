<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\player\Player;

/**
 * A player is about to get experience points.
 */
class PlayerExperienceChangeEvent extends PlayerEvent implements Cancellable{
    use CancellableTrait;

    private int $amount;

    public function __construct(Player $player, int $amount){
        $this->player = $player;
        $this->amount = $amount;
    }

    public function getAmount() : int{
        return $this->amount;
    }

    public function setAmount(int $amount) : void{
        $this->amount = $amount;
    }
}