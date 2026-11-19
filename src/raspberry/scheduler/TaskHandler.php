<?php

declare(strict_types=1);

namespace raspberry\scheduler;

/**
 * A scheduled task: when it runs next, how often, and whether it was cancelled.
 */
class TaskHandler{

    private Task $task;

    public int $nextRun{
        get{
            return $this->nextRun;
        }

        set{
            $this->nextRun = $value;
        }
    }

    /** ticks between runs, 0 for a task that runs once */
    private int $period;

    private bool $cancelled = false;

    public function __construct(Task $task, int $nextRun, int $period){
        $this->task = $task;
        $this->nextRun = $nextRun;
        $this->period = $period;
    }

    public function getTask() : Task{
        return $this->task;
    }

    public function getPeriod() : int{
        return $this->period;
    }

    public function isRepeating() : bool{
        return $this->period > 0;
    }

    public function isCancelled() : bool{
        return $this->cancelled;
    }

    public function cancel() : void{
        if($this->cancelled){
            return;
        }

        $this->cancelled = true;
        $this->task->onCancel();
        $this->task->handler = null;
    }

    /**
     * @internal
     */
    public function finish() : void{
        $this->cancelled = true;
        $this->task->handler = null;
    }
}