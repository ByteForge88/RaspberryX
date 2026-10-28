<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\command;

class ChainedSubCommandData{

    public string $name;

    /** @var list<array{int, int}> */
    public array $values;

    /**
     * @param list<array{int, int}> $values
     */
    public function __construct(string $name, array $values){
        $this->name = $name;
        $this->values = $values;
    }
}