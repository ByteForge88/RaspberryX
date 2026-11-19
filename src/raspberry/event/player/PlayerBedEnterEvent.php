<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\math\Vector3;

use raspberry\player\Player;

/**
 * A player is lying down in a bed. The bed, the time and the monsters around were already
 * checked. Cancelling it keeps them up, the spawn point is still set.
 */
class PlayerBedEnterEvent extends PlayerEvent implements Cancellable{
    use CancellableTrait;

    private Vector3 $bed;

    public function __construct(Player $player, Vector3 $bed){
        $this->player = $player;
        $this->bed = $bed;
    }

    /**
     * Where the head of the bed is.
     */
    public function getBed() : Vector3{
        return $this->bed;
    }
}