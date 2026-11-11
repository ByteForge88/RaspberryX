<?php

declare(strict_types=1);

namespace raspberry\event;

/**
 * Allows you to cancel events
 */
interface Cancellable{

    public function isCancelled() : bool;

    public function cancel() : void;

    public function uncancel() : void;

}