<?php

declare(strict_types=1);

namespace raspberry\entity\object;

use raspberry\entity\Entity;
use raspberry\entity\EntityIds;

use raspberry\event\entity\ExplosionPrimeEvent;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\types\EntityMetadata;

use raspberry\world\Explosion;
use raspberry\world\GameRules;
use raspberry\world\World;

use function cos;
use function mt_getrandmax;
use function mt_rand;
use function sin;

use const M_PI;

class PrimedTNT extends Entity{
    use BlockPhysics;

    public const SIZE = 0.98;

    public const DEFAULT_FUSE = 80;

    public const EXPLOSION_RADIUS = 4.0;

    private const GRAVITY = 0.04;
    private const DRAG = 0.02;

    private int $fuse;

    private bool $viewersUpdated = false;

    public function __construct(World $world, Vector3 $position, int $fuse = self::DEFAULT_FUSE){
        $this->fuse = $fuse;

        $angle = mt_rand() / mt_getrandmax() * M_PI * 2;
        $this->motion = new Vector3(-sin($angle) * 0.02, 0.2, -cos($angle) * 0.02);

        parent::__construct($world, $position);
    }

    public function getNetworkTypeId() : string{
        return EntityIds::TNT;
    }

    public function getWidth() : float{
        return self::SIZE;
    }

    public function getHeight() : float{
        return self::SIZE;
    }

    public function getFuse() : int{
        return $this->fuse;
    }

    public function createMetadata() : EntityMetadata{
        $metadata = new EntityMetadata();
        $metadata->setFlag(EntityMetadata::FLAG_HAS_GRAVITY);
        $metadata->setFlag(EntityMetadata::FLAG_HAS_COLLISION);
        $metadata->setFlag(EntityMetadata::FLAG_IGNITED);
        $metadata->setLong(EntityMetadata::KEY_FLAGS_TWO, 0);
        $metadata->setInt(EntityMetadata::KEY_FUSE_TIME, $this->fuse);
        $metadata->setFloat(EntityMetadata::KEY_WIDTH, self::SIZE);
        $metadata->setFloat(EntityMetadata::KEY_HEIGHT, self::SIZE);
        return $metadata;
    }

    public function tick(int $currentTick) : void{
        if($this->closed){
            return;
        }

        if(!$this->world->isChunkLoaded($this->position->getChunkX(), $this->position->getChunkZ())){
            return;
        }

        if(!$this->viewersUpdated || $currentTick % 20 === 0){
            $this->viewersUpdated = true;
            $this->updateViewers();
        }

        $this->fall(self::GRAVITY, self::DRAG, 0.7);

        if(--$this->fuse > 0){
            return;
        }

        $this->close();

        if(!$this->world->getGameRules()->getBool(GameRules::TNT_EXPLODES)){
            return;
        }

        $event = new ExplosionPrimeEvent($this, self::EXPLOSION_RADIUS);
        $event->call();

        if($event->isCancelled()){
            return;
        }

        $explosion = new Explosion($this->world, $this->position->add(0, self::SIZE / 16, 0), $event->getRadius(), $this);
        $explosion->setBlockBreaking($event->isBlockBreaking());
        $explosion->explode();
    }
}