<?php

declare(strict_types=1);

namespace raspberry\entity\projectile;

use raspberry\block\BlockIds;
use raspberry\block\BlockProperties;

use raspberry\entity\Entity;
use raspberry\entity\EntityIds;
use raspberry\entity\Human;
use raspberry\entity\Living;
use raspberry\entity\object\ExperienceOrb;
use raspberry\entity\object\ItemEntity;

use raspberry\item\FishingLoot;
use raspberry\item\ItemIds;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelEvent;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;
use raspberry\network\mcpe\protocol\types\ParticleType;

use raspberry\player\GameMode;
use raspberry\player\Player;

use raspberry\world\Chunk;
use raspberry\world\World;
use raspberry\world\biome\BiomeTags;

use function abs;
use function ceil;
use function cos;
use function deg2rad;
use function floor;
use function max;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function sin;
use function sqrt;

use const M_PI;

class FishingHook extends Projectile{

    private const int FLYING = 0;
    private const int HOOKED = 1;
    private const int BOBBING = 2;

    private const float SIZE = 0.15;

    private const float GRAVITY = 0.03;
    private const float DRAG = 0.92;

    private const float MAX_DISTANCE_SQUARED = 1024.0;

    private const int MAX_GROUND_TICKS = 1200;

    private const int NIBBLE_TICKS = 10;

    private const int LURE_TICKS = 100;

    private const float STEP = 0.25;

    private const float HIT_MARGIN = 0.3;

    private int $state = self::FLYING;

    private ?Entity $hooked = null;

    private bool $onGround = false;

    private int $groundTicks = 0;

    private int $timeUntilLured = 0;

    private int $timeUntilHooked = 0;

    private int $nibble = 0;

    private float $fishAngle = 0.0;

    private int $lure;

    private int $luck;

    private bool $jungle;

    private bool $viewersSent = false;

    public function __construct(World $world, Player $owner, int $lure, int $luck){
        $this->lure = $lure;
        $this->luck = $luck;

        $yaw = deg2rad($owner->getYaw());
        $pitch = deg2rad($owner->getPitch());
        $h = cos(-$yaw - M_PI);
        $i = sin(-$yaw - M_PI);
        $j = -cos(-$pitch);
        $k = sin(-$pitch);

        $feet = $owner->getPosition();
        $position = new Vector3($feet->x - $i * 0.3, $owner->getEyePosition()->y, $feet->z - $h * 0.3);

        $x = -$i;
        $y = max(-5.0, min(5.0, -($k / $j)));
        $z = -$h;
        $length = sqrt($x * $x + $y * $y + $z * $z);
        $scale = fn() : float => 0.6 / $length + 0.5 + 0.0103365 * (self::random() - self::random());

        $this->jungle = $world->getBiome($position->getFloorX(), $position->getFloorY(), $position->getFloorZ())->hasTag(BiomeTags::JUNGLE);

        parent::__construct($world, $position, new Vector3($x * $scale(), $y * $scale(), $z * $scale()), $owner);
    }

    public function getNetworkTypeId() : string{
        return EntityIds::FISHING_HOOK;
    }

    public function getWidth() : float{
        return self::SIZE;
    }

    public function getHeight() : float{
        return self::SIZE;
    }

    public function getHooked() : ?Entity{
        return $this->hooked;
    }

    public function isOnGround() : bool{
        return $this->onGround;
    }

    protected function getGravity() : float{
        return self::GRAVITY;
    }

    protected function onHitEntity(Living $target, Vector3 $point) : void{
        $this->hooked = $target;
    }

    protected function onHitBlock(int $x, int $y, int $z, Vector3 $point) : void{

    }

