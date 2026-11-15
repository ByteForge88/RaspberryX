<?php

declare(strict_types=1);

namespace raspberry\event\block;

use raspberry\block\entity\Sign;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;
use raspberry\event\player\PlayerEvent;

use raspberry\math\Vector3;

use raspberry\player\Player;

/**
 * A player finished writing on a sign. Change the text with setText(), lines are split by \n.
 * Cancelling it keeps the old text.
 */
class SignChangeEvent extends PlayerEvent implements Cancellable{
    use CancellableTrait;

    private Sign $sign;

    private bool $front;

    private string $text;

    public function __construct(Player $player, Sign $sign, bool $front, string $text){
        $this->player = $player;
        $this->sign = $sign;
        $this->front = $front;
        $this->text = $text;
    }

    public function getSign() : Sign{
        return $this->sign;
    }

    public function getPosition() : Vector3{
        return new Vector3($this->sign->getX(), $this->sign->getY(), $this->sign->getZ());
    }

    /**
     * Whether it is the front of the sign, or the back.
     */
    public function isFront() : bool{
        return $this->front;
    }

    /**
     * The text before the change.
     */
    public function getOldText() : string{
        return $this->sign->getText($this->front);
    }

    public function getText() : string{
        return $this->text;
    }

    public function setText(string $text) : void{
        $this->text = $text;
    }
}