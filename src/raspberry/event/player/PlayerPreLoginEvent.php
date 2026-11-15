<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\event\Event;

use raspberry\network\mcpe\auth\AuthenticatedLogin;

/**
 * Someone signed in with Microsoft and is about to join, before anything of theirs is loaded.
 * Keep them out with setKickMessage(). When the server is full the kick message is already set,
 * clear it with setKickMessage(null) to let them in anyway.
 */
class PlayerPreLoginEvent extends Event{

    private AuthenticatedLogin $identity;

    private ?string $kickMessage;

    public function __construct(AuthenticatedLogin $identity, ?string $kickMessage){
        $this->identity = $identity;
        $this->kickMessage = $kickMessage;
    }

    public function getIdentity() : AuthenticatedLogin{
        return $this->identity;
    }

    public function getName() : string{
        return $this->identity->username;
    }

    public function getXuid() : string{
        return $this->identity->xuid;
    }

    public function isAllowed() : bool{
        return $this->kickMessage === null;
    }

    /**
     * What they see on the disconnect screen, null when they may join.
     */
    public function getKickMessage() : ?string{
        return $this->kickMessage;
    }

    public function setKickMessage(?string $kickMessage) : void{
        $this->kickMessage = $kickMessage;
    }
}