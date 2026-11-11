<?php

declare(strict_types=1);

namespace raspberry\event\block;

use raspberry\block\BlockState;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;
use raspberry\event\Event;

use raspberry\item\Item;

use raspberry\math\Vector3;

use raspberry\player\Player;

/**
 * A player is about to break a block. Cancelling it puts the block back for the player.
 */
class BlockBreakEvent extends Event implements Cancellable{
    use CancellableTrait;

    private Player $player;

    private BlockState $block;

    private Vector3 $position;

    /** @var list<Item> */
    private array $drops;

    /**
     * @param list<Item> $drops
     */
    public function __construct(Player $player, BlockState $block, Vector3 $position, array $drops){
        $this->player = $player;
        $this->block = $block;
        $this->position = $position;
        $this->drops = $drops;
    }

    public function getPlayer() : Player{
        return $this->player;
    }

    public function getBlock() : BlockState{
        return $this->block;
    }

    public function getPosition() : Vector3{
        return $this->position;
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
}