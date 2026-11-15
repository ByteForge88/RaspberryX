<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\entity\object\ItemEntity;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\item\Item;

use raspberry\player\Player;

/**
 * A player is about to pick up an item from the ground.
 */
class PlayerPickupItemEvent extends PlayerEvent implements Cancellable{
    use CancellableTrait;

    private ItemEntity $entity;

    public function __construct(Player $player, ItemEntity $entity){
        $this->player = $player;
        $this->entity = $entity;
    }

    public function getEntity() : ItemEntity{
        return $this->entity;
    }

    public function getItem() : Item{
        return $this->entity->getItem();
    }
}