<?php

declare(strict_types=1);

namespace raspberry\entity\object;

use raspberry\block\BlockIds;
use raspberry\block\BlockState;
use raspberry\block\Rails;

use raspberry\entity\Entity;
use raspberry\entity\Human;
use raspberry\entity\Living;
use raspberry\entity\Vehicle;

use raspberry\item\Item;

use raspberry\math\Vector3;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\SetActorMotionPacket;
use raspberry\network\mcpe\protocol\types\EntityMetadata;

use raspberry\player\Player;

use raspberry\world\Chunk;
use raspberry\world\GameRules;
use raspberry\world\World;

use function abs;
use function atan2;
use function cos;
use function deg2rad;
use function floor;
use function fmod;
use function in_array;
use function max;
use function min;
use function sin;
use function sqrt;

use const M_PI;

abstract class Cart extends Vehicle{
    use BlockPhysics;

    public const float WIDTH = 0.98;
    public const float HEIGHT = 0.7;

    public const float NETWORK_OFFSET = 0.35;

    public const float MAX_SPEED = 0.4;
    public const float MAX_SPEED_IN_WATER = 0.2;

    public const float RAIL_HEIGHT = 0.0625;

    private const float GRAVITY = 0.04;
    private const float GRAVITY_IN_WATER = 0.005;

    private const float SLOPE_PULL = 0.0078125;

    private const float POWERED_BOOST = 0.06;
    private const float POWERED_START = 0.02;

    private const float BRAKE_STOP = 0.03;

    private const float LAVA_DAMAGE = 4.0;

    private const float PUSH_REACH = 0.2;

    private bool $onRail = false;

    private bool $flipped = false;

    private bool $viewersSent = false;

    public function __construct(World $world, Vector3 $position, float $yaw = 0.0){
        $this->motion = Vector3::zero();
        $this->yaw = $yaw;

        parent::__construct($world, $position);
    }

    public static function place(World $world, int $x, int $y, int $z, BlockState $rail) : static{
        $shape = Rails::shapeOf($rail);
        $yaw = in_array($shape, [Rails::NORTH_SOUTH, Rails::ASCENDING_NORTH, Rails::ASCENDING_SOUTH], true) ? 90.0 : 0.0;

        return new static($world, new Vector3($x + 0.5, $y + self::RAIL_HEIGHT + (Rails::isAscending($shape) ? 0.5 : 0.0), $z + 0.5), $yaw);
    }

    public static function fromSave(World $world, CompoundTag $tag) : ?static{
        [$x, $y, $z] = [$tag->getDouble("X"), $tag->getDouble("Y"), $tag->getDouble("Z")];

        if($x === null || $y === null || $z === null){
            return null;
        }

        $cart = new static($world, new Vector3($x, $y, $z), $tag->getFloat("Yaw", 0.0) ?? 0.0);
        $cart->motion = new Vector3($tag->getFloat("MotionX", 0.0) ?? 0.0, $tag->getFloat("MotionY", 0.0) ?? 0.0, $tag->getFloat("MotionZ", 0.0) ?? 0.0);
        $cart->flipped = ($tag->getByte("Flipped", 0) ?? 0) === 1;

        return $cart;
    }

    public function save() : ?CompoundTag{
        if($this->closed){
            return null;
        }

        $tag = new CompoundTag();
        $tag->setString("identifier", $this->getNetworkTypeId());
        $tag->setDouble("X", $this->position->x);
        $tag->setDouble("Y", $this->position->y);
        $tag->setDouble("Z", $this->position->z);
        $tag->setFloat("Yaw", $this->yaw);
        $tag->setFloat("MotionX", $this->motion->x);
        $tag->setFloat("MotionY", $this->motion->y);
        $tag->setFloat("MotionZ", $this->motion->z);
        $tag->setByte("Flipped", $this->flipped ? 1 : 0);
        return $tag;
    }

    abstract public function getItemId() : string;

    public function getNetworkOffset() : float{
        return self::NETWORK_OFFSET;
    }

    public function getWidth() : float{
        return self::WIDTH;
    }

    public function getHeight() : float{
        return self::HEIGHT;
    }

    public function isOnRail() : bool{
        return $this->onRail;
    }

