<?php

declare(strict_types=1);

namespace raspberry\entity\object;

use raspberry\inventory\Inventory;

use raspberry\network\mcpe\protocol\types\inventory\ContainerIds;

use raspberry\player\Player;

trait CargoHold{

    private Inventory $cargo;

    /** @var array<int, Player> */
    private array $rummagers = [];

    abstract protected function getWindowType() : int;

    public function getCargo() : Inventory{
        return $this->cargo;
    }

    public function rummage(Player $player) : bool{
        $manager = $player->getNetworkSession()->getInventoryManager();

        if($this->closed || $manager === null || $player->getWorld() !== $this->world){
            return false;
        }

        $position = $this->position;
        $manager->openWindow($this->getWindowType(), [[$position->getFloorX(), $position->getFloorY(), $position->getFloorZ()]], [ContainerIds::LEVEL_ENTITY], [$this->cargo], function() use ($player) : void{
            unset($this->rummagers[$player->getId()]);
        }, null, $this);

        $this->rummagers[$player->getId()] = $player;

        return true;
    }

    public function touchesBlock(int $x, int $y, int $z) : bool{
        $half = $this->getWidth() / 2;
        $position = $this->position;

        return !$this->closed &&
            $position->x - $half < $x + 1 &&
            $position->x + $half > $x &&
            $position->y < $y + 1 &&
            $position->y + $this->getHeight() > $y &&
            $position->z - $half < $z + 1 &&
            $position->z + $half > $z;
    }

    private function kickOutRummagers() : void{
        foreach($this->rummagers as $player){
            if($player->getNetworkSession()->getInventoryManager()?->getWindow()?->getActor() === $this){
                $player->getNetworkSession()->getInventoryManager()?->closeWindow(true);
            }
        }

        $this->rummagers = [];
    }

    private function spillCargo() : void{
        $this->kickOutRummagers();

        foreach($this->cargo->getContents() as $item){
            $this->world->dropItem($this->position->add(0, 0.5, 0), $item);
        }

        $this->cargo->clearAll();
    }
}