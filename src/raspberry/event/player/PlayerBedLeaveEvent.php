<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\math\Vector3;

use raspberry\player\Player;

/**
 * A player got out of bed: they woke up, the night was skipped, they were hurt or the bed broke.
 */
class PlayerBedLeaveEvent extends PlayerEvent{

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