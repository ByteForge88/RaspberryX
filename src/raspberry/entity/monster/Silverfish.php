<?php

declare(strict_types=1);

namespace raspberry\entity\monster;

use raspberry\Server;

use raspberry\block\BlockIds;
use raspberry\block\InfestedBlocks;

use raspberry\entity\EntityIds;
use raspberry\entity\ai\Behavior;
use raspberry\entity\damage\DamageSource;
use raspberry\entity\effect\Effect;

use raspberry\network\mcpe\protocol\ActorEventPacket;
use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\types\LevelEvent;

use raspberry\world\GameRules;

use function floor;
use function mt_rand;

class Silverfish extends Monster{

    public const float FOLLOW_RANGE = 16.0;

    private const float TARGET_RANGE = 8.0;
    private const float ANGER_RANGE = 20.0;

    private const int WAKE_DELAY = 20;
    private const int WAKE_HORIZONTAL = 10;
    private const int WAKE_VERTICAL = 5;

    private const int MERGE_CHANCE = 10;

    private const array FACES = [[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]];

    private int $wakeTicks = 0;

    public function getNetworkTypeId() : string{
        return EntityIds::SILVERFISH;
    }

    protected function getBaseWidth() : float{
        return 0.4;
    }

    protected function getBaseHeight() : float{
        return 0.3;
    }

    protected function getDefaultMaxHealth() : float{
        return 8.0;
    }

    protected function getDefaultMovementSpeed() : float{
        return 0.25;
    }

    protected function getAttackDamage() : float{
        return 1.0;
    }

    public function isArthropod() : bool{
        return true;
    }

    public function canBeAffected(Effect $effect) : bool{
        return $effect !== Effect::INFESTED && parent::canBeAffected($effect);
    }

    public function getBehaviors() : array{
        return [
            Behavior::float(1),
            Behavior::hurtByTarget(1, self::FOLLOW_RANGE),
            Behavior::nearestAttackableTarget(2, self::TARGET_RANGE),
            Behavior::meleeAttack(4, 1.0),
            Behavior::randomStroll(5, 1.0)
        ];
    }

    protected function getDrops(int $looting) : array{
        return [];
    }

    public function onEngineEvent(int $kind, float $value, int $x, int $y, int $z, int $other) : void{
        parent::onEngineEvent($kind, $value, $x, $y, $z, $other);

        if($kind === self::EVENT_TARGET && $this->target !== null){
            $this->alertOthers();
        }
    }

    private function alertOthers() : void{
        $engine = $this->world->getMobEngine();

        foreach($this->world->getEntities() as $entity){
            if($entity !== $this && $entity instanceof self && $entity->isAlive() && $entity->getTarget() === null && $entity->getPosition()->distanceSquared($this->position) <= self::ANGER_RANGE ** 2){
                $engine->hurt($entity, $this->target);
            }
        }
    }

    protected function onDamaged(DamageSource $source) : void{
        parent::onDamaged($source);

        if($this->wakeTicks <= 0 && ($source->getAttacker() !== null || $source->getCause() === DamageSource::CAUSE_MAGIC)){
            $this->wakeTicks = self::WAKE_DELAY;
        }
    }

    public function tick(int $currentTick) : void{
        parent::tick($currentTick);

        if($this->closed || !$this->isAlive()){
            return;
        }

        if($this->wakeTicks > 0 && --$this->wakeTicks === 0){
            $this->wakeFriends();
        }

        if($this->getTarget() === null && mt_rand(0, self::MERGE_CHANCE - 1) === 0 && $this->world->getGameRules()->getBool(GameRules::MOB_GRIEFING)){
            $this->tryMerge();
        }
    }

    private function wakeFriends() : void{
        $griefing = $this->world->getGameRules()->getBool(GameRules::MOB_GRIEFING);
        $baseX = $this->position->getFloorX();
        $baseY = $this->position->getFloorY();
        $baseZ = $this->position->getFloorZ();

        for($dy = 0; $dy <= self::WAKE_VERTICAL && $dy >= -self::WAKE_VERTICAL; $dy = ($dy <= 0 ? 1 : 0) - $dy){
            for($dx = 0; $dx <= self::WAKE_HORIZONTAL && $dx >= -self::WAKE_HORIZONTAL; $dx = ($dx <= 0 ? 1 : 0) - $dx){
                for($dz = 0; $dz <= self::WAKE_HORIZONTAL && $dz >= -self::WAKE_HORIZONTAL; $dz = ($dz <= 0 ? 1 : 0) - $dz){
                    $x = $baseX + $dx;
                    $y = $baseY + $dy;
                    $z = $baseZ + $dz;

                    if(!$this->world->isChunkLoaded($x >> 4, $z >> 4)){
                        continue;
                    }

                    $block = $this->world->getBlock($x, $y, $z);

                    if(!InfestedBlocks::isInfested($block)){
                        continue;
                    }

                    if($griefing){
                        $this->world->setBlock($x, $y, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::AIR));
                        $this->world->broadcastPacket($x, $z, LevelEventPacket::create(LevelEvent::PARTICLES_DESTROY_BLOCK, $x + 0.5, $y + 0.5, $z + 0.5, Server::getInstance()->getBlockTranslator()->getNetworkId($block)));
                        InfestedBlocks::release($this->world, $x, $y, $z);
                    }else{
                        $this->world->setBlock($x, $y, $z, InfestedBlocks::toHost($block));
                    }

                    if(mt_rand(0, 1) === 0){
                        return;
                    }
                }
            }
        }
    }

    private function tryMerge() : void{
        [$dx, $dy, $dz] = self::FACES[mt_rand(0, 5)];
        $x = $this->position->getFloorX() + $dx;
        $y = (int) floor($this->position->y + 0.5) + $dy;
        $z = $this->position->getFloorZ() + $dz;

        if(!$this->world->isChunkLoaded($x >> 4, $z >> 4)){
            return;
        }

        $block = $this->world->getBlock($x, $y, $z);

        if(!InfestedBlocks::canInfest($block)){
            return;
        }

        $this->world->setBlock($x, $y, $z, InfestedBlocks::toInfested($block));
        $this->broadcastEvent(ActorEventPacket::SILVERFISH_MERGE);
        $this->close();
    }
}