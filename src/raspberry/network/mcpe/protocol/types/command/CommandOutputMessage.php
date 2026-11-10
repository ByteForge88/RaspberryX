<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\command;

class CommandOutputMessage{

    /** a language key without the "%", or plain text */
    public string $messageId;

    public bool $success;

    /** @var list<string> */
    public array $parameters;

    /**
     * @param list<string> $parameters
     */
    public function __construct(string $messageId, bool $success, array $parameters = []){
        $this->messageId = $messageId;
        $this->success = $success;
        $this->parameters = $parameters;
    }
}