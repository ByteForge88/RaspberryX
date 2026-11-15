<?php

namespace raspberry\event\player;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\math\Vector3;

use raspberry\player\Player;

/**
 * A player moved or turned. Cancelling it puts them back where they were, setTo() moves them
 * somewhere else instead. It happens up to 20 times a second for every player, keep listeners
 * quick.
 */
class PlayerMoveEvent extends PlayerEvent implements Cancellable{
    use CancellableTrait;

    private Vector3 $from;

    private Vector3 $to;

    private float $yaw;

    private float $pitch;

    private bool $changed = false;

    public function __construct(Player $player, Vector3 $from, Vector3 $to, float $yaw, float $pitch){
        $this->player = $player;
        $this->from = $from;
        $this->to = $to;
        $this->yaw = $yaw;
        $this->pitch = $pitch;
    }

    public function getFrom() : Vector3{
        return $this->from;
    }

    public function getTo() : Vector3{
        return $this->to;
    }

    /**
     * Moves the player here instead of where they went.
     */
    public function setTo(Vector3 $to) : void{
        $this->to = $to;
        $this->changed = true;
    }

    public function getYaw() : float{
        return $this->yaw;
    }

    public function getPitch() : float{
        return $this->pitch;
    }

    /**
     * Whether a listener moved the player somewhere else with setTo().
     */
    public function isChanged() : bool{
        return $this->changed;
    }
}