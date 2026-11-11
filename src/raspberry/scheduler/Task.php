<?php

declare(strict_types=1);

namespace raspberry\scheduler;

abstract class Task{

    private ?TaskHandler $handler = null;

    abstract public function onRun() : void;

    /**
     * Called when the task is cancelled, also when the plugin is disabled.
     */
    public function onCancel() : void{
    }

    public function getHandler() : ?TaskHandler{
        return $this->handler;
    }

    /**
     * @internal
     */
    public function setHandler(?TaskHandler $handler) : void{
        $this->handler = $handler;
    }
}