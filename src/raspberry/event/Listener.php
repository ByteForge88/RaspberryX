<?php

declare(strict_types=1);

namespace raspberry\event;

/**
 * A class whose public methods handle events. Every public method that takes one event as its
 * only parameter becomes a handler when the listener is registered:
 *
 *   #[Priority(EventPriority::HIGH)]
 *   public function onChat(PlayerChatEvent $event) : void{ ... }
 *
 * Add #[HandleCancelled] to also get events another listener cancelled.
 */
interface Listener{

}