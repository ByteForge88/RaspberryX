<?php

declare(strict_types=1);

namespace raspberry\entity\object;

use raspberry\block\growth\Oxidation;

use raspberry\entity\Living;
use raspberry\entity\Entity;
use raspberry\entity\EntityIds;
use raspberry\entity\damage\DamageSource;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\world\World;

use function abs;
use function floor;
use function mt_rand;

class LightningBolt extends Entity{

    public const DAMAGE = 5.0;

    public const FIRE_SECONDS = 8;

    private const REACH = 3.0;
    private const REACH_UP = 6.0;

    private int $life = 2;

    private int $flashes;

    /** @var array<int, true> */
    private array $hit = [];

    public function __construct(World $world, Vector3 $position){
        $this->flashes = mt_rand(1, 3);

        parent::__construct($world, $position);
    }

    public function getNetworkTypeId() : string{
        return EntityIds::LIGHTNING_BOLT;
    }

    public function tick(int $currentTick) : void{
        if($this->closed){
            return;
        }

        if($this->life === 2){
            $this->updateViewers();
            $this->world->broadcastPacket($this->position->getFloorX(), $this->position->getFloorZ(), LevelSoundEventPacket::create(LevelSoundEvent::THUNDER, $this->position->x, $this->position->y, $this->position->z));
            $this->clearCopper();
        }

        if(--$this->life < 0){
            if($this->flashes === 0){
                $this->close();
                return;
            }

            if($this->life < -mt_rand(0, 9)){
                --$this->flashes;
                $this->life = 1;
            }
        }

        if($this->life >= 0){
            $this->strikeAround();
        }
    }

    private function strikeAround() : void{
        foreach($this->world->getEntities() as $entity){
            if(!$entity instanceof Living || $entity->isClosed() || !$entity->isAlive() || isset($this->hit[$entity->getId()])){
                continue;
            }

            $position = $entity->getPosition();

            if(abs($position->x - $this->position->x) > self::REACH || abs($position->z - $this->position->z) > self::REACH || $position->y < $this->position->y - self::REACH || $position->y > $this->position->y + self::REACH_UP){
                continue;
            }

            $this->hit[$entity->getId()] = true;
            $entity->setOnFire(self::FIRE_SECONDS);
            $entity->damage(new DamageSource(DamageSource::CAUSE_LIGHTNING, self::DAMAGE));
        }
    }

    /**
     * Copper the bolt hits loses its weathering.
     */
    private function clearCopper() : void{
        [$x, $y, $z] = [(int) floor($this->position->x), (int) floor($this->position->y - 0.000001), (int) floor($this->position->z)];

        if(!$this->world->isChunkLoaded($x >> 4, $z >> 4)){
            return;
        }

        $cleared = Oxidation::unweathered($this->world->getBlock($x, $y, $z));

        if($cleared !== null){
            $this->world->setBlock($x, $y, $z, $cleared);
        }
    }
}