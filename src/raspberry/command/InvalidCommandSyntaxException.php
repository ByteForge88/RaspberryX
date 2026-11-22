<?php

declare(strict_types=1);

namespace raspberry\command;

use RuntimeException;

class InvalidCommandSyntaxException extends RuntimeException{

    private int $word;

    /**
     * @param int $word
     */
    public function __construct(int $word = -1){
        parent::__construct("Invalid command syntax");
        $this->word = $word;
    }

    public function getWord() : int{
        return $this->word;
    }
}