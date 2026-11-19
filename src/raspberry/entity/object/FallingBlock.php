<?php

declare(strict_types=1);

namespace raspberry\entity\object;

use raspberry\Server;

use raspberry\block\BlockIds;
use raspberry\block\BlockState;
use raspberry\block\FallingBlocks;

use raspberry\event\entity\EntityBlockChangeEvent;

use raspberry\entity\Entity;
use raspberry\entity\Living;
use raspberry\entity\damage\DamageSource;

use raspberry\item\Item;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\types\EntityMetadata;
use raspberry\network\mcpe\protocol\types\LevelEvent;

use raspberry\world\Chunk;
use raspberry\world\GameRules;
use raspberry\world\World;

use function ceil;
use function floor;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function str_ends_with;

class FallingBlock extends Entity{
    use BlockPhysics;

    public const float SIZE = 0.98;

    private const float GRAVITY = 0.04;
    private const float DRAG = 0.02;

    private const int MAX_AGE = 600;

    private const float ANVIL_DAMAGE_PER_BLOCK = 2.0;
    private const float ANVIL_MAX_DAMAGE = 40.0;

    private const array ANVILS = [BlockIds::ANVIL => "minecraft:chipped_anvil", "minecraft:chipped_anvil" => "minecraft:damaged_anvil", "minecraft:damaged_anvil" => null];

    private BlockState $block;

    private int $age = 0;

    private float $hurtPerBlock = 0.0;

    private float $hurtMax = 0.0;

    private bool $viewersUpdated = false;

    public function __construct(World $world, Vector3 $position, BlockState $block){
        $this->block = $block;
        $this->motion = Vector3::zero();

        parent::__construct($world, $position);
    }

    public function getNetworkTypeId() : string{
        return "minecraft:falling_block";//EntityIds doesnt include this
    }

    public function getWidth() : float{
        return self::SIZE;
    }

    public function getHeight() : float{
        return self::SIZE;
    }

    public function getBlock() : BlockState{
        return $this->block;
    }

    public function createMetadata() : EntityMetadata{
        $metadata = new EntityMetadata();
        $metadata->setFlag(EntityMetadata::FLAG_HAS_GRAVITY);
        $metadata->setFlag(EntityMetadata::FLAG_HAS_COLLISION);
        $metadata->setLong(EntityMetadata::KEY_FLAGS_TWO, 0);
        $metadata->setInt(EntityMetadata::KEY_VARIANT, Server::getInstance()->getBlockTranslator()->getNetworkId($this->block));
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

        $falling = $this->fallDistance;
        $this->fall(self::GRAVITY, self::DRAG, 0.7);

        if($this->onGround){
            $this->land($falling);
            return;
        }

        if(++$this->age > self::MAX_AGE || $this->position->y < Chunk::MIN_Y - 64){
            $this->dropAsItem();
            $this->close();
        }
    }

    private function land(float $fallDistance) : void{
        $x = (int) floor($this->position->x);
        $y = (int) floor($this->position->y + 0.01);
        $z = (int) floor($this->position->z);
        $block = $this->block;
        $name = $block->getName();
        $this->close();

        if($name === BlockIds::POINTED_DRIPSTONE){
            $this->hurtBelow($x, $y, $z, $fallDistance, $this->hurtPerBlock, $this->hurtMax, DamageSource::CAUSE_STALACTITE);
            $this->world->broadcastPacket($x, $z, LevelEventPacket::create(LevelEvent::SOUND_POINTED_DRIPSTONE_LAND, $x + 0.5, $y + 0.5, $z + 0.5));
            $this->dropAsItem();
            return;
        }

        if(str_ends_with($name, "suspicious_sand") || str_ends_with($name, "suspicious_gravel")){
            $this->world->broadcastPacket($x, $z, LevelEventPacket::create(LevelEvent::PARTICLES_DESTROY_BLOCK, $x + 0.5, $y + 0.5, $z + 0.5, Server::getInstance()->getBlockTranslator()->getNetworkId($block)));
            return;
        }

        if(isset(self::ANVILS[$name])){
            $block = $this->hitWithAnvil($x, $y, $z, $fallDistance);

            if($block === null){
                $this->world->broadcastPacket($x, $z, LevelEventPacket::create(LevelEvent::SOUND_ANVIL_BROKEN, $x + 0.5, $y + 0.5, $z + 0.5));
                return;
            }

            $this->world->broadcastPacket($x, $z, LevelEventPacket::create(LevelEvent::SOUND_ANVIL_LAND, $x + 0.5, $y + 0.5, $z + 0.5));
        }

        $here = $this->world->getBlock($x, $y, $z);

        if(!FallingBlocks::canFallThrough($here)){
            $this->dropAsItem();
            return;
        }

        $event = new EntityBlockChangeEvent($this, new Vector3($x, $y, $z), $here, $block);
        $event->call();

        if($event->isCancelled()){
            $this->dropAsItem();
            return;
        }

        $this->world->setBlock($x, $y, $z, $event->getNewState());
    }

    /**
     * Pointed dripstone set to fall hurts what it lands on, $perBlock for each block it fell.
     */
    public function setHurtsEntities(float $perBlock, float $max) : void{
        $this->hurtPerBlock = $perBlock;
        $this->hurtMax = $max;
    }

    /**
     * Hurts every living thing in the block it landed in, the blocks it fell are returned.
     */
    private function hurtBelow(int $x, int $y, int $z, float $fallDistance, float $perBlock, float $max, int $cause) : int{
        $blocks = (int) ceil($fallDistance - 1.0);

        if($blocks <= 0 || $perBlock <= 0.0){
            return $blocks;
        }

        $damage = min(floor($blocks * $perBlock), $max);

        foreach($this->world->getEntities() as $entity){
            $position = $entity->getPosition();
            $half = $entity->getWidth() / 2;

            if($entity instanceof Living && $entity->isAlive() && $position->x + $half > $x && $position->x - $half < $x + 1 && $position->z + $half > $z && $position->z - $half < $z + 1 && $position->y + $entity->getHeight() > $y && $position->y < $y + 1){
                $entity->damage(new DamageSource($cause, $damage));
            }
        }

        return $blocks;
    }

    private function hitWithAnvil(int $x, int $y, int $z, float $fallDistance) : ?BlockState{
        $blocks = $this->hurtBelow($x, $y, $z, $fallDistance, self::ANVIL_DAMAGE_PER_BLOCK, self::ANVIL_MAX_DAMAGE, DamageSource::CAUSE_FALLING_BLOCK);

        if($blocks <= 0){
            return $this->block;
        }

        if(mt_rand() / mt_getrandmax() >= 0.05 + $blocks * 0.05){
            return $this->block;
        }

        $next = self::ANVILS[$this->block->getName()];

        return $next === null ? null : Server::getInstance()->getBlockStateRegistry()->get($next, $this->block->getProperties());
    }

    private function dropAsItem() : void{
        if(!$this->world->getGameRules()->getBool(GameRules::DO_ENTITY_DROPS)){
            return;
        }

        $this->world->dropItem($this->position, new Item($this->block->getName()));
    }
}