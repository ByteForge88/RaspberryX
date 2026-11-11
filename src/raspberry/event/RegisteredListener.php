<?php

declare(strict_types=1);

namespace raspberry\event;

use raspberry\plugin\PluginBase;

use Closure;

class RegisteredListener{

    /** @var class-string<Event> */
    private string $event;

    private Closure $handler;

    private PluginBase $plugin;

    private EventPriority $priority;

    private bool $handleCancelled;

    /**
     * @param class-string<Event> $event
     */
    public function __construct(string $event, Closure $handler, PluginBase $plugin, EventPriority $priority, bool $handleCancelled){
        $this->event = $event;
        $this->handler = $handler;
        $this->plugin = $plugin;
        $this->priority = $priority;
        $this->handleCancelled = $handleCancelled;
    }

    /**
     * @return class-string<Event>
     */
    public function getEvent() : string{
        return $this->event;
    }

    public function getHandler() : Closure{
        return $this->handler;
    }

    public function getPlugin() : PluginBase{
        return $this->plugin;
    }

    public function getPriority() : EventPriority{
        return $this->priority;
    }

    public function handlesCancelled() : bool{
        return $this->handleCancelled;
    }
}