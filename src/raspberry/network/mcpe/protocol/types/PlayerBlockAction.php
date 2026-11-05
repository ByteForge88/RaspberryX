<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types;

class PlayerBlockAction{

    public const START_BREAK = 0;
    public const ABORT_BREAK = 1;
    public const STOP_BREAK = 2;
    public const CRACK_BREAK = 18;
    public const PREDICT_DESTROY_BLOCK = 26;
    public const CONTINUE_DESTROY_BLOCK = 27;

    public int $action;

    public int $x;
    public int $y;
    public int $z;

    public int $face;

    public function __construct(int $action, int $x, int $y, int $z, int $face){
        $this->action = $action;
        $this->x = $x;
        $this->y = $y;
        $this->z = $z;
        $this->face = $face;
    }
}