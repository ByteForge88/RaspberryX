<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\command;

class CommandEnumConstraintData{

    public const int CHEATS_ENABLED = 0;
    public const int OPERATOR_PERMISSIONS = 1;
    public const int HOST_PERMISSIONS = 2;

    public int $valueIndex;

    public int $enumIndex;

    /** @var list<int> */
    public array $constraints;

    /**
     * @param list<int> $constraints
     */
    public function __construct(int $valueIndex, int $enumIndex, array $constraints){
        $this->valueIndex = $valueIndex;
        $this->enumIndex = $enumIndex;
        $this->constraints = $constraints;
    }
}