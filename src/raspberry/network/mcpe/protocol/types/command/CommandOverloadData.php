<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\command;

class CommandOverloadData{

    public bool $chaining = false;

    /** @var list<CommandParameterData> */
    public array $parameters;

    /**
     * @param list<CommandParameterData> $parameters
     */
    public function __construct(array $parameters){
        $this->parameters = $parameters;
    }
}