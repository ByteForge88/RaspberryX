<?php

declare(strict_types=1);

namespace raspberry\entity;

use raspberry\math\Vector3;

use raspberry\player\Player;

use raspberry\world\World;

use LogicException;

/**
 * Anything that exists in a world and can move: players, mobs, dropped items, arrows...
 *
 * Every entity gets a runtime ID that is unique while the server runs. Bedrock also has a
 * "unique ID"; RaspberryX uses the same number for both.
 */
abstract class Entity{

    private static int $nextRuntimeId = 1;

    protected int $id;

    protected World $world;

    /** position of the entity's feet */
    protected Vector3 $position;

    protected float $yaw = 0.0;
    protected float $pitch = 0.0;

    protected bool $closed = false;

    /** @var array<int, Player> players this entity is currently shown to, by runtime ID */
    protected array $viewers = [];

    public function __construct(World $world, Vector3 $position){
        $this->id = self::nextRuntimeId();
        $this->world = $world;
        $this->position = $position;

        $world->addEntity($this);
    }

    public static function nextRuntimeId() : int{
        return self::$nextRuntimeId++;
    }

    abstract public function getNetworkTypeId() : string;

    public function getId() : int{
        return $this->id;
    }

    public function getWorld() : World{
        return $this->world;
    }

    public function getPosition() : Vector3{
        return $this->position;
    }

    /**
     * Distance from the feet to the eyes; 0 for entities without eyes.
     */
    public function getEyeHeight() : float{
        return 0.0;
    }

    public function getEyePosition() : Vector3{
        return $this->position->add(0, $this->getEyeHeight(), 0);
    }

    public function getYaw() : float{
        return $this->yaw;
    }

    public function getPitch() : float{
        return $this->pitch;
    }

    public function setRotation(float $yaw, float $pitch) : void{
        $this->yaw = $yaw;
        $this->pitch = $pitch;
    }

    /**
     * Updates the stored position. Does not tell any client about it.
     */
    public function setPosition(Vector3 $position) : void{
        if($this->closed){
            throw new LogicException("Cannot move a closed entity");
        }

        $this->position = $position;
    }

    /**
     * Moves and turns the entity and shows the movement to its viewers.
     *
     * @return bool false when nothing changed
     */
    public function move(Vector3 $position, float $yaw, float $pitch) : bool{
        if($this->position->equals($position) && $this->yaw === $yaw && $this->pitch === $pitch){
            return false;
        }

        $this->setPosition($position);
        $this->setRotation($yaw, $pitch);

        foreach($this->viewers as $viewer){
            $viewer->getNetworkSession()->moveEntity($this);
        }

        return true;
    }

    /**
     * Shows this entity to a player.
     */
    public function spawnTo(Player $player) : void{
        if($player === $this || $this->closed || isset($this->viewers[$player->getId()])){
            return;
        }

        $this->viewers[$player->getId()] = $player;
        $player->getNetworkSession()->spawnEntity($this);
    }

    /**
     * Hides this entity from a player.
     *
     * @param bool $send false when the player is leaving and does not need the packet
     */
    public function despawnFrom(Player $player, bool $send = true) : void{
        if(!isset($this->viewers[$player->getId()])){
            return;
        }

        unset($this->viewers[$player->getId()]);

        if($send){
            $player->getNetworkSession()->despawnEntity($this);
        }
    }

    public function despawnFromAll() : void{
        foreach($this->viewers as $viewer){
            $this->despawnFrom($viewer);
        }
    }

    /**
     * @return array<int, Player>
     */
    public function getViewers() : array{
        return $this->viewers;
    }

    public function isClosed() : bool{
        return $this->closed;
    }

    /**
     * Removes the entity from its world. It must not be used afterwards.
     */
    public function close() : void{
        if($this->closed){
            return;
        }

        $this->despawnFromAll();

        $this->closed = true;
        $this->world->removeEntity($this);
    }
}