<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\block\BlockState;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\item\Item;

use raspberry\math\Vector3;

use raspberry\player\Player;

/**
 * A player used a bucket on a block.
 */
abstract class PlayerBucketEvent extends PlayerEvent implements Cancellable{
    use CancellableTrait;

    private BlockState $block;

    private Vector3 $position;

    private Item $bucket;

    private Item $result;

    public function __construct(Player $player, BlockState $block, Vector3 $position, Item $bucket, Item $result){
        $this->player = $player;
        $this->block = $block;
        $this->position = $position;
        $this->bucket = $bucket;
        $this->result = $result;
    }

    public function getBlock() : BlockState{
        return $this->block;
    }

    public function getPosition() : Vector3{
        return $this->position;
    }

    /**
     * The bucket in the player's hand.
     */
    public function getBucket() : Item{
        return $this->bucket;
    }

    /**
     * What the bucket turns into.
     */
    public function getResult() : Item{
        return $this->result;
    }

    public function setResult(Item $result) : void{
        $this->result = $result;
    }
}