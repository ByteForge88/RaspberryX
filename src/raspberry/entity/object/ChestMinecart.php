<?php

declare(strict_types=1);

namespace raspberry\entity\object;

use raspberry\entity\EntityIds;

use raspberry\item\ItemIds;

use raspberry\network\mcpe\ContainerWindow;

class ChestMinecart extends CargoCart{

    public const int SIZE = 27;

    public function getNetworkTypeId() : string{
        return EntityIds::CHEST_MINECART;
    }

    public function getItemId() : string{
        return ItemIds::CHEST_MINECART;
    }

    protected function getWindowType() : int{
        return ContainerWindow::TYPE_MINECART_CHEST;
    }
}