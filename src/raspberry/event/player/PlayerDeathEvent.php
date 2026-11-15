<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\entity\damage\DamageSource;

use raspberry\item\Item;

use raspberry\lang\Translation;

use raspberry\player\Player;

/**
 * A player died. Change what they drop, whether they keep their inventory and experience, and the
 * death message everyone sees.
 */
class PlayerDeathEvent extends PlayerEvent{

    private ?DamageSource $cause;

    private Translation|string|null $deathMessage;

    /** @var list<Item> */
    private array $drops;

    private bool $keepInventory;

    /**
     * @param list<Item> $drops
     */
    public function __construct(Player $player, ?DamageSource $cause, Translation|string|null $deathMessage, array $drops = [], bool $keepInventory = false){
        $this->player = $player;
        $this->cause = $cause;
        $this->deathMessage = $deathMessage;
        $this->drops = $drops;
        $this->keepInventory = $keepInventory;
    }

    public function getCause() : ?DamageSource{
        return $this->cause;
    }

    public function getDeathMessage() : Translation|string|null{
        return $this->deathMessage;
    }

    public function setDeathMessage(Translation|string|null $deathMessage) : void{
        $this->deathMessage = $deathMessage;
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

    public function getKeepInventory() : bool{
        return $this->keepInventory;
    }

    /**
     * Keeping the inventory keeps the experience too, and nothing drops.
     */
    public function setKeepInventory(bool $keepInventory) : void{
        $this->keepInventory = $keepInventory;

        if($keepInventory){
            $this->drops = [];
        }
    }
}