<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\inventory;

class ItemStackRequest{

    /** negative, chosen by the client */
    public int $requestId;

    /** @var list<StackRequestAction> */
    public array $actions;

    /**
     * @param list<StackRequestAction> $actions
     */
    public function __construct(int $requestId, array $actions){
        $this->requestId = $requestId;
        $this->actions = $actions;
    }
}