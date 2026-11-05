<?php

declare(strict_types=1);

namespace raspberry\network\mcpe;

use raspberry\block\entity\BlockEntity;

use raspberry\inventory\Inventory;

use Closure;

use function in_array;

class ContainerWindow{

    public const TYPE_CONTAINER = 0;
    public const TYPE_WORKBENCH = 1;
    public const TYPE_FURNACE = 2;
    public const TYPE_ENCHANTMENT = 3;
    public const TYPE_BREWING_STAND = 4;
    public const TYPE_ANVIL = 5;
    public const TYPE_HOPPER = 8;
    public const TYPE_LOOM = 24;
    public const TYPE_GRINDSTONE = 26;
    public const TYPE_BLAST_FURNACE = 27;
    public const TYPE_SMOKER = 28;
    public const TYPE_STONECUTTER = 29;
    public const TYPE_CARTOGRAPHY = 30;
    public const TYPE_SMITHING_TABLE = 33;

    private int $windowId;

    private int $type;

    /** @var list<array{int, int, int}> */
    private array $positions;

    /** @var list<int> */
    private array $slotContainerIds;

    /** @var list<array{Inventory, int}> */
    private array $parts;

    /** @var (Closure() : void)|null */
    private ?Closure $onClose;

    /** the block entity whose screen it is */
    private ?BlockEntity $entity;

    /**
     * @param list<array{int, int, int}> $positions
     * @param list<int>                  $slotContainerIds
     * @param list<Inventory>            $inventories shown one after the other
     * @param (Closure() : void)|null    $onClose
     */
    public function __construct(int $windowId, int $type, array $positions, array $slotContainerIds, array $inventories, ?Closure $onClose, ?BlockEntity $entity){
        $this->windowId = $windowId;
        $this->type = $type;
        $this->positions = $positions;
        $this->slotContainerIds = $slotContainerIds;
        $this->onClose = $onClose;
        $this->entity = $entity;
        $this->parts = [];

        $offset = 0;

        foreach($inventories as $inventory){
            $this->parts[] = [$inventory, $offset];
            $offset += $inventory->getSize();
        }
    }

    public function getWindowId() : int{
        return $this->windowId;
    }

    public function getType() : int{
        return $this->type;
    }

    /**
     * @return list<array{int, int, int}>
     */
    public function getPositions() : array{
        return $this->positions;
    }

    public function isAt(int $x, int $y, int $z) : bool{
        foreach($this->positions as $position){
            if($position === [$x, $y, $z]){
                return true;
            }
        }

        return false;
    }

    public function getEntity() : ?BlockEntity{
        return $this->entity;
    }

    /**
     * @return list<array{Inventory, int}>
     */
    public function getParts() : array{
        return $this->parts;
    }

    /**
     * @return array{Inventory, int}|null
     */
    public function resolve(int $containerId, int $slot) : ?array{
        if(!in_array($containerId, $this->slotContainerIds, true)){
            return null;
        }

        foreach($this->parts as [$inventory, $offset]){
            if($slot >= $offset && $slot < $offset + $inventory->getSize()){
                return [$inventory, $slot - $offset];
            }
        }

        return null;
    }

    /**
     * @internal
     */
    public function onClose() : void{
        $onClose = $this->onClose;
        $this->onClose = null;

        if($onClose !== null){
            $onClose();
        }
    }
}