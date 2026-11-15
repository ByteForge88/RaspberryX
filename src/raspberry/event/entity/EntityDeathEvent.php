<?php

declare(strict_types=1);

namespace raspberry\event\entity;

use raspberry\entity\Living;

use raspberry\item\Item;

use raspberry\player\Player;

/**
 * A mob died. Change what it drops and the experience its killer gets. Players have
 * PlayerDeathEvent instead.
 */
class EntityDeathEvent extends EntityEvent{

    /** @var list<Item> */
    private array $drops;

    private int $xp;

    private ?Player $killer;

    /**
     * @param list<Item> $drops
     */
    public function __construct(Living $entity, array $drops, int $xp, ?Player $killer){
        $this->entity = $entity;
        $this->drops = $drops;
        $this->xp = $xp;
        $this->killer = $killer;
    }

    public function getEntity() : Living{
        /** @var Living */
        return $this->entity;
    }

    /**
     * The player whose kill it is, null when no player hit it in its last seconds.
     */
    public function getKiller() : ?Player{
        return $this->killer;
    }

    /**
     * @return list<Item>
     */
    public function getDrops() : array{
        return $this->drops;
    }

    /**
     * @param list<Item> $drops
     */
    public function setDrops(array $drops) : void{
        $this->drops = $drops;
    }

    public function getXp() : int{
        return $this->xp;
    }

    public function setXp(int $xp) : void{
        $this->xp = $xp;
    }
}