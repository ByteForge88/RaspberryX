<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

use function preg_match;
use function str_starts_with;

/**
 * x y z of a block, like a position but absolute ones have to be whole numbers. The command gets
 * the block's corner.
 */
class BlockPositionArgument extends PositionArgument{

    public function getNetworkType() : int{
        return AvailableCommandsPacket::ARG_TYPE_INT_POSITION;
    }

    protected function isCoordinate(string $part) : bool{
        if(str_starts_with($part, "~") || str_starts_with($part, "^")){
            return parent::isCoordinate($part);
        }

        return preg_match(IntArgument::PATTERN, $part) === 1;
    }

    protected function absolute(string $part, int $axis) : float{
        return (float) $part;
    }

    protected function finish(Vector3 $position) : Vector3{
        return new Vector3($position->getFloorX(), $position->getFloorY(), $position->getFloorZ());
    }
}