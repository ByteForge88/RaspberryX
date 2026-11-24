<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\nbt\tag\CompoundTag;

use function max;
use function min;

class NoteBlock extends BlockEntity{

    public const string ID = "Music";

    public const int MAX_NOTE = 24;

    private int $note = 0;

    private bool $powered = false;

    public function getSaveId() : string{
        return self::ID;
    }

    public function getNote() : int{
        return $this->note;
    }

    public function setNote(int $note) : void{
        $this->note = max(0, min($note, self::MAX_NOTE));
        $this->setChanged();
    }

    public function isPowered() : bool{
        return $this->powered;
    }

    public function setPowered(bool $powered) : void{
        $this->powered = $powered;
        $this->setChanged();
    }

    public function readData(CompoundTag $nbt) : void{
        $this->note = max(0, min($nbt->getByte("note", 0) ?? 0, self::MAX_NOTE));
        $this->powered = $nbt->getByte("powered", 0) === 1;
    }

    public function writeData(CompoundTag $nbt) : void{
        $nbt->setByte("note", $this->note)->setByte("powered", $this->powered ? 1 : 0);
    }
}