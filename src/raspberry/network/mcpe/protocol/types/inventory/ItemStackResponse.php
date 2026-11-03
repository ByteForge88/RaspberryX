<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\inventory;

class ItemStackResponse{

    public const STATUS_OK = 0;
    public const STATUS_ERROR = 1;

    public int $status;

    public int $requestId;

    /** @var array<int, list<array{int, int, int, string, int}>> */
    public array $containers;

    /**
     * @param array<int, list<array{int, int, int, string, int}>> $containers
     */
    public function __construct(int $status, int $requestId, array $containers = []){
        $this->status = $status;
        $this->requestId = $requestId;
        $this->containers = $containers;
    }
}