<?php

declare(strict_types=1);

namespace raspberry\scheduler;

use Closure;

/**
 * A task from a function: new ClosureTask(fn() => $this->getLogger()->info("hi")).
 */
class ClosureTask extends Task{

    private Closure $closure;

    /**
     * @param Closure() : void $closure
     */
    public function __construct(Closure $closure){
        $this->closure = $closure;
    }

    public function onRun() : void{
        ($this->closure)();
    }
}