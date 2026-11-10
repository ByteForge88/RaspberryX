<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\lang\Translation;

use RuntimeException;

class CommandException extends RuntimeException{

    private ?Translation $translation;

    /**
     * @param list<string|int|float|Translation> $params
     */
    public function __construct(string $key = "commands.generic.exception", array $params = []){
        parent::__construct($key);
        $this->translation = new Translation($key, $params);
    }

    public static function text(string $message) : self{
        $exception = new self($message);
        $exception->translation = null;

        return $exception;
    }

    public function getTranslation() : ?Translation{
        return $this->translation;
    }
}