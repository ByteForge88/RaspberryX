<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\command;

class CommandEnumData{

    public string $name;

    /** @var list<string> */
    public array $values;

    /**
     * @param list<string> $values
     */
    public function __construct(string $name, array $values){
        $this->name = $name;
        $this->values = $values;
    }
}