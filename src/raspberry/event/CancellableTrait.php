<?php

declare(strict_types=1);

namespace raspberry\event;

trait CancellableTrait{

    private bool $cancelled = false;

    public function isCancelled() : bool{
        return $this->cancelled;
    }

    public function cancel() : void{
        $this->cancelled = true;
    }

    public function uncancel() : void{
        $this->cancelled = false;
    }
}