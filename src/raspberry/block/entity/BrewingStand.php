<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\Server;

use raspberry\block\BlockProperties;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\ContainerSetDataPacket;
use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\player\Player;

use raspberry\world\World;

use function max;
use function min;

class BrewingStand extends ContainerBlockEntity implements TickableBlockEntity{

    public const SLOT_REAGENT = 0;
    public const SLOT_FUEL = 4;
    public const POTION_SLOTS = [1, 2, 3];

    public const BREW_TICKS = 400;

    /** brews one blaze powder gives */
    public const FUEL_PER_POWDER = 20;

    /** the block property that shows a bottle in each potion slot */
    private const BOTTLE_PROPERTIES = [
        1 => BlockProperties::BREWING_STAND_SLOT_A_BIT,
        2 => BlockProperties::BREWING_STAND_SLOT_B_BIT,
        3 => BlockProperties::BREWING_STAND_SLOT_C_BIT
    ];

    private int $brewTime = 0;

    private int $fuel = 0;

    private ?string $brewingWith = null;

    /** @var array<int, int> */
    private array $sentData = [];

    public function __construct(int $x, int $y, int $z){
        parent::__construct($x, $y, $z, 5);
    }

    public function getSaveId() : string{
        return "BrewingStand";
    }

    public function readData(CompoundTag $nbt) : void{
        parent::readData($nbt);

        $this->brewTime = max(0, min($nbt->getShort("CookTime", 0) ?? 0, self::BREW_TICKS));
        $this->fuel = max(0, min($nbt->getShort("FuelAmount", 0) ?? 0, self::FUEL_PER_POWDER));
        $this->brewingWith = $this->brewTime > 0 ? $this->getInventory()->getItem(self::SLOT_REAGENT)->getId() : null;
    }

    public function writeData(CompoundTag $nbt) : void{
        parent::writeData($nbt);

        $nbt->setShort("CookTime", $this->brewTime);
        $nbt->setShort("FuelAmount", $this->fuel);
        $nbt->setShort("FuelTotal", self::FUEL_PER_POWDER);
    }

    public function onOpen(Player $player) : void{
        $this->sendContainerData($this->getData(), [$player]);
    }

    public function tick(World $world, int $currentTick) : void{
        $inventory = $this->getInventory();
        $powder = $inventory->getItem(self::SLOT_FUEL);

        if($this->fuel <= 0 && $powder->getId() === ItemIds::BLAZE_POWDER){
            $this->fuel = self::FUEL_PER_POWDER;
            $inventory->setItem(self::SLOT_FUEL, $powder->getCount() > 1 ? (clone $powder)->setCount($powder->getCount() - 1) : Item::air());
            $this->setChanged();
        }

        $reagent = $inventory->getItem(self::SLOT_REAGENT);
        $canBrew = $this->canBrew($reagent);

        if($this->brewTime > 0){
            --$this->brewTime;

            if(!$canBrew || $reagent->getId() !== $this->brewingWith){
                $this->brewTime = 0;
                $this->brewingWith = null;
            }elseif($this->brewTime === 0){
                $this->brew($world, $reagent);
                $this->brewingWith = null;
            }

            $this->setChanged();
        }elseif($canBrew && $this->fuel > 0){
            --$this->fuel;
            $this->brewTime = self::BREW_TICKS;
            $this->brewingWith = $reagent->getId();
            $this->setChanged();
        }

        $this->updateBottles($world);

        $data = $this->getData();

        if($data !== $this->sentData){
            $this->sentData = $data;
            $this->sendContainerData($data);
        }
    }

    private function canBrew(Item $reagent) : bool{
        if($reagent->isNull()){
            return false;
        }

        $crafting = Server::getInstance()->getCraftingManager();

        foreach(self::POTION_SLOTS as $slot){
            if($crafting->getBrewingOutput($this->getInventory()->getItem($slot), $reagent) !== null){
                return true;
            }
        }

        return false;
    }

    private function brew(World $world, Item $reagent) : void{
        $inventory = $this->getInventory();
        $crafting = Server::getInstance()->getCraftingManager();

        foreach(self::POTION_SLOTS as $slot){
            $output = $crafting->getBrewingOutput($inventory->getItem($slot), $reagent);

            if($output !== null){
                $inventory->setItem($slot, $output);
            }
        }

        $inventory->setItem(self::SLOT_REAGENT, $reagent->getCount() > 1 ? (clone $reagent)->setCount($reagent->getCount() - 1) : Item::air());
        $world->broadcastPacket($this->getX(), $this->getZ(), LevelSoundEventPacket::create(LevelSoundEvent::POTION_BREWED, $this->getX() + 0.5, $this->getY() + 0.5, $this->getZ() + 0.5));
    }

    /**
     * The block shows a bottle for every filled potion slot.
     */
    private function updateBottles(World $world) : void{
        $block = $world->getBlock($this->getX(), $this->getY(), $this->getZ());
        $updated = $block;

        foreach(self::BOTTLE_PROPERTIES as $slot => $property){
            if($updated->hasProperty($property)){
                $updated = $updated->withProperty($property, !$this->getInventory()->getItem($slot)->isNull());
            }
        }

        if(!$updated->equals($block)){
            $world->setBlock($this->getX(), $this->getY(), $this->getZ(), $updated);
        }
    }

    /**
     * @return array<int, int>
     */
    private function getData() : array{
        return [
            ContainerSetDataPacket::BREWING_STAND_BREW_TIME => $this->brewTime,
            ContainerSetDataPacket::BREWING_STAND_FUEL_AMOUNT => $this->fuel,
            ContainerSetDataPacket::BREWING_STAND_FUEL_TOTAL => self::FUEL_PER_POWDER
        ];
    }
}