<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\entity\EntityFactory;
use raspberry\entity\monster\Monster;

use raspberry\math\Vector3;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\types\LevelEvent;

use raspberry\player\GameMode;

use raspberry\world\NaturalSpawner;
use raspberry\world\World;

use function floor;
use function mt_getrandmax;
use function mt_rand;

class Spawner extends BlockEntity implements TickableBlockEntity{

    public const string ID = "MobSpawner";

    /** spawners do not care how dark it is, only that there is not too much block light */
    private const int MAX_BLOCK_LIGHT = 11;

    private string $entityId = "";

    private int $delay = 20;

    private int $minDelay = 200;

    private int $maxDelay = 800;

    private int $spawnCount = 4;

    private int $maxNearby = 6;

    private int $playerRange = 16;

    private int $spawnRange = 4;

    public function getSaveId() : string{
        return self::ID;
    }

    public function getEntityId() : string{
        return $this->entityId;
    }

    public function setEntityId(string $entityId) : void{
        $this->entityId = $entityId;
        $this->delay = 20;
        $this->setChanged();
    }

    public function tick(World $world, int $currentTick) : void{
        if($this->entityId === "" || !$this->isPlayerNear($world)){
            return;
        }

        if($this->delay > 0){
            --$this->delay;
            return;
        }

        $spawned = false;

        for($i = 0; $i < $this->spawnCount; ++$i){
            $x = $this->getX() + (mt_rand() / mt_getrandmax() - mt_rand() / mt_getrandmax()) * $this->spawnRange + 0.5;
            $y = $this->getY() + mt_rand(0, 2) - 1;
            $z = $this->getZ() + (mt_rand() / mt_getrandmax() - mt_rand() / mt_getrandmax()) * $this->spawnRange + 0.5;
            [$bx, $bz] = [(int) floor($x), (int) floor($z)];

            [$width, $height] = NaturalSpawner::getSize($this->entityId);

            if(!$world->isChunkLoaded($bx >> 4, $bz >> 4) || !NaturalSpawner::hasRoom($world, $x, $y, $z, $width, $height)){
                continue;
            }

            if($this->countNearby($world) >= $this->maxNearby){
                $this->resetDelay();
                return;
            }

            $mob = EntityFactory::create($this->entityId, $world, new Vector3($x, $y, $z), mt_rand() / mt_getrandmax() * 360.0);

            if($mob === null){
                return;
            }

            if($mob instanceof Monster){
                if($world->getBlockLight($bx, $y, $bz) > self::MAX_BLOCK_LIGHT){
                    $mob->close();
                    continue;
                }

                $mob->setPersistent(false);
            }

            $mob->onSpawnedNaturally();
            $world->broadcastPacket($this->getX(), $this->getZ(), LevelEventPacket::create(LevelEvent::PARTICLES_MOB_BLOCK_SPAWN, $this->getX() + 0.5, $this->getY() + 0.5, $this->getZ() + 0.5));
            $spawned = true;
        }

        if($spawned){
            $this->resetDelay();
        }
    }

    private function resetDelay() : void{
        $this->delay = $this->maxDelay <= $this->minDelay ? $this->minDelay : $this->minDelay + mt_rand(0, $this->maxDelay - $this->minDelay - 1);
    }

    private function isPlayerNear(World $world) : bool{
        $center = new Vector3($this->getX() + 0.5, $this->getY() + 0.5, $this->getZ() + 0.5);

        foreach($world->getPlayers() as $player){
            if($player->isSpawned() && $player->isAlive() && $player->getGameMode() !== GameMode::SPECTATOR && $player->getPosition()->distanceSquared($center) < $this->playerRange ** 2){
                return true;
            }
        }

        return false;
    }

    /**
     * Its own mobs within the spawn range around it.
     */
    private function countNearby(World $world) : int{
        $count = 0;
        $range = $this->spawnRange;

        foreach($world->getEntities() as $entity){
            if($entity->isClosed() || $entity->getNetworkTypeId() !== $this->entityId){
                continue;
            }

            $position = $entity->getPosition();

            if($position->x >= $this->getX() - $range && $position->x <= $this->getX() + 1 + $range && $position->y >= $this->getY() - $range && $position->y <= $this->getY() + 1 + $range && $position->z >= $this->getZ() - $range && $position->z <= $this->getZ() + 1 + $range){
                ++$count;
            }
        }

        return $count;
    }

    public function readData(CompoundTag $nbt) : void{
        $this->entityId = $nbt->getString("EntityIdentifier", "");
        $this->delay = $nbt->getShort("Delay", 20);
        $this->minDelay = $nbt->getShort("MinSpawnDelay", 200);
        $this->maxDelay = $nbt->getShort("MaxSpawnDelay", 800);
        $this->spawnCount = $nbt->getShort("SpawnCount", 4);
        $this->maxNearby = $nbt->getShort("MaxNearbyEntities", 6);
        $this->playerRange = $nbt->getShort("RequiredPlayerRange", 16);
        $this->spawnRange = $nbt->getShort("SpawnRange", 4);
    }

    public function writeData(CompoundTag $nbt) : void{
        $nbt->setString("EntityIdentifier", $this->entityId);
        $nbt->setShort("Delay", $this->delay);
        $nbt->setShort("MinSpawnDelay", $this->minDelay);
        $nbt->setShort("MaxSpawnDelay", $this->maxDelay);
        $nbt->setShort("SpawnCount", $this->spawnCount);
        $nbt->setShort("MaxNearbyEntities", $this->maxNearby);
        $nbt->setShort("RequiredPlayerRange", $this->playerRange);
        $nbt->setShort("SpawnRange", $this->spawnRange);
        $nbt->setFloat("DisplayEntityWidth", 1.0);
        $nbt->setFloat("DisplayEntityHeight", 1.0);
        $nbt->setFloat("DisplayEntityScale", 1.0);
    }
}