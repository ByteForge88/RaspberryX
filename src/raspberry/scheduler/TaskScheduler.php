<?php

declare(strict_types=1);

namespace raspberry\scheduler;

use raspberry\plugin\PluginLogger;

use InvalidArgumentException;
use SplPriorityQueue;
use Throwable;

use function spl_object_id;

class TaskScheduler{

    private PluginLogger $logger;

    /** @var SplPriorityQueue<array{int, int}, TaskHandler> */
    private SplPriorityQueue $queue;

    /** @var array<int, TaskHandler> */
    private array $handlers = [];

    private int $currentTick = 0;

    private int $order = 0;

    public function __construct(PluginLogger $logger, int $currentTick){
        $this->logger = $logger;
        $this->currentTick = $currentTick;
        $this->queue = new SplPriorityQueue();
    }

    /**
     * Runs the task on the next tick.
     */
    public function scheduleTask(Task $task) : TaskHandler{
        return $this->schedule($task, 1, 0);
    }

    public function scheduleDelayedTask(Task $task, int $delay) : TaskHandler{
        return $this->schedule($task, $delay, 0);
    }

    public function scheduleRepeatingTask(Task $task, int $period) : TaskHandler{
        return $this->schedule($task, 1, $period);
    }

    public function scheduleDelayedRepeatingTask(Task $task, int $delay, int $period) : TaskHandler{
        return $this->schedule($task, $delay, $period);
    }

    public function cancelAllTasks() : void{
        foreach($this->handlers as $handler){
            $handler->cancel();
        }

        $this->handlers = [];
        $this->queue = new SplPriorityQueue();
    }

    /**
     * @internal
     */
    public function tick(int $currentTick) : void{
        $this->currentTick = $currentTick;

        while(!$this->queue->isEmpty()){
            /** @var TaskHandler $handler */
            $handler = $this->queue->top();

            if($handler->isCancelled()){
                $this->queue->extract();
                unset($this->handlers[spl_object_id($handler)]);
                continue;
            }

            if($handler->getNextRun() > $currentTick){
                break;
            }

            $this->queue->extract();

            try{
                $handler->getTask()->onRun();
            }catch(Throwable $exception){
                $this->logger->error("Task " . $handler->getTask()::class . " failed: " . $exception::class . ": " . $exception->getMessage());
                $this->logger->debug($exception->getTraceAsString());
            }

            if($handler->isRepeating() && !$handler->isCancelled()){
                $handler->setNextRun($currentTick + $handler->getPeriod());
                $this->queue->insert($handler, [-$handler->getNextRun(), -++$this->order]);
            }else{
                unset($this->handlers[spl_object_id($handler)]);
                $handler->finish();
            }
        }
    }

    private function schedule(Task $task, int $delay, int $period) : TaskHandler{
        if($task->getHandler() !== null){
            throw new InvalidArgumentException("The task is already scheduled");
        }

        if($delay < 1 || $period < 0){
            throw new InvalidArgumentException("The delay must be at least 1 tick and the period cannot be negative");
        }

        $handler = new TaskHandler($task, $this->currentTick + $delay, $period);
        $task->setHandler($handler);
        $this->handlers[spl_object_id($handler)] = $handler;
        $this->queue->insert($handler, [-$handler->getNextRun(), -++$this->order]);
        return $handler;
    }
}