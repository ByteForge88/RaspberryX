<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\math\Vector3;

use raspberry\player\Player;

use raspberry\world\World;

/**
 * A player pressed respawn. Change where they come back with setPosition().
 */
class PlayerRespawnEvent extends PlayerEvent{

    private World $world;

    private Vector3 $position;

    public function __construct(Player $player, World $world, Vector3 $position){
        $this->player = $player;
        $this->world = $world;
        $this->position = $position;
    }

    public function getWorld() : World{
        return $this->world;
    }

    public function getPosition() : Vector3{
        return $this->position;
    }

    public function setPosition(Vector3 $position, ?World $world = null) : void{
        $this->position = $position;
        $this->world = $world ?? $this->world;
    }
}