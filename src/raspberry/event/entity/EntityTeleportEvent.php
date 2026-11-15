<?php

declare(strict_types=1);

namespace raspberry\event\entity;

use raspberry\entity\Entity;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\math\Vector3;

use raspberry\world\World;

/**
 * An entity or player is about to be teleported: a command, an ender pearl, a chorus fruit, a
 * plugin, respawning. Change the destination with setTo().
 */
class EntityTeleportEvent extends EntityEvent implements Cancellable{
    use CancellableTrait;

    private World $fromWorld;

    private Vector3 $from;

    private World $toWorld;

    private Vector3 $to;

    public function __construct(Entity $entity, World $fromWorld, Vector3 $from, World $toWorld, Vector3 $to){
        $this->entity = $entity;
        $this->fromWorld = $fromWorld;
        $this->from = $from;
        $this->toWorld = $toWorld;
        $this->to = $to;
    }

    public function getFrom() : Vector3{
        return $this->from;
    }

    public function getFromWorld() : World{
        return $this->fromWorld;
    }

    public function getTo() : Vector3{
        return $this->to;
    }

    public function getToWorld() : World{
        return $this->toWorld;
    }

    public function setTo(Vector3 $to, ?World $world = null) : void{
        $this->to = $to;
        $this->toWorld = $world ?? $this->toWorld;
    }
}