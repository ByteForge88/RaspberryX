<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\block\Dispensing;

use raspberry\item\Item;

use raspberry\math\Facing;

use raspberry\world\World;

class Dropper extends Dispenser{

    public function getSaveId() : string{
        return "Dropper";
    }

    protected function dispense(World $world, Item $item, int $facing) : ?Item{
        [$dx, $dy, $dz] = Facing::offset($facing);
        $target = Hopper::getContainer($world, $this->getX() + $dx, $this->getY() + $dy, $this->getZ() + $dz);

        //into a container it goes quietly, and stays when the container is full
        if($target !== null){
            return Hopper::insertOne($world, $target, $item, $facing === Facing::DOWN) ? $item->setCount($item->getCount() - 1) : $item;
        }

        Dispensing::drop($world, $this->getX(), $this->getY(), $this->getZ(), (clone $item)->setCount(1), $facing);
        return $item->setCount($item->getCount() - 1);
    }
}