<?php

declare(strict_types=1);

namespace raspberry\block\entity;

class EnderChest extends BlockEntity{

    public function getSaveId() : string{
        return "EnderChest";
    }
}