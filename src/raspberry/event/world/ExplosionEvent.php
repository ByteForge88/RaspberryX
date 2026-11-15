<?php

declare(strict_types=1);

namespace raspberry\event\world;

use raspberry\entity\Entity;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\math\Vector3;

use raspberry\world\World;

/**
 * Something is about to explode. getBlocks() is every block it breaks, take some out to keep
 * them. Cancelling it stops the whole explosion, nothing gets hurt either.
 */
class ExplosionEvent extends WorldEvent implements Cancellable{
    use CancellableTrait;

    private Vector3 $center;

    private float $radius;

    private ?Entity $source;

    /** @var list<Vector3> */
    private array $blocks;

    private float $dropChance;

    /**
     * @param list<Vector3> $blocks
     */
    public function __construct(World $world, Vector3 $center, float $radius, ?Entity $source, array $blocks, float $dropChance){
        $this->world = $world;
        $this->center = $center;
        $this->radius = $radius;
        $this->source = $source;
        $this->blocks = $blocks;
        $this->dropChance = $dropChance;
    }

    public function getCenter() : Vector3{
        return $this->center;
    }

    public function getRadius() : float{
        return $this->radius;
    }

    /**
     * What blew up, lit TNT or a creeper, null for a plugin's or a bed's explosion.
     */
    public function getSource() : ?Entity{
        return $this->source;
    }

    /**
     * @return list<Vector3>
     */
    public function getBlocks() : array{
        return $this->blocks;
    }

    /**
     * @param list<Vector3> $blocks
     */
    public function setBlocks(array $blocks) : void{
        $this->blocks = $blocks;
    }

    /**
     * The chance each broken block drops, 0 to 1.
     */
    public function getDropChance() : float{
        return $this->dropChance;
    }

    public function setDropChance(float $dropChance) : void{
        $this->dropChance = $dropChance;
    }
}