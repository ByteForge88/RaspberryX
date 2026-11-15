<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\player\GameMode;
use raspberry\player\Player;

/**
 * A player's game mode is about to change.
 */
class PlayerGameModeChangeEvent extends PlayerEvent implements Cancellable{
    use CancellableTrait;

    private GameMode $gameMode;

    public function __construct(Player $player, GameMode $gameMode){
        $this->player = $player;
        $this->gameMode = $gameMode;
    }

    public function getOldGameMode() : GameMode{
        return $this->player->getGameMode();
    }

    public function getNewGameMode() : GameMode{
        return $this->gameMode;
    }
}