    public function createMetadata() : EntityMetadata{
        $metadata = new EntityMetadata();
        $metadata->setFlag(EntityMetadata::FLAG_HAS_GRAVITY);
        $metadata->setFlag(EntityMetadata::FLAG_HAS_COLLISION);
        $metadata->setFlag(EntityMetadata::FLAG_STACKABLE);
        $metadata->setFlag(EntityMetadata::FLAG_COLLIDABLE);
        $metadata->setInt(EntityMetadata::KEY_HEALTH, (int) $this->damage);
        $metadata->setInt(EntityMetadata::KEY_HURT_TIME, $this->hurtTime);
        $metadata->setInt(EntityMetadata::KEY_HURT_DIRECTION, $this->hurtDirection);
        $metadata->setFloat(EntityMetadata::KEY_SCALE, 1.0);
        $metadata->setFloat(EntityMetadata::KEY_WIDTH, self::WIDTH);
        $metadata->setFloat(EntityMetadata::KEY_HEIGHT, self::HEIGHT);
        $this->addMetadata($metadata);

        return $metadata;
    }

    protected function addMetadata(EntityMetadata $metadata) : void{
    }

    public function getSeat(Player $rider) : Vector3{
        return new Vector3(0.0, -0.2, 0.0);
    }

    public function canBoard(Player $player) : bool{
        return false;
    }

    public function getInteractText(Player $player) : string{
        return "";
    }

    public function interact(Player $player) : void{
    }

    public function destroy(bool $drop) : void{
        if($this->closed){
            return;
        }

        $this->removePassengers(true);

        if($drop && $this->world->getGameRules()->getBool(GameRules::DO_ENTITY_DROPS)){
            $this->world->dropItem($this->position->add(0, 0.5, 0), new Item($this->getItemId()));
        }

        $this->close();
    }

    public function overlaps(float $x1, float $y1, float $z1, float $x2, float $y2, float $z2) : bool{
        $half = self::WIDTH / 2;
        $position = $this->position;

        return $position->x - $half < $x2 && $position->x + $half > $x1 &&
            $position->y < $y2 && $position->y + self::HEIGHT > $y1 &&
            $position->z - $half < $z2 && $position->z + $half > $z1;
    }

