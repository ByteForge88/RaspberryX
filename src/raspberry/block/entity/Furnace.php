<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\Server;

use raspberry\crafting\FurnaceData;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\ContainerSetDataPacket;

use raspberry\player\Player;

use raspberry\world\World;

use function floor;
use function max;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function str_starts_with;
use function substr;

class Furnace extends ContainerBlockEntity implements TickableBlockEntity{

    public const ID_FURNACE = "Furnace";
    public const ID_BLAST_FURNACE = "BlastFurnace";
    public const ID_SMOKER = "Smoker";

    public const SLOT_INPUT = 0;
    public const SLOT_FUEL = 1;
    public const SLOT_OUTPUT = 2;

    private const COOK_TICKS = 200;

    /** the furnace block for each kind, and the recipe block its recipes name */
    private const BLOCKS = [
        self::ID_FURNACE => "furnace",
        self::ID_BLAST_FURNACE => "blast_furnace",
        self::ID_SMOKER => "smoker"
    ];

    private string $saveId;

    /** ticks the burning fuel has left, and how long it burned in total */
    private int $burnTime = 0;
    private int $burnDuration = 0;

    private int $cookTime = 0;

    private float $experience = 0.0;

    /** @var array<int, int> */
    private array $sentData = [];

    public function __construct(int $x, int $y, int $z, string $saveId = self::ID_FURNACE){
        parent::__construct($x, $y, $z, 3);

        $this->saveId = $saveId;
    }

    public function getSaveId() : string{
        return $this->saveId;
    }

    /**
     * Ticks needed to cook one item.
     */
    public function getCookTicks() : int{
        return $this->saveId === self::ID_FURNACE ? self::COOK_TICKS : self::COOK_TICKS / 2;
    }

    public function isBurning() : bool{
        return $this->burnTime > 0;
    }

    /**
     * Takes the stored experience, rounded like vanilla: the fraction is a chance of one more point.
     */
    public function collectExperience() : int{
        $whole = (int) floor($this->experience);

        if(mt_rand() / mt_getrandmax() < $this->experience - $whole){
            ++$whole;
        }

        $this->experience = 0.0;
        $this->setChanged();

        return $whole;
    }

    public function readData(CompoundTag $nbt) : void{
        parent::readData($nbt);

        $this->burnTime = max(0, $nbt->getShort("BurnTime", 0) ?? 0);
        $this->burnDuration = max(0, $nbt->getShort("BurnDuration", 0) ?? 0);
        $this->cookTime = max(0, $nbt->getShort("CookTime", 0) ?? 0);
        $this->experience = max(0.0, (float) ($nbt->getShort("StoredXPInt", 0) ?? 0));
    }

    public function writeData(CompoundTag $nbt) : void{
        parent::writeData($nbt);

        $nbt->setShort("BurnTime", min($this->burnTime, 32767));
        $nbt->setShort("BurnDuration", min($this->burnDuration, 32767));
        $nbt->setShort("CookTime", $this->cookTime);
        $nbt->setShort("StoredXPInt", (int) min(floor($this->experience), 32767));
    }

    public function onOpen(Player $player) : void{
        $this->sendContainerData($this->getData(), [$player]);
    }

    public function tick(World $world, int $currentTick) : void{
        $inventory = $this->getInventory();
        $wasBurning = $this->isBurning();

        if($this->burnTime > 0){
            --$this->burnTime;
        }

        $input = $inventory->getItem(self::SLOT_INPUT);
        $fuel = $inventory->getItem(self::SLOT_FUEL);
        $output = $this->getOutput($input);

        if($this->isBurning() || (!$fuel->isNull() && !$input->isNull())){
            if(!$this->isBurning() && $output !== null){
                $ticks = FurnaceData::getBurnTicks($fuel);

                if($ticks > 0){
                    $this->burnTime = $this->burnDuration = $this->saveId === self::ID_FURNACE ? $ticks : (int) ($ticks / 2);
                    //a lava bucket leaves its bucket behind
                    $inventory->setItem(self::SLOT_FUEL, $fuel->getId() === ItemIds::LAVA_BUCKET ? new Item(ItemIds::BUCKET) : ($fuel->getCount() > 1 ? (clone $fuel)->setCount($fuel->getCount() - 1) : Item::air()));
                }
            }

            if($this->isBurning() && $output !== null){
                if(++$this->cookTime >= $this->getCookTicks()){
                    $this->cookTime = 0;
                    $this->cook($input, $output);
                }
            }else{
                $this->cookTime = 0;
            }

            $this->setChanged();
        }elseif($this->cookTime > 0){
            //an unlit furnace loses its progress, twice as fast as it was made
            $this->cookTime = max(0, $this->cookTime - 2);
            $this->setChanged();
        }

        if($wasBurning !== $this->isBurning()){
            $this->setLit($world, $this->isBurning());
        }

        $data = $this->getData();

        if($data !== $this->sentData){
            $this->sentData = $data;
            $this->sendContainerData($data);
        }
    }

    /**
     * What $input cooks into here, null when it cannot cook or the output slot has no room.
     */
    private function getOutput(Item $input) : ?Item{
        if($input->isNull()){
            return null;
        }

        $recipe = Server::getInstance()->getCraftingManager()->getFurnaceRecipe($input, self::BLOCKS[$this->saveId]);

        if($recipe === null){
            return null;
        }

        $result = $recipe->getOutput();
        $current = $this->getInventory()->getItem(self::SLOT_OUTPUT);

        if(!$current->isNull() && (!$current->canStackWith($result) || $current->getCount() + $result->getCount() > $current->getMaxStackSize())){
            return null;
        }

        return $result;
    }

    private function cook(Item $input, Item $result) : void{
        $inventory = $this->getInventory();
        $current = $inventory->getItem(self::SLOT_OUTPUT);

        $inventory->setItem(self::SLOT_INPUT, $input->getCount() > 1 ? (clone $input)->setCount($input->getCount() - 1) : Item::air());
        $inventory->setItem(self::SLOT_OUTPUT, $current->isNull() ? clone $result : (clone $current)->setCount($current->getCount() + $result->getCount()));
        $this->experience += FurnaceData::getExperience($result) * $result->getCount();
    }

    /**
     * Switches between the furnace and the lit furnace block, keeping its direction.
     */
    private function setLit(World $world, bool $lit) : void{
        $block = $world->getBlock($this->getX(), $this->getY(), $this->getZ());
        $name = substr($block->getName(), 10);
        $unlit = str_starts_with($name, "lit_") ? substr($name, 4) : $name;
        $wanted = "minecraft:" . ($lit ? "lit_" : "") . $unlit;

        if($wanted === $block->getName()){
            return;
        }

        $state = Server::getInstance()->getBlockStateRegistry()->getDefaultState($wanted);

        foreach($block->getProperties() as $property => $value){
            if($state->hasProperty($property)){
                $state = $state->withProperty($property, $value);
            }
        }

        $world->setBlock($this->getX(), $this->getY(), $this->getZ(), $state);
    }

    /**
     * @return array<int, int>
     */
    private function getData() : array{
        return [
            ContainerSetDataPacket::FURNACE_TICK_COUNT => $this->cookTime,
            ContainerSetDataPacket::FURNACE_LIT_TIME => $this->burnTime,
            ContainerSetDataPacket::FURNACE_LIT_DURATION => $this->burnDuration
        ];
    }
}