<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\Server;

use raspberry\block\Dispensing;
use raspberry\block\BlockProperties;

use raspberry\crafting\CraftingRecipe;
use raspberry\crafting\RecipeIngredient;

use raspberry\item\Item;

use raspberry\math\Facing;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\world\World;

use function explode;

class Crafter extends ContainerBlockEntity{

    public const SIZE = 9;

    /** how long the crafter shows it is crafting */
    public const CRAFTING_TICKS = 6;

    /** one bit per slot */
    private int $disabledSlots = 0;

    public function __construct(int $x, int $y, int $z){
        parent::__construct($x, $y, $z, self::SIZE);
    }

    public function getSaveId() : string{
        return "Crafter";
    }

    public function readData(CompoundTag $nbt) : void{
        parent::readData($nbt);

        $this->disabledSlots = ($nbt->getShort("disabled_slots", 0) ?? 0) & 0x1ff;
    }

    public function writeData(CompoundTag $nbt) : void{
        parent::writeData($nbt);

        $nbt->setShort("disabled_slots", $this->disabledSlots);
    }

    public function isSlotDisabled(int $slot) : bool{
        return ($this->disabledSlots >> $slot & 1) === 1;
    }

    public function setSlotDisabled(int $slot, bool $disabled) : void{
        $this->disabledSlots = $disabled ? $this->disabledSlots | 1 << $slot : $this->disabledSlots & ~(1 << $slot);
        $this->setChanged();
        $this->onContentChange($this->getInventory());
    }

    /**
     * What comparators read: the slots that hold something or are disabled.
     */
    public function getSignal() : int{
        $signal = 0;

        for($slot = 0; $slot < self::SIZE; ++$slot){
            if($this->isSlotDisabled($slot) || !$this->getInventory()->getItem($slot)->isNull()){
                ++$signal;
            }
        }

        return $signal;
    }

    /**
     * Puts one item in from a hopper or dropper, in the open slot that holds the least.
     */
    public function insertOne(Item $one) : bool{
        $best = null;
        $bestCount = 0;

        for($slot = 0; $slot < self::SIZE; ++$slot){
            if($this->isSlotDisabled($slot)){
                continue;
            }

            $current = $this->getInventory()->getItem($slot);

            if(!$current->isNull() && (!$current->canStackWith($one) || $current->getCount() >= $current->getMaxStackSize())){
                continue;
            }

            if($best === null || $current->getCount() < $bestCount){
                $best = $slot;
                $bestCount = $current->getCount();
            }
        }

        if($best === null){
            return false;
        }

        $current = $this->getInventory()->getItem($best);
        $this->getInventory()->setItem($best, $current->isNull() ? $one : (clone $current)->setCount($current->getCount() + 1));

        return true;
    }

    /**
     * Crafts what the grid holds once and puts it out the front, called on a redstone pulse.
     */
    public function craft(World $world) : void{
        [$x, $y, $z] = [$this->getX(), $this->getY(), $this->getZ()];
        $grid = [];

        for($slot = 0; $slot < self::SIZE; ++$slot){
            $grid[] = $this->getInventory()->getItem($slot);
        }

        [$recipe, $matched] = $this->findRecipe($grid);

        if($recipe === null){
            $world->broadcastPacket($x, $z, LevelSoundEventPacket::create(LevelSoundEvent::CRAFTER_FAIL, $x + 0.5, $y + 0.5, $z + 0.5));
            return;
        }

        foreach($matched as $slot => $ingredient){
            $this->getInventory()->setItem($slot, (clone $grid[$slot])->setCount($grid[$slot]->getCount() - $ingredient->getCount()));
        }

        $block = $world->getBlock($x, $y, $z);
        $facing = Facing::fromName(explode("_", (string) $block->getProperty(BlockProperties::ORIENTATION))[0]);
        [$dx, $dy, $dz] = Facing::offset($facing);
        $target = Hopper::getContainer($world, $x + $dx, $y + $dy, $z + $dz);

        //the empty buckets of a cake are outputs of the recipe too
        foreach($recipe->getOutput() as $output){
            $item = clone $output;

            while($target !== null && !$item->isNull() && Hopper::insertOne($world, $target, $item, $facing === Facing::DOWN)){
                $item->setCount($item->getCount() - 1);
            }

            if(!$item->isNull()){
                Dispensing::drop($world, $x, $y, $z, $item, $facing);
            }
        }

        $world->broadcastPacket($x, $z, LevelSoundEventPacket::create(LevelSoundEvent::CRAFTER_CRAFT, $x + 0.5, $y + 0.5, $z + 0.5));
        $world->setBlock($x, $y, $z, $block->withProperty(BlockProperties::CRAFTING, true));

        $world->scheduleUpdate(self::CRAFTING_TICKS, function() use ($world, $x, $y, $z) : void{
            $current = $world->getBlock($x, $y, $z);

            if($current->hasProperty(BlockProperties::CRAFTING) && $current->getProperty(BlockProperties::CRAFTING) === true){
                $world->setBlock($x, $y, $z, $current->withProperty(BlockProperties::CRAFTING, false));
            }
        });
    }

    /**
     * @param list<Item> $grid
     *
     * @return array{CraftingRecipe|null, array<int, RecipeIngredient>}
     */
    private function findRecipe(array $grid) : array{
        $empty = true;

        foreach($grid as $item){
            if(!$item->isNull()){
                $empty = false;
                break;
            }
        }

        if(!$empty){
            foreach(Server::getInstance()->getCraftingManager()->getCraftingRecipes() as $recipe){
                if($recipe->getBlock() !== "crafting_table"){
                    continue;
                }

                $matched = $recipe->match($grid, 3, 1);

                if($matched !== null){
                    return [$recipe, $matched];
                }
            }
        }

        return [null, []];
    }
}