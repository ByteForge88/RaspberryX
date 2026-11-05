<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\nbt\tag\CompoundTag;

use function max;
use function min;

class Comparator extends BlockEntity{

    private int $output = 0;

    public function getSaveId() : string{
        return "Comparator";
    }

    public function getOutput() : int{
        return $this->output;
    }

    public function setOutput(int $output) : void{
        $this->output = max(0, min($output, 15));
        $this->setChanged();
    }

    public function readData(CompoundTag $nbt) : void{
        $this->output = max(0, min($nbt->getInt("OutputSignal", 0) ?? 0, 15));
    }

    public function writeData(CompoundTag $nbt) : void{
        $nbt->setInt("OutputSignal", $this->output);
    }
}