<?php

declare(strict_types=1);

namespace raspberry\event\inventory;

use raspberry\event\player\PlayerEvent;

use raspberry\network\mcpe\ContainerWindow;

use raspberry\player\Player;

/**
 * A player's screen closed: they closed it, walked away, or the block broke.
 */
class InventoryCloseEvent extends PlayerEvent{

    private ContainerWindow $window;

    public function __construct(Player $player, ContainerWindow $window){
        $this->player = $player;
        $this->window = $window;
    }

    public function getWindow() : ContainerWindow{
        return $this->window;
    }
}