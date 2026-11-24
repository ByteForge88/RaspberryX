<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\block\Pistons;

use raspberry\nbt\tag\CompoundTag;
use raspberry\nbt\tag\IntTag;
use raspberry\nbt\tag\ListTag;
use raspberry\nbt\tag\Tag;

use raspberry\world\World;

use function count;
use function intdiv;
use function max;
use function min;

class PistonArm extends BlockEntity implements TickableBlockEntity{

    public const string ID = "PistonArm";

    public const int RETRACTED = 0;
    public const int PUSHING = 1;
    public const int EXTENDED = 2;
    public const int PULLING = 3;

    public const float STEP = 0.5;

    private int $state = self::RETRACTED;

    private int $newState = self::RETRACTED;

    private float $progress = 0.0;

    private float $lastProgress = 0.0;

    private bool $sticky = false;

    /** @var list<array{int, int, int}> */
    private array $attached = [];

    public function getSaveId() : string{
        return self::ID;
    }

    public function isSticky() : bool{
        return $this->sticky;
    }

    public function setSticky(bool $sticky) : void{
        $this->sticky = $sticky;
    }

    public function getState() : int{
        return $this->state;
    }

    public function isMoving() : bool{
        return $this->state === self::PUSHING || $this->state === self::PULLING;
    }

    public function isExtending() : bool{
        return $this->state === self::PUSHING;
    }

    public function getProgress() : float{
        return $this->progress;
    }

    public function setExtended() : void{
        $this->state = $this->newState = self::EXTENDED;
        $this->progress = $this->lastProgress = 1.0;
        $this->attached = [];
        $this->setChanged();
    }

    public function start(bool $extending) : void{
        $this->state = $extending ? self::PUSHING : self::PULLING;
        $this->newState = $extending ? self::EXTENDED : self::RETRACTED;
        $this->progress = $this->lastProgress = $extending ? 0.0 : 1.0;
        $this->attached = [];
        $this->setChanged();
    }

    /**
     * @return list<array{int, int, int}>
     */
    public function getAttached() : array{
        return $this->attached;
    }

    /**
     * @param list<array{int, int, int}> $attached
     */
    public function setAttached(array $attached) : void{
        $this->attached = $attached;
        $this->setChanged();
    }

    /**
     * @return list<array{int, int, int}>
     */
    public function finish() : array{
        $attached = $this->attached;

        $this->state = $this->newState;
        $this->progress = $this->lastProgress = $this->state === self::EXTENDED ? 1.0 : 0.0;
        $this->attached = [];
        $this->setChanged();
        return $attached;
    }

    public function tick(World $world, int $currentTick) : void{
        if(!$this->isMoving()){
            return;
        }

        $this->lastProgress = $this->progress;
        $this->progress = $this->isExtending() ? min(1.0, $this->progress + self::STEP) : max(0.0, $this->progress - self::STEP);

        if($this->progress === $this->lastProgress){
            Pistons::finish($world, $this);
            return;
        }

        $this->setChanged();
        Pistons::pushEntities($world, $this);
        $world->sendBlockEntity($this);
    }

    public function readData(CompoundTag $nbt) : void{
        $this->state = self::readState($nbt->getByte("State", self::RETRACTED) ?? self::RETRACTED);
        $this->newState = self::readState($nbt->getByte("NewState", $this->state) ?? $this->state);
        $this->progress = max(0.0, min($nbt->getFloat("Progress", 0.0) ?? 0.0, 1.0));
        $this->lastProgress = max(0.0, min($nbt->getFloat("LastProgress", $this->progress) ?? $this->progress, 1.0));
        $this->sticky = $nbt->getByte("Sticky", 0) === 1;

        $values = $nbt->getListTag("AttachedBlocks")?->getAllValues() ?? [];
        $this->attached = [];

        for($i = 0, $count = intdiv(count($values), 3) * 3; $i < $count; $i += 3){
            $this->attached[] = [(int) $values[$i], (int) $values[$i + 1], (int) $values[$i + 2]];
        }

        if(!$this->isMoving()){
            $this->attached = [];
        }
    }

    public function writeData(CompoundTag $nbt) : void{
        $attached = new ListTag([], Tag::TAG_INT);

        foreach($this->attached as [$x, $y, $z]){
            $attached->push(new IntTag($x));
            $attached->push(new IntTag($y));
            $attached->push(new IntTag($z));
        }

        $nbt->setTag("AttachedBlocks", $attached)
            ->setTag("BreakBlocks", new ListTag([], Tag::TAG_INT))
            ->setFloat("LastProgress", $this->lastProgress)
            ->setFloat("Progress", $this->progress)
            ->setByte("NewState", $this->newState)
            ->setByte("State", $this->state)
            ->setByte("Sticky", $this->sticky ? 1 : 0);
    }

    private static function readState(int $state) : int{
        return $state >= self::RETRACTED && $state <= self::PULLING ? $state : self::RETRACTED;
    }
}