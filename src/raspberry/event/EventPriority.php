<?php

declare(strict_types=1);

namespace raspberry\event;

/**
 * When a listener runs: LOWEST first, MONITOR last. MONITOR is for looking at the outcome only,
 * a listener there should not change the event.
 */
enum EventPriority : int{

    case LOWEST = 0;
    case LOW = 1;
    case NORMAL = 2;
    case HIGH = 3;
    case HIGHEST = 4;
    case MONITOR = 5;

}