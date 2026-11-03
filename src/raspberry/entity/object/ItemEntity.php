<?php

declare(strict_types=1);

namespace raspberry\entity\object;

use raspberry\block\BlockCollision;

use raspberry\entity\Entity;
use raspberry\entity\Human;

use raspberry\item\Item;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\TakeItemActorPacket;

use raspberry\player\GameMode;

use raspberry\world\Chunk;
use raspberry\world\World;

use InvalidArgumentException;

use function abs;
use function floor;
use function max;
use function min;

class ItemEntity extends Entity{

    public const SIZE = 0.25;

    public const GRAVITY = 0.04;
    public const DRAG = 0.02;
    public const GROUND_FRICTION = 0.6;

    public const DESPAWN_TICKS = 6000;

    public const DEFAULT_PICKUP_DELAY = 10;

    public const THROWN_PICKUP_DELAY = 40;

    public const VOID_DEPTH = 64;

    private const MERGE_TICKS = 10;
    private const VIEWER_UPDATE_TICKS = 20;

    private Item $item;

    private Vector3 $motion;

    private int $pickupDelay;

    private int $age = 0;

    private bool $onGround = false;

    private bool $viewersUpdated = false;

    public function __construct(World $world, Vector3 $position, Item $item, ?Vector3 $motion = null, int $pickupDelay = self::DEFAULT_PICKUP_DELAY){
        if($item->isNull()){
            throw new InvalidArgumentException("Cannot drop an empty item");
        }

        $this->item = clone $item;
        $this->motion = $motion ?? Vector3::zero();
        $this->pickupDelay = $pickupDelay;

        parent::__construct($world, $position);
    }

    public function getNetworkTypeId() : string{
        return "minecraft:item";
    }

    public function getNetworkOffset() : float{
        return self::SIZE / 2;
    }

    public function getItem() : Item{
        return clone $this->item;
    }

    public function getMotion() : Vector3{
        return $this->motion;
    }

    public function getPickupDelay() : int{
        return $this->pickupDelay;
    }

    public function getAge() : int{
        return $this->age;
    }

    public function isOnGround() : bool{
        return $this->onGround;
    }

    public function tick(int $currentTick) : void{
        if($this->closed){
            return;
        }

        if(++$this->age >= self::DESPAWN_TICKS || $this->position->y < Chunk::MIN_Y - self::VOID_DEPTH){
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

        $this->tickMovement();

        if($this->pickupDelay > 0){
            --$this->pickupDelay;
        }elseif($this->tryPickup()){
            return;
        }

        if(($this->age + $this->id) % self::MERGE_TICKS === 0){
            $this->tryMerge();
        }
    }

    private function tickMovement() : void{
        $x = $this->position->x;
        $y = $this->position->y;
        $z = $this->position->z;

        if($this->isSolidAt((int) floor($x), (int) floor($y + 0.001), (int) floor($z))){
            $this->onGround = false;
            $this->motion = new Vector3(0.0, 0.1, 0.0);
            $this->move(new Vector3($x, $y + 0.1, $z), 0.0, 0.0);
            return;
        }

        $friction = 1 - self::DRAG;
        $motionY = ($this->motion->y - self::GRAVITY) * $friction;

        if($this->onGround){
            $friction *= self::GROUND_FRICTION;
        }

        $motionX = $this->motion->x * $friction;
        $motionZ = $this->motion->z * $friction;

        [$dx, $dy, $dz] = $this->collide($motionX, $motionY, $motionZ);

        $this->onGround = $motionY < 0 && $dy !== $motionY;

        $this->motion = new Vector3(
            $dx !== $motionX || abs($motionX) < 0.001 ? 0.0 : $motionX,
            $dy !== $motionY ? 0.0 : $motionY,
            $dz !== $motionZ || abs($motionZ) < 0.001 ? 0.0 : $motionZ
        );

        if($dx !== 0.0 || $dy !== 0.0 || $dz !== 0.0){
            $this->move(new Vector3($x + $dx, $y + $dy, $z + $dz), 0.0, 0.0);
        }
    }

    /**
     * @return array{float, float, float}
     */
    private function collide(float $dx, float $dy, float $dz) : array{
        $half = self::SIZE / 2;
        $box = [$this->position->x - $half, $this->position->y, $this->position->z - $half, $this->position->x + $half, $this->position->y + self::SIZE, $this->position->z + $half];
        $blocks = [];

        for($bx = (int) floor(min($box[0], $box[0] + $dx)); $bx <= (int) floor(max($box[3], $box[3] + $dx)); ++$bx){
            for($by = (int) floor(min($box[1], $box[1] + $dy)); $by <= (int) floor(max($box[4], $box[4] + $dy)); ++$by){
                for($bz = (int) floor(min($box[2], $box[2] + $dz)); $bz <= (int) floor(max($box[5], $box[5] + $dz)); ++$bz){
                    if($this->isSolidAt($bx, $by, $bz)){
                        $blocks[] = [$bx, $by, $bz, $bx + 1, $by + 1, $bz + 1];
                    }
                }
            }
        }

        foreach([1, 0, 2] as $axis){
            $delta = [$dx, $dy, $dz][$axis];

            if($delta === 0.0){
                continue;
            }

            foreach($blocks as $block){
                $delta = self::clip($box, $block, $axis, $delta);
            }

            $box[$axis] += $delta;
            $box[$axis + 3] += $delta;

            match($axis){
                0 => $dx = $delta,
                1 => $dy = $delta,
                2 => $dz = $delta
            };
        }

        return [$dx, $dy, $dz];
    }

    /**
     * @param array<int, float> $box
     * @param array<int, int|float> $block
     */
    private static function clip(array $box, array $block, int $axis, float $delta) : float{
        foreach([0, 1, 2] as $other){
            if($other !== $axis && ($box[$other + 3] <= $block[$other] || $box[$other] >= $block[$other + 3])){
                return $delta;
            }
        }

        if($delta > 0 && $box[$axis + 3] <= $block[$axis]){
            return min($delta, $block[$axis] - $box[$axis + 3]);
        }

        if($delta < 0 && $box[$axis] >= $block[$axis + 3]){
            return max($delta, $block[$axis + 3] - $box[$axis]);
        }

        return $delta;
    }

    private function isSolidAt(int $x, int $y, int $z) : bool{
        if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y || !$this->world->isChunkLoaded($x >> 4, $z >> 4)){
            return false;
        }

        return BlockCollision::isSolid($this->world->getBlock($x, $y, $z));
    }

