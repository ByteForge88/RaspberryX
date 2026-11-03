<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\inventory;

class StackRequestSlot{

    public int $containerId;

    public ?int $dynamicContainerId;

    public int $slot;

    public int $stackNetworkId;

    public function __construct(int $containerId, ?int $dynamicContainerId, int $slot, int $stackNetworkId){
        $this->containerId = $containerId;
        $this->dynamicContainerId = $dynamicContainerId;
        $this->slot = $slot;
        $this->stackNetworkId = $stackNetworkId;
    }
}