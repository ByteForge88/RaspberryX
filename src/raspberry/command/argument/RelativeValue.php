<?php

declare(strict_types=1);

namespace raspberry\command\argument;

/**
 * A number that may be relative to something, like the 90 or ~90 of /tp's rotation.
 */
class RelativeValue{

    private float $amount;

    private bool $relative;

    public function __construct(float $amount, bool $relative){
        $this->amount = $amount;
        $this->relative = $relative;
    }

    public function getAmount() : float{
        return $this->amount;
    }

    public function isRelative() : bool{
        return $this->relative;
    }

    /**
     * The number it stands for, when it is relative to $origin.
     */
    public function from(float $origin) : float{
        return $this->relative ? $origin + $this->amount : $this->amount;
    }
}