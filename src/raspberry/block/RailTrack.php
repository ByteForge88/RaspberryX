<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\world\World;

use function count;

class RailTrack{

    private World $world;

    private int $x;
    private int $y;
    private int $z;

    private BlockState $state;

    private bool $straight;

    /** @var list<array{int, int, int}> */
    private array $connections = [];

    public function __construct(World $world, int $x, int $y, int $z, BlockState $state){
        $this->world = $world;
        $this->x = $x;
        $this->y = $y;
        $this->z = $z;
        $this->state = $state;
        $this->straight = Rails::isStraight($state->getName());
        $this->connect(Rails::shapeOf($state));
    }

    public static function find(World $world, int $x, int $y, int $z) : ?self{
        foreach([$y, $y + 1, $y - 1] as $at){
            $block = Rails::blockAt($world, $x, $at, $z);

            if(Rails::isRail($block->getName())){
                return new self($world, $x, $at, $z, $block);
            }
        }

        return null;
    }

    public function countPotentialConnections() : int{
        $count = 0;

        foreach([[0, -1], [0, 1], [-1, 0], [1, 0]] as [$dx, $dz]){
            if($this->hasRail($this->x + $dx, $this->y, $this->z + $dz)){
                ++$count;
            }
        }

        return $count;
    }

    public function place(bool $powered, bool $always) : void{
        [$x, $y, $z] = [$this->x, $this->y, $this->z];
        $current = Rails::shapeOf($this->state);

        $north = $this->hasNeighborRail($x, $y, $z - 1);
        $south = $this->hasNeighborRail($x, $y, $z + 1);
        $west = $this->hasNeighborRail($x - 1, $y, $z);
        $east = $this->hasNeighborRail($x + 1, $y, $z);

        $alongZ = $north || $south;
        $alongX = $west || $east;
        $southEast = $south && $east;
        $southWest = $south && $west;
        $northEast = $north && $east;
        $northWest = $north && $west;
        $shape = null;

        if($alongZ && !$alongX){
            $shape = Rails::NORTH_SOUTH;
        }

        if($alongX && !$alongZ){
            $shape = Rails::EAST_WEST;
        }

        if(!$this->straight){
            if($southEast && !$north && !$west){
                $shape = Rails::SOUTH_EAST;
            }

            if($southWest && !$north && !$east){
                $shape = Rails::SOUTH_WEST;
            }

            if($northWest && !$south && !$east){
                $shape = Rails::NORTH_WEST;
            }

            if($northEast && !$south && !$west){
                $shape = Rails::NORTH_EAST;
            }
        }

        if($shape === null){
            if($alongZ && $alongX){
                $shape = $current;
            }elseif($alongZ){
                $shape = Rails::NORTH_SOUTH;
            }elseif($alongX){
                $shape = Rails::EAST_WEST;
            }

            if(!$this->straight){
                if($powered){
                    $shape = $southEast ? Rails::SOUTH_EAST : $shape;
                    $shape = $southWest ? Rails::SOUTH_WEST : $shape;
                    $shape = $northEast ? Rails::NORTH_EAST : $shape;
                    $shape = $northWest ? Rails::NORTH_WEST : $shape;
                }else{
                    $shape = $northWest ? Rails::NORTH_WEST : $shape;
                    $shape = $northEast ? Rails::NORTH_EAST : $shape;
                    $shape = $southWest ? Rails::SOUTH_WEST : $shape;
                    $shape = $southEast ? Rails::SOUTH_EAST : $shape;
                }
            }
        }

        $shape = $shape === null ? $current : $this->slope($shape);
        $this->connect($shape);
        $state = $this->state->withProperty(BlockProperties::RAIL_DIRECTION, $shape);

        if(!$always && $this->world->getBlock($x, $y, $z)->equals($state)){
            return;
        }

        $this->state = $state;
        $this->world->setBlock($x, $y, $z, $state);

        foreach($this->connections as [$cx, $cy, $cz]){
            $other = self::find($this->world, $cx, $cy, $cz);

            if($other !== null){
                $other->removeSoftConnections();

                if($other->canConnectTo($this)){
                    $other->connectTo($this);
                }
            }
        }
    }

