<?php

declare(strict_types=1);

namespace raspberry\entity\projectile;

use raspberry\block\BlockIds;
use raspberry\block\Bells;
use raspberry\block\BlockCollision;

use raspberry\entity\Entity;
use raspberry\entity\Human;
use raspberry\entity\Living;

use raspberry\math\Facing;
use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\types\EntityMetadata;

use raspberry\player\GameMode;
use raspberry\player\Player;

use raspberry\world\Chunk;
use raspberry\world\World;

use function abs;
use function atan2;
use function ceil;
use function cos;
use function deg2rad;
use function floor;
use function max;
use function mt_getrandmax;
use function mt_rand;
use function rad2deg;
use function sin;
use function sqrt;

/**
 * Something thrown or shot: it flies with gravity and drag and hits the first living thing or
 * solid block in its way.
 */
abstract class Projectile extends Entity{

    private const int MAX_AGE = 1200;
    private const int VOID_DEPTH = 64;
    private const int VIEWER_UPDATE_TICKS = 20;

    /** the shooter is not hit by its own shot right as it leaves */
    private const int OWNER_SAFE_TICKS = 5;

    /** how much bigger than a target's box a hit counts */
    private const float HIT_MARGIN = 0.3;

    private const float STEP = 0.25;

    protected Vector3 $motion;

    protected ?Entity $owner;

    protected int $age = 0;

    private bool $viewersUpdated = false;

    public function __construct(World $world, Vector3 $position, Vector3 $motion, ?Entity $owner){
        $this->motion = $motion;
        $this->owner = $owner;

        parent::__construct($world, $position);

        $this->updateRotation();
    }

    abstract protected function getGravity() : float;

    abstract protected function onHitEntity(Living $target, Vector3 $point) : void;

    abstract protected function onHitBlock(int $x, int $y, int $z, Vector3 $point) : void;

    protected function hitsEntities() : bool{
        return true;
    }

    /** how much speed it keeps each tick */
    protected function getDrag() : float{
        return $this->isInWater() ? 0.8 : 0.99;
    }

    public function getWidth() : float{
        return 0.25;
    }

    public function getHeight() : float{
        return 0.25;
    }

    public function getMotion() : Vector3{
        return $this->motion;
    }

    public function setMotion(Vector3 $motion) : void{
        $this->motion = $motion;
        $this->updateRotation();
    }

    public function getOwner() : ?Entity{
        return $this->owner !== null && !$this->owner->isClosed() ? $this->owner : null;
    }

    /**
     * A motion toward x y z at $velocity, spread a little by $inaccuracy the way Java aims.
     */
    public static function aim(float $x, float $y, float $z, float $velocity, float $inaccuracy) : Vector3{
        $length = sqrt($x * $x + $y * $y + $z * $z);

        if($length < 1.0e-7){
            return new Vector3(0.0, 0.0, 0.0);
        }

        $spread = fn() : float => (mt_rand() / mt_getrandmax() - mt_rand() / mt_getrandmax()) * 0.0172275 * $inaccuracy;

        return new Vector3(
            ($x / $length + $spread()) * $velocity,
            ($y / $length + $spread()) * $velocity,
            ($z / $length + $spread()) * $velocity
        );
    }

    /**
     * A motion where yaw and pitch look, for something thrown or shot by a player.
     */
    public static function aimFromRotation(float $yaw, float $pitch, float $velocity, float $inaccuracy) : Vector3{
        $yaw = deg2rad($yaw);
        $pitch = deg2rad($pitch);
        return self::aim(-sin($yaw) * cos($pitch), -sin($pitch), cos($yaw) * cos($pitch), $velocity, $inaccuracy);
    }

    public function createMetadata() : EntityMetadata{
        $metadata = new EntityMetadata();
        $metadata->setLong(EntityMetadata::KEY_FLAGS_TWO, 0);
        $metadata->setFlag(EntityMetadata::FLAG_HAS_GRAVITY);
        $metadata->setFlag(EntityMetadata::FLAG_HAS_COLLISION);
        $metadata->setFloat(EntityMetadata::KEY_SCALE, 1.0);
        $metadata->setFloat(EntityMetadata::KEY_WIDTH, $this->getWidth());
        $metadata->setFloat(EntityMetadata::KEY_HEIGHT, $this->getHeight());
        $metadata->setLong(EntityMetadata::KEY_OWNER, $this->getOwner()?->getId() ?? -1);
        return $metadata;
    }