    public function tick(int $currentTick) : void{
        if($this->closed){
            return;
        }

        $owner = $this->getOwner();

        if(!$owner instanceof Player || $this->shouldStop($owner)){
            $this->close();
            return;
        }

        if(!$this->world->isChunkLoaded($this->position->getChunkX(), $this->position->getChunkZ())){
            return;
        }

        if(!$this->viewersSent || $currentTick % 20 === 0){
            $this->viewersSent = true;
            $this->updateViewers();
        }

        if($this->onGround){
            if(++$this->groundTicks >= self::MAX_GROUND_TICKS){
                $this->close();
                return;
            }
        }else{
            $this->groundTicks = 0;
        }

        $surface = $this->getWaterHeight($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
        $inWater = $surface > 0;

        if($this->state === self::FLYING){
            if($this->hooked !== null){
                $this->motion = Vector3::zero();
                $this->state = self::HOOKED;
                return;
            }

            if($inWater){
                $this->motion = new Vector3($this->motion->x * 0.3, $this->motion->y * 0.2, $this->motion->z * 0.3);
                $this->state = self::BOBBING;
                return;
            }
        }elseif($this->state === self::HOOKED){
            $this->followHooked();
            return;
        }else{
            $d = $this->position->y + $this->motion->y - $this->position->getFloorY() - $surface;

            if(abs($d) < 0.01){
                $d += $d > 0 ? 0.1 : ($d < 0 ? -0.1 : 0.0);
            }

            $this->motion = new Vector3($this->motion->x * 0.9, $this->motion->y - $d * self::random() * 0.2, $this->motion->z * 0.9);

            if($inWater){
                if($this->nibble > 0){
                    $this->motion = $this->motion->add(0, -0.1 * self::random() * self::random(), 0);
                }

                $this->catchFish();
            }
        }

        if(!$inWater){
            $this->motion = $this->motion->add(0, -self::GRAVITY, 0);
        }

        $this->step();

        $this->motion = new Vector3($this->motion->x * self::DRAG, $this->motion->y * self::DRAG, $this->motion->z * self::DRAG);
    }

    public function reel() : int{
        $owner = $this->getOwner();

        if(!$owner instanceof Player || $this->closed){
            $this->close();
            return 0;
        }

        $wear = 0;
        $target = $owner->getPosition();

        if($this->hooked !== null && !$this->hooked->isClosed()){
            $this->pull($this->hooked, $target);
            $wear = $this->hooked instanceof ItemEntity ? 3 : 5;
        }elseif($this->nibble > 0){
            $dx = $target->x - $this->position->x;
            $dy = $target->y - $this->position->y;
            $dz = $target->z - $this->position->z;
            $motion = new Vector3($dx * 0.1, $dy * 0.1 + sqrt(sqrt($dx * $dx + $dy * $dy + $dz * $dz)) * 0.08, $dz * 0.1);

            new ItemEntity($this->world, $this->position, FishingLoot::roll($this->luck, $this->jungle), $motion);
            new ExperienceOrb($this->world, new Vector3($target->x, $target->y + 0.5, $target->z + 0.5), mt_rand(1, 6));
            $wear = 1;
        }

        if($this->onGround){
            $wear = 2;
        }

        $this->close();

        return $wear;
    }

    private function shouldStop(Player $owner) : bool{
        return !$owner->isAlive() || $owner->getWorld() !== $this->world || $owner->getGameMode() === GameMode::SPECTATOR ||
            $owner->getInventory()->getItemInHand()->getId() !== ItemIds::FISHING_ROD || $owner->getPosition()->distanceSquared($this->position) > self::MAX_DISTANCE_SQUARED;
    }

    private function followHooked() : void{
        $hooked = $this->hooked;

        if($hooked === null || $hooked->isClosed() || $hooked->getWorld() !== $this->world || ($hooked instanceof Living && !$hooked->isAlive())){
            $this->hooked = null;
            $this->state = self::FLYING;
            return;
        }

        $position = $hooked->getPosition();
        $this->move(new Vector3($position->x, $position->y + self::heightOf($hooked) * 0.8, $position->z), $this->yaw, $this->pitch);
    }

    private function pull(Entity $entity, Vector3 $to) : void{
        $pull = new Vector3(($to->x - $this->position->x) * 0.1, ($to->y - $this->position->y) * 0.1, ($to->z - $this->position->z) * 0.1);

        if($entity instanceof Living || $entity instanceof ItemEntity){
            $entity->setMotion($entity->getMotion()->add($pull->x, $pull->y, $pull->z));
        }
    }

    private function catchFish() : void{
        $x = $this->position->getFloorX();
        $y = $this->position->getFloorY();
        $z = $this->position->getFloorZ();
        $speed = 1;

        if(self::random() < 0.25 && $this->world->getWeather()->isRaining() && $this->world->getBiome($x, $y + 1, $z)->canRainAt($y + 1)){
            ++$speed;
        }

        if(self::random() < 0.5 && !$this->world->canSeeSky($x, $y + 1, $z)){
            --$speed;
        }

        if($this->nibble > 0){
            if(--$this->nibble <= 0){
                $this->timeUntilLured = 0;
                $this->timeUntilHooked = 0;
            }
        }elseif($this->timeUntilHooked > 0){
            $this->timeUntilHooked -= $speed;

            if($this->timeUntilHooked > 0){
                $this->fishAngle += 9.188 * (self::random() - self::random());
                $angle = deg2rad($this->fishAngle);
                $fishX = $this->position->x + sin($angle) * $this->timeUntilHooked * 0.1;
                $fishY = floor($this->position->y) + 1.0;
                $fishZ = $this->position->z + cos($angle) * $this->timeUntilHooked * 0.1;

                if($this->getWaterHeight((int) floor($fishX), (int) $fishY - 1, (int) floor($fishZ)) > 0){
                    if(self::random() < 0.15){
                        $this->particle(ParticleType::BUBBLE, $fishX, $fishY - 0.1, $fishZ);
                    }

                    $this->particle(ParticleType::WATER_WAKE, $fishX, $fishY, $fishZ);
                }
            }else{
                $packet = LevelSoundEventPacket::create(LevelSoundEvent::SPLASH, $this->position->x, $this->position->y, $this->position->z);
                $packet->entityType = EntityIds::FISHING_HOOK;
                $this->world->broadcastPacket($x, $z, $packet);

                for($i = (int) (1 + self::SIZE * 20); $i > 0; --$i){
                    $spreadX = (self::random() * 2 - 1) * self::SIZE;
                    $spreadZ = (self::random() * 2 - 1) * self::SIZE;
                    $this->particle(ParticleType::BUBBLE, $this->position->x + $spreadX, $this->position->y + 0.5, $this->position->z + $spreadZ);
                    $this->particle(ParticleType::WATER_WAKE, $this->position->x + $spreadX, $this->position->y + 0.5, $this->position->z + $spreadZ);
                }

                $this->nibble = self::NIBBLE_TICKS;
            }
        }elseif($this->timeUntilLured > 0){
            $this->timeUntilLured -= $speed;
            $chance = 0.15;

            if($this->timeUntilLured < 20){
                $chance += (20 - $this->timeUntilLured) * 0.05;
            }elseif($this->timeUntilLured < 40){
                $chance += (40 - $this->timeUntilLured) * 0.02;
            }elseif($this->timeUntilLured < 60){
                $chance += (60 - $this->timeUntilLured) * 0.01;
            }

            if(self::random() < $chance){
                $angle = deg2rad(self::random() * 360);
                $distance = 25 + self::random() * 35;
                $splashX = $this->position->x + sin($angle) * $distance * 0.1;
                $splashY = floor($this->position->y) + 1.0;
                $splashZ = $this->position->z + cos($angle) * $distance * 0.1;

                if($this->getWaterHeight((int) floor($splashX), (int) $splashY - 1, (int) floor($splashZ)) > 0){
                    $this->particle(ParticleType::WATER_SPLASH, $splashX, $splashY, $splashZ);
                }
            }

            if($this->timeUntilLured <= 0){
                $this->fishAngle = self::random() * 360;
                $this->timeUntilHooked = mt_rand(20, 80);
            }
        }else{
            $this->timeUntilLured = mt_rand(100, 600) - $this->lure * self::LURE_TICKS;
        }
    }

    /**
     * Moves by the motion, stopping at blocks; a hook in flight catches the first entity in its way.
     */
    private function step() : void{
        $steps = (int) max(1, ceil($this->motion->length() / self::STEP));
        [$x, $y, $z] = [$this->position->x, $this->position->y, $this->position->z];
        [$dx, $dy, $dz] = [$this->motion->x / $steps, $this->motion->y / $steps, $this->motion->z / $steps];
        $ground = false;
        $wall = false;

        for($i = 0; $i < $steps; ++$i){
            if($dy !== 0.0){
                if($this->isBlocked($x, $y + $dy, $z)){
                    $ground = $ground || $dy < 0;
                    $y = $dy < 0 ? floor($y + $dy) + 1.0 : floor($y + $dy + self::SIZE) - self::SIZE;
                    $dy = 0.0;
                }else{
                    $y += $dy;
                }
            }

            if($dx !== 0.0){
                if($this->isBlocked($x + $dx, $y, $z)){
                    $wall = true;
                    $dx = 0.0;
                }else{
                    $x += $dx;
                }
            }

            if($dz !== 0.0){
                if($this->isBlocked($x, $y, $z + $dz)){
                    $wall = true;
                    $dz = 0.0;
                }else{
                    $z += $dz;
                }
            }

            if($this->state === self::FLYING){
                $hit = $this->findEntityAt($x, $y, $z);

                if($hit !== null){
                    $this->hooked = $hit;
                    break;
                }
            }
        }

        $this->onGround = $ground;
        $this->motion = new Vector3($dx === 0.0 ? 0.0 : $this->motion->x, $dy === 0.0 ? 0.0 : $this->motion->y, $dz === 0.0 ? 0.0 : $this->motion->z);

        if($this->state === self::FLYING && ($ground || $wall)){
            $this->motion = Vector3::zero();
        }

        $this->move(new Vector3($x, $y, $z), $this->yaw, $this->pitch);
        $this->updateRotation();
    }

    private function isBlocked(float $x, float $y, float $z) : bool{
        $half = self::SIZE / 2;

        for($bx = (int) floor($x - $half); $bx <= (int) floor($x + $half); ++$bx){
            for($by = (int) floor($y); $by <= (int) floor($y + self::SIZE); ++$by){
                for($bz = (int) floor($z - $half); $bz <= (int) floor($z + $half); ++$bz){
                    if($this->isSolidAt($bx, $by, $bz)){
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function findEntityAt(float $x, float $y, float $z) : ?Entity{
        $owner = $this->getOwner();

        foreach($this->world->getEntities() as $entity){
            if($entity === $this || $entity === $owner || $entity->isClosed() || (!$entity instanceof Living && !$entity instanceof ItemEntity)){
                continue;
            }

            if($entity instanceof Living && !$entity->isAlive()){
                continue;
            }

            if($entity instanceof Player && (!$entity->isSpawned() || $entity->getGameMode() === GameMode::SPECTATOR)){
                continue;
            }

            $position = $entity->getPosition();
            $half = self::widthOf($entity) / 2 + self::HIT_MARGIN;

            if($x > $position->x - $half && $x < $position->x + $half && $z > $position->z - $half && $z < $position->z + $half &&
                $y > $position->y - self::HIT_MARGIN && $y < $position->y + self::heightOf($entity) + self::HIT_MARGIN){
                return $entity;
            }
        }

        return null;
    }

    private function getWaterHeight(int $x, int $y, int $z) : float{
        if(!$this->isWaterAt($x, $y, $z)){
            return 0.0;
        }

        if($this->isWaterAt($x, $y + 1, $z)){
            return 1.0;
        }

        $block = $this->world->getBlock($x, $y, $z);
        $depth = $block->hasProperty(BlockProperties::LIQUID_DEPTH) ? (int) $block->getProperty(BlockProperties::LIQUID_DEPTH) : 0;

        return ($depth >= 8 ? 8 : 8 - $depth) / 9;
    }

    private function isWaterAt(int $x, int $y, int $z) : bool{
        if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y || !$this->world->isChunkLoaded($x >> 4, $z >> 4)){
            return false;
        }

        $name = $this->world->getBlock($x, $y, $z)->getName();

        return $name === BlockIds::WATER || $name === BlockIds::FLOWING_WATER;
    }

    private function particle(int $type, float $x, float $y, float $z) : void{
        $this->world->broadcastPacket((int) floor($x), (int) floor($z), LevelEventPacket::create(LevelEvent::PARTICLE_LEGACY_EVENT | $type, $x, $y, $z));
    }

    private static function widthOf(Entity $entity) : float{
        return $entity instanceof Human ? Human::WIDTH : ($entity instanceof ItemEntity ? ItemEntity::SIZE : $entity->getWidth());
    }

    private static function heightOf(Entity $entity) : float{
        return $entity instanceof Human ? Human::HEIGHT : ($entity instanceof ItemEntity ? ItemEntity::SIZE : $entity->getHeight());
    }

    private static function random() : float{
        return mt_rand() / mt_getrandmax();
    }
}