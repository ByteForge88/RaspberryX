<?php

declare(strict_types=1);

namespace raspberry\event;

use Attribute;

/**
 * Sets the priority of an event handler in a Listener, NORMAL when left out.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Priority{

    public EventPriority $priority;

    public function __construct(EventPriority $priority){
        $this->priority = $priority;
    }
}