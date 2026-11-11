<?php

declare(strict_types=1);

namespace raspberry\event;

use raspberry\plugin\PluginBase;
use raspberry\plugin\PluginException;

use raspberry\utils\Logger;

use Closure;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

use function get_parent_class;
use function is_subclass_of;
use function usort;

class EventManager{

    private Logger $logger;

    /** @var array<class-string<Event>, list<RegisteredListener>> */
    private array $listeners = [];

    /** @var array<class-string<Event>, list<RegisteredListener>> handlers for a class and its parents, in order */
    private array $cache = [];

    public function __construct(Logger $logger){
        $this->logger = $logger;
    }

    /**
     * @param class-string<Event> $event
     * @param Closure(Event) : void $handler
     */
    public function register(string $event, Closure $handler, PluginBase $plugin, EventPriority $priority = EventPriority::NORMAL, bool $handleCancelled = false) : RegisteredListener{
        if(!is_subclass_of($event, Event::class)){
            throw new PluginException("{$event} is not an event");
        }

        $listener = new RegisteredListener($event, $handler, $plugin, $priority, $handleCancelled);
        $this->listeners[$event][] = $listener;
        $this->cache = [];

        return $listener;
    }

    /**
     * @return list<RegisteredListener>
     */
    public function registerEvents(Listener $listener, PluginBase $plugin) : array{
        $registered = [];

        foreach((new ReflectionClass($listener))->getMethods(ReflectionMethod::IS_PUBLIC) as $method){
            if($method->isStatic() || $method->getNumberOfParameters() !== 1){
                continue;
            }

            $type = $method->getParameters()[0]->getType();

            if(!$type instanceof ReflectionNamedType || $type->isBuiltin() || !is_subclass_of($type->getName(), Event::class)){
                continue;
            }

            $priority = ($method->getAttributes(Priority::class)[0] ?? null)?->newInstance()->priority ?? EventPriority::NORMAL;
            $handleCancelled = $method->getAttributes(HandleCancelled::class) !== [];

            /** @var class-string<Event> $event */
            $event = $type->getName();
            $registered[] = $this->register($event, $method->getClosure($listener), $plugin, $priority, $handleCancelled);
        }

        return $registered;
    }

    public function unregister(RegisteredListener $listener) : void{
        foreach($this->listeners[$listener->getEvent()] ?? [] as $index => $registered){
            if($registered === $listener){
                unset($this->listeners[$listener->getEvent()][$index]);
            }
        }

        $this->cache = [];
    }

    public function unregisterAll(PluginBase $plugin) : void{
        foreach($this->listeners as $event => $listeners){
            foreach($listeners as $index => $listener){
                if($listener->getPlugin() === $plugin){
                    unset($this->listeners[$event][$index]);
                }
            }
        }

        $this->cache = [];
    }

    public function call(Event $event) : void{
        foreach($this->getHandlers($event::class) as $listener){
            if(!$listener->getPlugin()->isEnabled()){
                continue;
            }

            if($event instanceof Cancellable && $event->isCancelled() && !$listener->handlesCancelled()){
                continue;
            }

            try{
                ($listener->getHandler())($event);
            }catch(Throwable $exception){
                $this->logger->error("Could not pass {$event->getEventName()} to {$listener->getPlugin()->getName()}: " . $exception::class . ": " . $exception->getMessage());
                $this->logger->debug($exception->getTraceAsString());
            }
        }
    }

    /**
     * @param class-string<Event> $class
     *
     * @return list<RegisteredListener>
     */
    private function getHandlers(string $class) : array{
        if(isset($this->cache[$class])){
            return $this->cache[$class];
        }

        $handlers = [];

        for($current = $class; $current !== false; $current = get_parent_class($current)){
            foreach($this->listeners[$current] ?? [] as $listener){
                $handlers[] = $listener;
            }
        }

        usort($handlers, fn(RegisteredListener $a, RegisteredListener $b) => $a->getPriority()->value <=> $b->getPriority()->value);
        return $this->cache[$class] = $handlers;
    }
}