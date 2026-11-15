<?php

declare(strict_types=1);

namespace raspberry\event\world;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\world\World;

/**
 * It starts or stops raining or thundering, by itself or by /weather. Cancelling it keeps the
 * weather as it was.
 */
class WeatherChangeEvent extends WorldEvent implements Cancellable{
    use CancellableTrait;

    private bool $wasRaining;

    private bool $wasThundering;

    private bool $raining;

    private bool $thundering;

    public function __construct(World $world, bool $wasRaining, bool $wasThundering, bool $raining, bool $thundering){
        $this->world = $world;
        $this->wasRaining = $wasRaining;
        $this->wasThundering = $wasThundering;
        $this->raining = $raining;
        $this->thundering = $thundering;
    }

    public function wasRaining() : bool{
        return $this->wasRaining;
    }

    public function wasThundering() : bool{
        return $this->wasThundering;
    }

    public function isRaining() : bool{
        return $this->raining;
    }

    public function isThundering() : bool{
        return $this->thundering;
    }
}