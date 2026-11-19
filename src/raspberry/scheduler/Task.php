<?php

declare(strict_types=1);

namespace raspberry\scheduler;

abstract class Task{

    public ?TaskHandler $handler = null{
        get{
            return $this->handler;
        }

        set{
            $this->handler = $value;
        }
    }

    abstract public function onRun() : void;

    /**
     * Called when the task is cancelled, also when the plugin is disabled.
     */
    public function onCancel() : void{

    }
}