    public function tick(int $currentTick) : void{
        if($this->closed){
            return;
        }

        if(++$this->age >= self::MAX_AGE || $this->position->y < $this->world->getMinY() - self::VOID_DEPTH){
            $this->close();
            return;
        }

        if(!$this->world->isChunkLoaded($this->position->getChunkX(), $this->position->getChunkZ())){
            return;
        }

        if(!$this->viewersUpdated || $currentTick % self::VIEWER_UPDATE_TICKS === 0){
            $this->viewersUpdated = true;
            $this->updateViewers();
        }

        $this->fly();
    }

    /**
     * Steps along this tick's path, so a fast shot does not go through a wall or a mob.
     */
    protected function fly() : void{
        $steps = (int) max(1, ceil($this->motion->length() / self::STEP));

        for($step = 1; $step <= $steps; ++$step){
            $point = $this->position->add($this->motion->x * $step / $steps, $this->motion->y * $step / $steps, $this->motion->z * $step / $steps);
            $target = $this->hitsEntities() ? $this->getLivingAt($point) : null;

            if($target !== null){
                $this->move($point, $this->yaw, $this->pitch);
                $this->onHitEntity($target, $point);
                return;
            }

            [$bx, $by, $bz] = [(int) floor($point->x), (int) floor($point->y), (int) floor($point->z)];

            if($this->isSolidAt($bx, $by, $bz)){
                $hit = $this->world->getBlock($bx, $by, $bz)->getName();

                if($hit === BlockIds::TARGET){
                    $from = $this->position->add($this->motion->x * ($step - 1) / $steps, $this->motion->y * ($step - 1) / $steps, $this->motion->z * ($step - 1) / $steps);
                    $this->world->getRedstoneUpdater()->hitTarget($bx, $by, $bz, $from, $point, $this instanceof Arrow);
                }elseif($hit === BlockIds::BELL){
                    $face = abs($this->motion->x) > abs($this->motion->z) ? ($this->motion->x > 0 ? Facing::WEST : Facing::EAST) : ($this->motion->z > 0 ? Facing::NORTH : Facing::SOUTH);
                    Bells::ring($this->world, $bx, $by, $bz, $face);
                }

                $this->onHitBlock($bx, $by, $bz, $point);
                return;
            }
        }

        $drag = $this->getDrag();
        $this->move($this->position->add($this->motion->x, $this->motion->y, $this->motion->z), $this->yaw, $this->pitch);
        $this->motion = new Vector3($this->motion->x * $drag, $this->motion->y * $drag - $this->getGravity(), $this->motion->z * $drag);
        $this->updateRotation();
    }

    protected function updateRotation() : void{
        $flat = sqrt($this->motion->x ** 2 + $this->motion->z ** 2);
        $this->setRotation(rad2deg(atan2($this->motion->x, $this->motion->z)), rad2deg(atan2($this->motion->y, $flat)));
    }

    protected function isSolidAt(int $x, int $y, int $z) : bool{
        return $y >= Chunk::MIN_Y && $y <= Chunk::MAX_Y && $this->world->isChunkLoaded($x >> 4, $z >> 4) && BlockCollision::isSolid($this->world->getBlock($x, $y, $z));
    }

    protected function isInWater() : bool{
        $name = $this->world->isChunkLoaded($this->position->getChunkX(), $this->position->getChunkZ()) && $this->position->y >= Chunk::MIN_Y && $this->position->y <= Chunk::MAX_Y
            ? $this->world->getBlock($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ())->getName()
            : "";

        return $name === "minecraft:water" || $name === "minecraft:flowing_water";
    }

    private function getLivingAt(Vector3 $point) : ?Living{
        foreach($this->world->getEntities() as $entity){
            if(!$entity instanceof Living || $entity->isClosed() || !$entity->isAlive() || ($entity === $this->owner && $this->age <= self::OWNER_SAFE_TICKS)){
                continue;
            }

            if($entity instanceof Player && (!$entity->isSpawned() || $entity->getGameMode() === GameMode::SPECTATOR)){
                continue;
            }

            $position = $entity->getPosition();
            [$width, $height] = $entity instanceof Human ? [Human::WIDTH, Human::HEIGHT] : [$entity->getWidth(), $entity->getHeight()];
            $half = $width / 2 + self::HIT_MARGIN;

            if($point->x > $position->x - $half && $point->x < $position->x + $half && $point->z > $position->z - $half && $point->z < $position->z + $half &&
                $point->y > $position->y - self::HIT_MARGIN && $point->y < $position->y + $height + self::HIT_MARGIN){
                return $entity;
            }
        }

        return null;
    }
}