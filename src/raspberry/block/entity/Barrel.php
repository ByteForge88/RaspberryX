<?php

declare(strict_types=1);

namespace raspberry\block\entity;

class Barrel extends ContainerBlockEntity{

    public function getSaveId() : string{
        return "Barrel";
    }
}