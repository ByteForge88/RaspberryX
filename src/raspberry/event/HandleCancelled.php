<?php

declare(strict_types=1);

namespace raspberry\event;

use Attribute;

/**
 * Lets an event handler in a Listener get events that were already cancelled.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class HandleCancelled{

}