    private function slope(int $shape) : int{
        [$x, $y, $z] = [$this->x, $this->y, $this->z];

        if($shape === Rails::NORTH_SOUTH){
            $shape = Rails::isRailAt($this->world, $x, $y + 1, $z - 1) ? Rails::ASCENDING_NORTH : $shape;
            $shape = Rails::isRailAt($this->world, $x, $y + 1, $z + 1) ? Rails::ASCENDING_SOUTH : $shape;
        }elseif($shape === Rails::EAST_WEST){
            $shape = Rails::isRailAt($this->world, $x + 1, $y + 1, $z) ? Rails::ASCENDING_EAST : $shape;
            $shape = Rails::isRailAt($this->world, $x - 1, $y + 1, $z) ? Rails::ASCENDING_WEST : $shape;
        }

        return $shape;
    }

    private function connect(int $shape) : void{
        $this->connections = [];

        foreach(Rails::CONNECTIONS[$shape] as [$dx, $dy, $dz]){
            $this->connections[] = [$this->x + $dx, $this->y + $dy, $this->z + $dz];
        }
    }

    private function removeSoftConnections() : void{
        $kept = [];

        foreach($this->connections as [$cx, $cy, $cz]){
            $other = self::find($this->world, $cx, $cy, $cz);

            if($other !== null && $other->connectsTo($this)){
                $kept[] = [$other->x, $other->y, $other->z];
            }
        }

        $this->connections = $kept;
    }

    private function hasRail(int $x, int $y, int $z) : bool{
        return Rails::isRailAt($this->world, $x, $y, $z) || Rails::isRailAt($this->world, $x, $y + 1, $z) || Rails::isRailAt($this->world, $x, $y - 1, $z);
    }

    private function hasConnection(int $x, int $z) : bool{
        foreach($this->connections as [$cx, , $cz]){
            if($cx === $x && $cz === $z){
                return true;
            }
        }

        return false;
    }

    private function connectsTo(self $other) : bool{
        return $this->hasConnection($other->x, $other->z);
    }

    private function canConnectTo(self $other) : bool{
        return $this->connectsTo($other) || count($this->connections) !== 2;
    }

    private function hasNeighborRail(int $x, int $y, int $z) : bool{
        $other = self::find($this->world, $x, $y, $z);

        if($other === null){
            return false;
        }

        $other->removeSoftConnections();

        return $other->canConnectTo($this);
    }

    private function connectTo(self $other) : void{
        $this->connections[] = [$other->x, $other->y, $other->z];

        $north = $this->hasConnection($this->x, $this->z - 1);
        $south = $this->hasConnection($this->x, $this->z + 1);
        $west = $this->hasConnection($this->x - 1, $this->z);
        $east = $this->hasConnection($this->x + 1, $this->z);
        $shape = null;

        if($north || $south){
            $shape = Rails::NORTH_SOUTH;
        }

        if($west || $east){
            $shape = Rails::EAST_WEST;
        }

        if(!$this->straight){
            $shape = $south && $east && !$north && !$west ? Rails::SOUTH_EAST : $shape;
            $shape = $south && $west && !$north && !$east ? Rails::SOUTH_WEST : $shape;
            $shape = $north && $west && !$south && !$east ? Rails::NORTH_WEST : $shape;
            $shape = $north && $east && !$south && !$west ? Rails::NORTH_EAST : $shape;
        }

        $shape = $shape === null ? Rails::NORTH_SOUTH : $this->slope($shape);
        $this->state = $this->state->withProperty(BlockProperties::RAIL_DIRECTION, $shape);
        $this->world->setBlock($this->x, $this->y, $this->z, $this->state);
    }
}