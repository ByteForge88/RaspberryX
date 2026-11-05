<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\inventory;

class ItemStackRequest{

    /** negative, chosen by the client */
    public int $requestId;

    /** @var list<StackRequestAction> */
    public array $actions;

    /** @var list<string> */
    public array $filterStrings;

    /**
     * @param list<StackRequestAction> $actions
     * @param list<string>             $filterStrings
     */
    public function __construct(int $requestId, array $actions, array $filterStrings = []){
        $this->requestId = $requestId;
        $this->actions = $actions;
        $this->filterStrings = $filterStrings;
    }
}