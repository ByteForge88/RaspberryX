<?php

declare(strict_types=1);

namespace raspberry\entity\object;

use raspberry\block\BlockCollision;
use raspberry\block\entity\Campfire;

use raspberry\entity\Entity;
use raspberry\entity\Human;
use raspberry\entity\Living;
use raspberry\entity\effect\EffectInstance;

use raspberry\item\Item;
use raspberry\item\ItemIds;
use raspberry\item\Potion;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelEvent;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\player\GameMode;
use raspberry\player\Player;

use raspberry\world\Chunk;
use raspberry\world\World;

use function ceil;
use function floor;
use function max;
use function sqrt;

class ThrownPotion extends Entity{

    public const GRAVITY = 0.05;
    public const DRAG = 0.01;

    /** how far a splash reaches */
    public const SPLASH_RADIUS = 4.0;

    private const MAX_AGE = 1200;
    private const VOID_DEPTH = 64;

    /** the thrower is not hit by its own potion right as it leaves the hand */
    private const OWNER_SAFE_TICKS = 5;

    private const STEP = 0.25;

    private Item $potion;

    private Vector3 $motion;

    private ?Living $owner;

    private int $age = 0;

    private bool $viewersUpdated = false;

    public function __construct(World $world, Vector3 $position, Item $potion, Vector3 $motion, ?Living $owner){
        $this->potion = (clone $potion)->setCount(1);
        $this->motion = $motion;
        $this->owner = $owner;

        parent::__construct($world, $position);
    }

    public function getNetworkTypeId() : string{
        return $this->isLingering() ? "minecraft:lingering_potion" : "minecraft:splash_potion";
    }

    public function isLingering() : bool{
        return $this->potion->getId() === ItemIds::LINGERING_POTION;
    }

    public function getPotionMeta() : int{
        return $this->potion->getMeta();
    }

    public function getMotion() : Vector3{
        return $this->motion;
    }

    public function getOwner() : ?Living{
        return $this->owner;
    }

    public function tick(int $currentTick) : void{
        if($this->closed){
            return;
        }

        if(++$this->age >= self::MAX_AGE || $this->position->y < Chunk::MIN_Y - self::VOID_DEPTH){
            $this->close();
            return;
        }

        if(!$this->world->isChunkLoaded($this->position->getChunkX(), $this->position->getChunkZ())){
            return;
        }

        if(!$this->viewersUpdated){
            $this->viewersUpdated = true;
            $this->updateViewers();
        }

        //step along this tick's path so a fast potion does not fly through a wall or a player
        $length = $this->motion->length();
        $steps = (int) max(1, ceil($length / self::STEP));

        for($step = 1; $step <= $steps; ++$step){
            $point = $this->position->add($this->motion->x * $step / $steps, $this->motion->y * $step / $steps, $this->motion->z * $step / $steps);
            $hit = $this->getLivingAt($point);

            if($hit !== null || $this->isBlockedAt($point)){
                $this->move($point, $this->yaw, $this->pitch);
                $this->splash($hit);
                return;
            }
        }

        $this->move($this->position->add($this->motion->x, $this->motion->y, $this->motion->z), $this->yaw, $this->pitch);
        $this->motion = new Vector3($this->motion->x * (1 - self::DRAG), $this->motion->y * (1 - self::DRAG) - self::GRAVITY, $this->motion->z * (1 - self::DRAG));
    }

    /**
     * Water puts out the campfires right around where the bottle broke.
     */
    private function douseCampfires() : void{
        [$x, $y, $z] = [$this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ()];

        for($bx = $x - 1; $bx <= $x + 1; ++$bx){
            for($bz = $z - 1; $bz <= $z + 1; ++$bz){
                if(!$this->world->isChunkLoaded($bx >> 4, $bz >> 4)){
                    continue;
                }

                for($by = max($y - 1, Chunk::MIN_Y); $by <= $y + 1 && $by <= Chunk::MAX_Y; ++$by){
                    $campfire = $this->world->getBlockEntity($bx, $by, $bz);

                    if($campfire instanceof Campfire){
                        $campfire->extinguish($this->world);
                    }
                }
            }
        }
    }

    private function isBlockedAt(Vector3 $point) : bool{
        [$x, $y, $z] = [(int) floor($point->x), (int) floor($point->y), (int) floor($point->z)];

        return $y >= Chunk::MIN_Y && $y <= Chunk::MAX_Y && $this->world->isChunkLoaded($x >> 4, $z >> 4) && BlockCollision::isSolid($this->world->getBlock($x, $y, $z));
    }

    private function getLivingAt(Vector3 $point) : ?Living{
        foreach($this->world->getPlayers() as $player){
            if(($player === $this->owner && $this->age <= self::OWNER_SAFE_TICKS) || !self::canBeHit($player)){
                continue;
            }

            $position = $player->getPosition();
            $half = Human::WIDTH / 2 + 0.125;

            if($point->x > $position->x - $half && $point->x < $position->x + $half && $point->z > $position->z - $half && $point->z < $position->z + $half && $point->y > $position->y - 0.125 && $point->y < $position->y + Human::HEIGHT + 0.125){
                return $player;
            }
        }

        return null;
    }

    private static function canBeHit(Player $player) : bool{
        return !$player->isDead() && $player->isSpawned() && $player->getGameMode() !== GameMode::SPECTATOR;
    }

    private function splash(?Living $direct) : void{
        $position = $this->position;
        $meta = $this->getPotionMeta();

        $this->world->broadcastPacket($position->getFloorX(), $position->getFloorZ(), LevelEventPacket::create(LevelEvent::PARTICLES_POTION_SPLASH, $position->x, $position->y, $position->z, Potion::getColor($meta)));
        $this->world->broadcastPacket($position->getFloorX(), $position->getFloorZ(), LevelSoundEventPacket::create(LevelSoundEvent::GLASS, $position->x, $position->y, $position->z));

        if($meta === 0){
            $this->douseCampfires();
        }

        if($this->isLingering()){
            new AreaEffectCloud($this->world, $position, $meta, $this->owner);
            $this->close();
            return;
        }

        $effects = Potion::getEffects($meta);

        if($effects !== []){
            foreach($this->world->getPlayers() as $player){
                if(!self::canBeHit($player)){
                    continue;
                }

                $target = $player->getPosition();

                if($target->x < $position->x - self::SPLASH_RADIUS || $target->x > $position->x + self::SPLASH_RADIUS || $target->z < $position->z - self::SPLASH_RADIUS || $target->z > $position->z + self::SPLASH_RADIUS || $target->y + Human::HEIGHT < $position->y - 2 || $target->y > $position->y + 2){
                    continue;
                }

                $distance = $target->distanceSquared($position);

                if($distance >= self::SPLASH_RADIUS ** 2){
                    continue;
                }

                $potency = $player === $direct ? 1.0 : 1.0 - sqrt($distance) / self::SPLASH_RADIUS;

                foreach($effects as $effect){
                    if($effect->getEffect()->isInstant()){
                        $player->getEffects()->add($effect, false, $potency);
                    }elseif(($duration = (int) ($effect->getDuration() * $potency + 0.5)) > 20){
                        $player->getEffects()->add(new EffectInstance($effect->getEffect(), $duration, $effect->getAmplifier()));
                    }
                }
            }
        }

        $this->close();
    }
}