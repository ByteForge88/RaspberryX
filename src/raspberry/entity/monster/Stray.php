<?php

declare(strict_types=1);

namespace raspberry\entity\monster;

use raspberry\entity\EntityIds;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use function min;

class Stray extends Skeleton{

    private const int SLOWNESS = 17;

    private const int LONG_SLOWNESS = 18;

    public function getNetworkTypeId() : string{
        return EntityIds::STRAY;
    }

    protected function getArrowPotion() : int{
        return self::LONG_SLOWNESS;
    }

    protected function getDrops(int $looting) : array{
        $drops = parent::getDrops($looting);

        if($this->getKiller() !== null){
            $count = min(1, self::lootCount(0, 1, $looting));

            if($count > 0){
                $drops[] = new Item(ItemIds::ARROW, $count, self::SLOWNESS + 1);
            }
        }

        return $drops;
    }
}