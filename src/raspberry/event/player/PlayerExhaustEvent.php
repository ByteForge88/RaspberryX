<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\player\Player;

/**
 * A player gets hungrier: from running, jumping, swimming, breaking blocks, getting hurt. Four
 * exhaustion is one hunger point.
 */
class PlayerExhaustEvent extends PlayerEvent implements Cancellable{
    use CancellableTrait;

    private float $amount;

    public function __construct(Player $player, float $amount){
        $this->player = $player;
        $this->amount = $amount;
    }

    public function getAmount() : float{
        return $this->amount;
    }

    public function setAmount(float $amount) : void{
        $this->amount = $amount;
    }
}