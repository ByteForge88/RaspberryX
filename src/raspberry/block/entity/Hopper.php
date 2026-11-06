<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\Server;

use raspberry\block\BlockCollision;
use raspberry\block\BlockProperties;

use raspberry\crafting\FurnaceData;

use raspberry\entity\object\ItemEntity;

use raspberry\inventory\Inventory;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\math\Facing;
use raspberry\math\Vector3;

use raspberry\nbt\tag\CompoundTag;

use raspberry\world\Chunk;
use raspberry\world\World;

use function in_array;
use function max;
use function min;
use function preg_match;

class Hopper extends ContainerBlockEntity implements TickableBlockEntity{

    public const TRANSFER_COOLDOWN = 8;

    private int $cooldown = 0;

    public function __construct(int $x, int $y, int $z){
        parent::__construct($x, $y, $z, 5);
    }

    public function getSaveId() : string{
        return "Hopper";
    }

    public function readData(CompoundTag $nbt) : void{
        parent::readData($nbt);

        $this->cooldown = max(0, min($nbt->getInt("TransferCooldown", 0) ?? 0, self::TRANSFER_COOLDOWN));
    }

    public function writeData(CompoundTag $nbt) : void{
        parent::writeData($nbt);

        $nbt->setInt("TransferCooldown", $this->cooldown);
    }

    public function tick(World $world, int $currentTick) : void{
        if(--$this->cooldown > 0){
            return;
        }

        $this->cooldown = 0;

        if($world->getBlock($this->getX(), $this->getY(), $this->getZ())->getProperty(BlockProperties::TOGGLE_BIT) === true){
            return;
        }

        if($this->push($world) | $this->pull($world)){
            $this->cooldown = self::TRANSFER_COOLDOWN;
            $this->setChanged();
        }
    }

    /**
     * Moves one item into the container the hopper faces.
     */
    private function push(World $world) : bool{
        $block = $world->getBlock($this->getX(), $this->getY(), $this->getZ());
        $facing = $block->hasProperty(BlockProperties::FACING_DIRECTION) ? (int) $block->getProperty(BlockProperties::FACING_DIRECTION) : Facing::DOWN;

        if($facing === Facing::UP || $facing < Facing::DOWN || $facing > Facing::EAST){
            return false;
        }

        [$dx, $dy, $dz] = Facing::offset($facing);
        $target = self::getContainer($world, $this->getX() + $dx, $this->getY() + $dy, $this->getZ() + $dz);

        if($target === null){
            return false;
        }

        foreach($this->getInventory()->getContents() as $slot => $item){
            if(self::insertOne($world, $target, $item, $facing === Facing::DOWN)){
                $this->getInventory()->setItem($slot, $item->getCount() > 1 ? (clone $item)->setCount($item->getCount() - 1) : Item::air());
                return true;
            }
        }

        return false;
    }

    /**
     * Takes one item out of the container above, or picks up the items lying on the hopper.
     */
    private function pull(World $world) : bool{
        $source = self::getContainer($world, $this->getX(), $this->getY() + 1, $this->getZ());

        if($source !== null){
            foreach(self::getExtractSlots($source) as $slot){
                $item = $source->getInventory()->getItem($slot);

                if(!$item->isNull() && $this->getInventory()->canAddItem((clone $item)->setCount(1))){
                    $this->getInventory()->addItem((clone $item)->setCount(1));
                    $source->getInventory()->setItem($slot, $item->getCount() > 1 ? (clone $item)->setCount($item->getCount() - 1) : Item::air());
                    return true;
                }
            }

            return false;
        }

        if($this->getY() + 1 <= Chunk::MAX_Y && BlockCollision::isFullCube($world->getBlock($this->getX(), $this->getY() + 1, $this->getZ()))){
            return false;
        }

        foreach($world->getEntities() as $entity){
            if(!$entity instanceof ItemEntity || $entity->isClosed()){
                continue;
            }

            $position = $entity->getPosition();

            if($position->x < $this->getX() || $position->x >= $this->getX() + 1 || $position->z < $this->getZ() || $position->z >= $this->getZ() + 1 || $position->y < $this->getY() + 0.5 || $position->y >= $this->getY() + 2){
                continue;
            }

            $item = $entity->getItem();
            $left = $this->getInventory()->addItem(clone $item)[0] ?? null;

            if($left !== null && $left->getCount() === $item->getCount()){
                continue;
            }

            //what did not fit stays on the hopper
            $entity->close();

            if($left !== null){
                $world->dropItem($position, $left, new Vector3(0, 0, 0), 0);
            }

            return true;
        }

        return false;
    }

