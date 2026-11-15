<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\entity\Entity;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\item\Item;

use raspberry\player\Player;

/**
 * A player used the item they hold on an entity: shearing, milking, feeding, a name tag.
 */
class PlayerInteractEntityEvent extends PlayerEvent implements Cancellable{
    use CancellableTrait;

    private Entity $entity;

    private Item $item;

    public function __construct(Player $player, Entity $entity, Item $item){
        $this->player = $player;
        $this->entity = $entity;
        $this->item = $item;
    }

    public function getEntity() : Entity{
        return $this->entity;
    }

    public function getItem() : Item{
        return $this->item;
    }
}