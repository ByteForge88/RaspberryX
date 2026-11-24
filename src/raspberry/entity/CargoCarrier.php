<?php

declare(strict_types=1);

namespace raspberry\entity;

use raspberry\inventory\Inventory;

use raspberry\player\Player;

interface CargoCarrier{

    public function getCargo() : Inventory;

    public function rummage(Player $player) : bool;

    public function touchesBlock(int $x, int $y, int $z) : bool;

}