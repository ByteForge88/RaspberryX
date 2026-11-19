<?php

declare(strict_types=1);

namespace raspberry\block\growth;

use raspberry\block\BlockIds;
use raspberry\block\BlockState;
use raspberry\block\PointedDripstone;

use raspberry\world\World;

use JsonException;

class Dripstone implements RandomTickBehavior{

    public function getBlockNames() : array{
        return [BlockIds::POINTED_DRIPSTONE];
    }

    public function ticks(BlockState $state) : bool{
        return PointedDripstone::isHanging($state);
    }

    /**
     * @throws JsonException
     */
    public function onRandomTick(World $world, int $x, int $y, int $z, BlockState $state) : void{
        PointedDripstone::onRandomTick($world, $x, $y, $z, $state);
    }
}