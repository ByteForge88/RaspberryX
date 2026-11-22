<?php

declare(strict_types=1);

namespace raspberry\network\mcpe;

use raspberry\block\entity\BlockEntity;

use raspberry\entity\Entity;

use raspberry\inventory\Inventory;

use Closure;

use function in_array;

class ContainerWindow{

    public const int TYPE_CONTAINER = 0;
    public const int TYPE_WORKBENCH = 1;
    public const int TYPE_FURNACE = 2;
    public const int TYPE_ENCHANTMENT = 3;
    public const int TYPE_BREWING_STAND = 4;
    public const int TYPE_ANVIL = 5;
    public const int TYPE_DISPENSER = 6;
    public const int TYPE_DROPPER = 7;
    public const int TYPE_HOPPER = 8;
    public const int TYPE_BEACON = 13;
    public const int TYPE_LOOM = 24;
    public const int TYPE_LECTERN = 25;
    public const int TYPE_GRINDSTONE = 26;
    public const int TYPE_BLAST_FURNACE = 27;
    public const int TYPE_SMOKER = 28;
    public const int TYPE_STONECUTTER = 29;
    public const int TYPE_CARTOGRAPHY = 30;
    public const int TYPE_SMITHING_TABLE = 33;
    public const int TYPE_CHEST_BOAT = 34;
    public const int TYPE_CRAFTER = 36;

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

    private ?Entity $actor;

    /**
     * @param int $windowId
     * @param int $type
     * @param list<array{int, int, int}> $positions
     * @param list<int> $slotContainerIds
     * @param list<Inventory> $inventories shown one after the other
     * @param (Closure() : void)|null $onClose
     * @param BlockEntity|null $entity
     * @param Entity|null $actor
     */
    public function __construct(int $windowId, int $type, array $positions, array $slotContainerIds, array $inventories, ?Closure $onClose, ?BlockEntity $entity, ?Entity $actor = null){
        $this->windowId = $windowId;
        $this->type = $type;
        $this->positions = $positions;
        $this->slotContainerIds = $slotContainerIds;
        $this->onClose = $onClose;
        $this->entity = $entity;
        $this->actor = $actor;
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

    public function getActor() : ?Entity{
        return $this->actor;
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