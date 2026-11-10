<?php

declare(strict_types=1);

namespace raspberry\command;

use RuntimeException;

class InvalidCommandSyntaxException extends RuntimeException{

    private int $offset;

    public function __construct(int $offset = -1){
        parent::__construct("Invalid command syntax");
        $this->offset = $offset;
    }

    public function getOffset() : int{
        return $this->offset;
    }
}