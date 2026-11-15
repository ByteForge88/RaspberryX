<?php

declare(strict_types=1);

namespace raspberry\event;

use raspberry\Server;

use function strrchr;
use function substr;

/**
 * Something that happened which plugins can listen to. Events that can be stopped implement
 * Cancellable.
 */
abstract class Event{

    public function getEventName() : string{
        return substr((string) strrchr("\\" . static::class, "\\"), 1);
    }

    /**
     * Hands the event to every listener, in order of priority.
     */
    public function call() : void{
        Server::getInstance()->getPluginManager()->getEventManager()->call($this);
    }

    /**
     * Whether any plugin listens to this event.
     */
    public static function hasHandlers() : bool{
        return Server::getInstance()->getPluginManager()->getEventManager()->hasHandlers(static::class);
    }
}