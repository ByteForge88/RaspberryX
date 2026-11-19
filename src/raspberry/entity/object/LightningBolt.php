<?php

declare(strict_types=1);

namespace raspberry\entity\object;

use raspberry\Server;

use raspberry\block\Fire;
use raspberry\block\BlockIds;
use raspberry\block\growth\Nearby;
use raspberry\block\growth\Oxidation;

use raspberry\entity\Living;
use raspberry\entity\Entity;
use raspberry\entity\EntityIds;
use raspberry\entity\damage\DamageSource;

use raspberry\event\block\BlockIgniteEvent;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\EntityMetadata;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\world\Difficulty;
use raspberry\world\GameRules;
use raspberry\world\World;

use JsonException;

use function abs;
use function floor;
use function mt_rand;

class LightningBolt extends Entity{

    public const float DAMAGE = 5.0;

    public const int FIRE_SECONDS = 8;

    private const float REACH = 3.0;
    private const float REACH_UP = 6.0;

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

    public function getMotion() : Vector3{
        return Vector3::zero();
    }

    public function createMetadata() : EntityMetadata{
        $metadata = new EntityMetadata();
        $metadata->setLong(EntityMetadata::KEY_FLAGS_TWO, 0);
        return $metadata;
    }

    /**
     * @throws JsonException
     */
    public function tick(int $currentTick) : void{
        if($this->closed){
            return;
        }

        if($this->life === 2){
            $this->updateViewers();
            $this->world->broadcastPacket($this->position->getFloorX(), $this->position->getFloorZ(), LevelSoundEventPacket::create(LevelSoundEvent::THUNDER, $this->position->x, $this->position->y, $this->position->z));
            $this->clearCopper();
            $this->spawnFire(4);
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
     * On normal and hard, the bolt sets fire where it hits and to a few spots around it.
     *
     * @throws JsonException
     */
    private function spawnFire(int $around) : void{
        $difficulty = Server::getInstance()->getDifficulty();

        if(($difficulty !== Difficulty::NORMAL && $difficulty !== Difficulty::HARD) || !$this->world->getGameRules()->getBool(GameRules::DO_FIRE_TICK)){
            return;
        }

        [$x, $y, $z] = [(int) floor($this->position->x), (int) floor($this->position->y), (int) floor($this->position->z)];
        $spots = [[$x, $y, $z]];

        for($i = 0; $i < $around; ++$i){
            $spots[] = [$x + mt_rand(-1, 1), $y + mt_rand(-1, 1), $z + mt_rand(-1, 1)];
        }

        foreach($spots as [$fx, $fy, $fz]){
            if(Nearby::name($this->world, $fx, $fy, $fz) === BlockIds::AIR && Fire::canSurviveAt($this->world, $fx, $fy, $fz)){
                BlockIgniteEvent::ignite($this->world, $fx, $fy, $fz, Fire::stateAt($this->world, $fx, $fy, $fz), BlockIgniteEvent::CAUSE_LIGHTNING);
            }
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