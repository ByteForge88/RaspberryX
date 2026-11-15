<?php

declare(strict_types=1);

namespace raspberry\event\block;

/**
 * Leaves with no log near them are about to decay and drop their saplings, sticks and apples.
 * Cancelling it keeps them, they try again on a later random tick.
 */
class LeavesDecayEvent extends BlockChangeEvent{

}