    private function isNear(float $minX, float $minY, float $minZ, float $maxX, float $maxY, float $maxZ) : bool{
        $half = self::SIZE / 2 + 1;
        $position = $this->position;

        return $maxX > $position->x - $half && $minX < $position->x + $half &&
            $maxY > $position->y - 0.5 && $minY < $position->y + self::SIZE + 0.5 &&
            $maxZ > $position->z - $half && $minZ < $position->z + $half;
    }

    private function tryPickup() : bool{
        foreach($this->world->getPlayers() as $player){
            if(!$player->isSpawned() || $player->isDead() || $player->getGameMode() === GameMode::SPECTATOR){
                continue;
            }

            $feet = $player->getPosition();
            $half = Human::WIDTH / 2;

            if(!$this->isNear($feet->x - $half, $feet->y, $feet->z - $half, $feet->x + $half, $feet->y + Human::HEIGHT, $feet->z + $half)){
                continue;
            }

            $leftover = $player->getInventory()->addItem($this->item)[0] ?? null;

            if($leftover !== null && $leftover->getCount() === $this->item->getCount()){
                continue;
            }

            $packet = TakeItemActorPacket::create($this->id, $player->getId());
            $player->getNetworkSession()->sendDataPacket($packet);

            foreach($this->viewers as $viewer){
                if($viewer !== $player){
                    $viewer->getNetworkSession()->sendDataPacket($packet);
                }
            }

            $this->close();

            if($leftover !== null){
                new ItemEntity($this->world, $this->position, $leftover, null, 0);
            }

            return true;
        }

        return false;
    }

    private function tryMerge() : void{
        $max = $this->item->getMaxStackSize();

        if($this->item->getCount() >= $max){
            return;
        }

        $half = self::SIZE / 2;

        foreach($this->world->getEntities() as $other){
            if(!$other instanceof ItemEntity || $other === $this || $other->closed){
                continue;
            }

            $item = $other->item;
            $position = $other->position;

            if($item->getCount() >= $max || !$item->canStackWith($this->item) || !$this->isNear($position->x - $half, $position->y, $position->z - $half, $position->x + $half, $position->y + self::SIZE, $position->z + $half)){
                continue;
            }

            $total = $item->getCount() + $this->item->getCount();
            $merged = new ItemEntity($this->world, $position, (clone $item)->setCount(min($total, $max)), $other->motion, max($this->pickupDelay, $other->pickupDelay));
            $merged->age = min($this->age, $other->age);

            if($total > $max){
                $rest = new ItemEntity($this->world, $this->position, (clone $this->item)->setCount($total - $max), $this->motion, $this->pickupDelay);
                $rest->age = $this->age;
            }

            $other->close();
            $this->close();
            return;
        }
    }
}