    public static function getContainer(World $world, int $x, int $y, int $z) : ?ContainerBlockEntity{
        if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y || !$world->isChunkLoaded($x >> 4, $z >> 4)){
            return null;
        }

        $entity = $world->getBlockEntity($x, $y, $z);

        return $entity instanceof ContainerBlockEntity ? $entity : null;
    }

    /**
     * Puts one of $item into $target, in the slots items from that side go to.
     */
    public static function insertOne(World $world, ContainerBlockEntity $target, Item $item, bool $fromAbove) : bool{
        $one = (clone $item)->setCount(1);
        $inventory = $target->getInventory();

        if($target instanceof Crafter){
            return $target->insertOne($one);
        }

        $slots = match(true){
            $target instanceof Furnace => $fromAbove ? [Furnace::SLOT_INPUT] : (FurnaceData::getBurnTicks($one) > 0 ? [Furnace::SLOT_FUEL] : []),
            $target instanceof BrewingStand => match(true){
                $fromAbove => Server::getInstance()->getCraftingManager()->isBrewingReagent($one) ? [BrewingStand::SLOT_REAGENT] : [],
                $one->getId() === ItemIds::BLAZE_POWDER => [BrewingStand::SLOT_FUEL],
                in_array($one->getId(), [ItemIds::POTION, ItemIds::SPLASH_POTION, ItemIds::LINGERING_POTION, ItemIds::GLASS_BOTTLE], true) => BrewingStand::POTION_SLOTS,
                default => []
            },
            $target instanceof ShulkerBox && preg_match('/^minecraft:\w+_shulker_box$/D', $one->getId()) === 1 => [],
            default => self::allSlots($inventory)
        };

        if($target instanceof Chest && ($other = self::getPairedChest($world, $target)) !== null){
            foreach([$target, $other] as $half){
                if(self::insertInto($half->getInventory(), self::allSlots($half->getInventory()), $one)){
                    return true;
                }
            }

            return false;
        }

        return self::insertInto($inventory, $slots, $one);
    }

    /**
     * @param list<int> $slots
     */
    private static function insertInto(Inventory $inventory, array $slots, Item $one) : bool{
        foreach($slots as $slot){
            $current = $inventory->getItem($slot);

            if($current->isNull()){
                $inventory->setItem($slot, $one);
                return true;
            }

            if($current->canStackWith($one) && $current->getCount() < $current->getMaxStackSize()){
                $inventory->setItem($slot, (clone $current)->setCount($current->getCount() + 1));
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private static function getExtractSlots(ContainerBlockEntity $source) : array{
        return match(true){
            $source instanceof Furnace => [Furnace::SLOT_OUTPUT],
            $source instanceof BrewingStand => BrewingStand::POTION_SLOTS,
            $source instanceof Crafter => [],
            default => self::allSlots($source->getInventory())
        };
    }

    /**
     * @return list<int>
     */
    private static function allSlots(Inventory $inventory) : array{
        $slots = [];

        for($slot = 0; $slot < $inventory->getSize(); ++$slot){
            $slots[] = $slot;
        }

        return $slots;
    }

    private static function getPairedChest(World $world, Chest $chest) : ?Chest{
        $pair = $chest->getPair();

        if($pair === null || !$world->isChunkLoaded($pair[0] >> 4, $pair[1] >> 4)){
            return null;
        }

        $other = $world->getBlockEntity($pair[0], $chest->getY(), $pair[1]);

        return $other instanceof Chest ? $other : null;
    }
}