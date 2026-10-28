<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\command;

class CommandParameterData{

    public const OPTION_COLLAPSE_ENUM = 1;
    public const OPTION_HAS_SEMANTIC_CONSTRAINT = 2;
    public const OPTION_AS_CHAINED_COMMAND = 4;

    public string $name;

    public int $type;

    public bool $optional;

    public int $options = 0;

    public function __construct(string $name, int $type, bool $optional){
        $this->name = $name;
        $this->type = $type;
        $this->optional = $optional;
    }
}