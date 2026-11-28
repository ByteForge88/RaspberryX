<?php

declare(strict_types=1);

namespace raspberry\utils;

use raspberry\plugin\PluginBase;

use Closure;
use LogicException;
use ReflectionClass;
use Throwable;

use function is_subclass_of;

trait SingletonTrait{

    /** @var array<class-string<self>, self> */
    private static array $instances = [];

    public static function getInstance() : static{
        $class = static::class;

        if(isset(self::$instances[$class])){
            return self::$instances[$class];
        }

        if(is_subclass_of($class, PluginBase::class)){
            throw new LogicException("Plugin {$class} has not been loaded yet");
        }

        $constructor = (new ReflectionClass($class))->getConstructor();

        if($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0){
            throw new LogicException("{$class} must register an instance because its constructor requires arguments");
        }

        return self::$instances[$class] = new static();
    }

    protected static function setInstance(self $instance) : void{
        self::$instances[$instance::class] = $instance;
    }

    /**
     * @internal
     */
    protected function loadWithSingleton(Closure $load) : void{
        $class = static::class;
        $previous = self::$instances[$class] ?? null;
        self::setInstance($this);

        try{
            $load();
        }catch(Throwable $exception){
            if($previous === null){
                unset(self::$instances[$class]);
            }else{
                self::$instances[$class] = $previous;
            }

            throw $exception;
        }
    }
}