    public function tick(int $currentTick) : void{
        if($this->closed){
            return;
        }

        if($this->position->y < Chunk::MIN_Y - 64){
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

        $this->dropLostPassengers();

        if($this->recover()){
            $this->broadcastMetadata();
        }

        $start = $this->position;
        $startYaw = $this->yaw;
        $startMotion = $this->motion;
        $water = $this->isInWater();

        $this->motion = $this->motion->add(0, $water ? -self::GRAVITY_IN_WATER : -self::GRAVITY, 0);

        [$x, $y, $z] = [$this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ()];

        if(Rails::isRailAt($this->world, $x, $y - 1, $z)){
            --$y;
        }

        $rail = Rails::blockAt($this->world, $x, $y, $z);
        $this->onRail = Rails::isRail($rail->getName());

        if($this->onRail){
            $this->rollAlong($x, $y, $z, $rail, $water);

            if($rail->getName() === BlockIds::ACTIVATOR_RAIL){
                $this->onActivatorRail(Rails::isPowered($rail));
            }elseif($rail->getName() === BlockIds::DETECTOR_RAIL && !Rails::isPowered($rail)){
                Rails::sense($this->world, $x, $y, $z);
            }
        }else{
            $this->roll($water);
        }

        if($this->closed){
            return;
        }

        $this->turn($start);
        $this->bump();

        if($this->isInLava()){
            $this->burn();

            if($this->closed){
                return;
            }
        }

        if(!$this->position->equals($start) || $this->yaw !== $startYaw){
            foreach($this->viewers as $viewer){
                $viewer->getNetworkSession()->moveEntity($this);
            }
        }

        if(!$this->motion->equals($startMotion)){
            foreach($this->viewers as $viewer){
                $viewer->getNetworkSession()->sendDataPacket(SetActorMotionPacket::create($this->id, $this->motion->x, $this->motion->y, $this->motion->z, 0));
            }
        }

        $this->updatePassengers();
    }

    public function pushFrom(Entity $other) : void{
        if(in_array($other, $this->passengers, true)){
            return;
        }

        $position = $other->getPosition();
        $dx = $position->x - $this->position->x;
        $dz = $position->z - $this->position->z;
        $distance = sqrt($dx * $dx + $dz * $dz);

        if($distance < 0.01){
            return;
        }

        $nx = $dx / $distance;
        $nz = $dz / $distance;
        $strength = min(1.0, 1.0 / $distance) * 0.05;
        $px = $nx * $strength;
        $pz = $nz * $strength;

        if($other instanceof self){
            $yaw = deg2rad($this->yaw);

            if(abs($nx * cos($yaw) + $nz * sin($yaw)) < 0.8){
                return;
            }

            $mine = $this->motion;
            $theirs = $other->getMotion();
            $averageX = ($theirs->x + $mine->x) / 2;
            $averageZ = ($theirs->z + $mine->z) / 2;

            $this->motion = new Vector3($mine->x * 0.2 + $averageX - $px, $mine->y, $mine->z * 0.2 + $averageZ - $pz);
            $other->setMotion(new Vector3($theirs->x * 0.2 + $averageX + $px, $theirs->y, $theirs->z * 0.2 + $averageZ + $pz));
            return;
        }

        $this->motion = $this->motion->add(-$px, 0, -$pz);

        if($other instanceof Living && !$other instanceof Player){
            $other->setMotion($other->getMotion()->add($px / 4, 0, $pz / 4));
        }
    }

    protected function onActivatorRail(bool $powered) : void{
    }

    protected function getRiderPush() : ?Vector3{
        return null;
    }

    protected function getFriction() : float{
        return $this->passengers === [] ? 0.96 : 0.997;
    }

    protected function burn() : void{
        $this->hurt(self::LAVA_DAMAGE);
    }

    private function rollAlong(int $x, int $y, int $z, BlockState $rail, bool $water) : void{
        $this->fallDistance = 0.0;
        [$px, $py, $pz] = [$this->position->x, $this->position->y, $this->position->z];
        $before = $this->railPoint($px, $py, $pz);
        $py = (float) $y;
        $shape = Rails::shapeOf($rail);
        $boost = $rail->getName() === BlockIds::GOLDEN_RAIL && Rails::isPowered($rail);
        $brake = $rail->getName() === BlockIds::GOLDEN_RAIL && !$boost;
        $pull = self::SLOPE_PULL * ($water ? 0.2 : 1.0);
        [$mx, $my, $mz] = [$this->motion->x, $this->motion->y, $this->motion->z];

        switch($shape){
            case Rails::ASCENDING_EAST:
                $mx -= $pull;
                ++$py;
                break;
            case Rails::ASCENDING_WEST:
                $mx += $pull;
                ++$py;
                break;
            case Rails::ASCENDING_NORTH:
                $mz += $pull;
                ++$py;
                break;
            case Rails::ASCENDING_SOUTH:
                $mz -= $pull;
                ++$py;
                break;
        }

        [$from, $to] = Rails::EXITS[$shape];
        $dx = $to[0] - $from[0];
        $dz = $to[2] - $from[2];
        $length = sqrt($dx * $dx + $dz * $dz);

        if($mx * $dx + $mz * $dz < 0){
            $dx = -$dx;
            $dz = -$dz;
        }

        $speed = min(2.0, sqrt($mx * $mx + $mz * $mz));
        $mx = $speed * $dx / $length;
        $mz = $speed * $dz / $length;

        $push = $this->getRiderPush();

        if($push !== null && $push->x * $push->x + $push->z * $push->z > 1.0E-4 && $mx * $mx + $mz * $mz < 0.01){
            $mx += $push->x * 0.1;
            $mz += $push->z * 0.1;
            $brake = false;
        }

        if($brake){
            if(sqrt($mx * $mx + $mz * $mz) < self::BRAKE_STOP){
                [$mx, $mz] = [0.0, 0.0];
            }else{
                $mx *= 0.5;
                $mz *= 0.5;
            }

            $my = 0.0;
        }

        $startX = $x + 0.5 + $from[0] * 0.5;
        $startZ = $z + 0.5 + $from[2] * 0.5;
        $ex = $to[0] * 0.5 - $from[0] * 0.5;
        $ez = $to[2] * 0.5 - $from[2] * 0.5;

        $along = match(true){
            $ex == 0.0 => $pz - $z,
            $ez == 0.0 => $px - $x,
            default => (($px - $startX) * $ex + ($pz - $startZ) * $ez) * 2.0
        };

        $px = $startX + $ex * $along;
        $pz = $startZ + $ez * $along;
        $this->setPosition(new Vector3($px, $py, $pz));

        $scale = $this->passengers === [] ? 1.0 : 0.75;
        $max = $water ? self::MAX_SPEED_IN_WATER : self::MAX_SPEED;
        $stepX = max(-$max, min($max, $scale * $mx));
        $stepZ = max(-$max, min($max, $scale * $mz));
        [$movedX, , $movedZ] = $this->collide($stepX, 0.0, $stepZ);
        $px += $movedX;
        $pz += $movedZ;
        $mx = $movedX !== $stepX ? 0.0 : $mx;
        $mz = $movedZ !== $stepZ ? 0.0 : $mz;

        $blockX = (int) floor($px) - $x;
        $blockZ = (int) floor($pz) - $z;

        if($from[1] !== 0 && $blockX === $from[0] && $blockZ === $from[2]){
            $py += $from[1];
        }elseif($to[1] !== 0 && $blockX === $to[0] && $blockZ === $to[2]){
            $py += $to[1];
        }

        $friction = $this->getFriction() * ($water ? 0.95 : 1.0);
        $mx *= $friction;
        $mz *= $friction;
        $my = 0.0;

        $after = $this->railPoint($px, $py, $pz);

        if($after !== null && $before !== null){
            $drop = ($before[1] - $after[1]) * 0.05;
            $speed = sqrt($mx * $mx + $mz * $mz);

            if($speed > 0.0){
                $mx *= ($speed + $drop) / $speed;
                $mz *= ($speed + $drop) / $speed;
            }

            $py = $after[1];
        }

        $leftX = (int) floor($px);
        $leftZ = (int) floor($pz);

        if($leftX !== $x || $leftZ !== $z){
            $speed = sqrt($mx * $mx + $mz * $mz);
            $mx = $speed * ($leftX - $x);
            $mz = $speed * ($leftZ - $z);
        }

        if($boost){
            $speed = sqrt($mx * $mx + $mz * $mz);

            if($speed > 0.01){
                $mx += $mx / $speed * self::POWERED_BOOST;
                $mz += $mz / $speed * self::POWERED_BOOST;
            }elseif($shape === Rails::EAST_WEST){
                $mx = match(true){
                    $this->isConductorAt($x - 1, $y, $z) => self::POWERED_START,
                    $this->isConductorAt($x + 1, $y, $z) => -self::POWERED_START,
                    default => $mx
                };
            }elseif($shape === Rails::NORTH_SOUTH){
                $mz = match(true){
                    $this->isConductorAt($x, $y, $z - 1) => self::POWERED_START,
                    $this->isConductorAt($x, $y, $z + 1) => -self::POWERED_START,
                    default => $mz
                };
            }
        }

        $this->motion = new Vector3($mx, $my, $mz);
        $this->setPosition(new Vector3($px, $py, $pz));
    }

    private function roll(bool $water) : void{
        $max = $water ? self::MAX_SPEED_IN_WATER : self::MAX_SPEED;
        $mx = max(-$max, min($max, $this->motion->x));
        $my = $this->motion->y;
        $mz = max(-$max, min($max, $this->motion->z));

        if($this->onGround){
            $mx *= 0.5;
            $my *= 0.5;
            $mz *= 0.5;
        }

        [$dx, $dy, $dz] = $this->collide($mx, $my, $mz);
        $this->onGround = $my < 0 && $dy !== $my;
        $this->fallDistance = $this->onGround || $dy >= 0 ? 0.0 : $this->fallDistance - $dy;
        $this->setPosition($this->position->add($dx, $dy, $dz));

        $mx = $dx !== $mx ? 0.0 : $mx;
        $my = $dy !== $my ? 0.0 : $my;
        $mz = $dz !== $mz ? 0.0 : $mz;

        if(!$this->onGround){
            $mx *= 0.95;
            $my *= 0.95;
            $mz *= 0.95;
        }

        $this->motion = new Vector3($mx, $my, $mz);
    }

    private function turn(Vector3 $start) : void{
        $dx = $start->x - $this->position->x;
        $dz = $start->z - $this->position->z;

        if($dx * $dx + $dz * $dz <= 0.001){
            return;
        }

        $yaw = atan2($dz, $dx) * 180 / M_PI + ($this->flipped ? 180.0 : 0.0);
        $change = fmod($yaw - $this->yaw + 540.0, 360.0) - 180.0;

        if($change < -170.0 || $change >= 170.0){
            $yaw += 180.0;
            $this->flipped = !$this->flipped;
        }

        $this->yaw = fmod($yaw + 360.0, 360.0);
    }

    private function bump() : void{
        $half = self::WIDTH / 2;

        foreach($this->world->getEntities() as $entity){
            if($entity === $this || $entity->isClosed() || in_array($entity, $this->passengers, true)){
                continue;
            }

            if($entity instanceof self){
                $reach = $half + self::PUSH_REACH + self::WIDTH / 2;
                $height = self::HEIGHT;
            }elseif($entity instanceof Living && $entity->isAlive() && !($entity instanceof Player && $entity->getVehicle() !== null)){
                $reach = $half + ($entity instanceof Human ? Human::WIDTH : $entity->getWidth()) / 2;
                $height = $entity instanceof Human ? Human::HEIGHT : $entity->getHeight();
            }else{
                continue;
            }

            $position = $entity->getPosition();

            if(abs($position->x - $this->position->x) >= $reach || abs($position->z - $this->position->z) >= $reach || $position->y >= $this->position->y + self::HEIGHT || $position->y + $height <= $this->position->y){
                continue;
            }

            if($entity instanceof self){
                $entity->pushFrom($this);
            }else{
                $this->pushFrom($entity);
            }
        }
    }

    /**
     * @return array{float, float, float}|null
     */
    private function railPoint(float $x, float $y, float $z) : ?array{
        [$bx, $by, $bz] = [(int) floor($x), (int) floor($y), (int) floor($z)];

        if(Rails::isRailAt($this->world, $bx, $by - 1, $bz)){
            --$by;
        }

        $rail = Rails::blockAt($this->world, $bx, $by, $bz);

        if(!Rails::isRail($rail->getName())){
            return null;
        }

        [$from, $to] = Rails::EXITS[Rails::shapeOf($rail)];
        $x0 = $bx + 0.5 + $from[0] * 0.5;
        $y0 = $by + self::RAIL_HEIGHT + $from[1] * 0.5;
        $z0 = $bz + 0.5 + $from[2] * 0.5;
        $ex = ($bx + 0.5 + $to[0] * 0.5) - $x0;
        $ey = (($by + self::RAIL_HEIGHT + $to[1] * 0.5) - $y0) * 2.0;
        $ez = ($bz + 0.5 + $to[2] * 0.5) - $z0;

        $along = match(true){
            $ex == 0.0 => $z - $bz,
            $ez == 0.0 => $x - $bx,
            default => (($x - $x0) * $ex + ($z - $z0) * $ez) * 2.0
        };

        $y = $y0 + $ey * $along + ($ey < 0.0 ? 1.0 : ($ey > 0.0 ? 0.5 : 0.0));

        return [$x0 + $ex * $along, $y, $z0 + $ez * $along];
    }

    private function isConductorAt(int $x, int $y, int $z) : bool{
        return $this->world->getRedstoneUpdater()->getSignal()->isConductor(Rails::blockAt($this->world, $x, $y, $z));
    }

    private function isInWater() : bool{
        $name = Rails::blockAt($this->world, $this->position->getFloorX(), (int) floor($this->position->y + 0.1), $this->position->getFloorZ())->getName();
        return $name === BlockIds::WATER || $name === BlockIds::FLOWING_WATER;
    }

    private function isInLava() : bool{
        $name = Rails::blockAt($this->world, $this->position->getFloorX(), (int) floor($this->position->y + 0.1), $this->position->getFloorZ())->getName();
        return $name === BlockIds::LAVA || $name === BlockIds::FLOWING_LAVA;
    }
}