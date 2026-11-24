<?php

declare(strict_types=1);

namespace raspberry\entity\object;

use raspberry\block\entity\ContainerBlockEntity;
use raspberry\block\redstone\RedstoneSignal;

use raspberry\entity\CargoCarrier;

use raspberry\inventory\Inventory;

use raspberry\math\Vector3;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\types\EntityMetadata;

use raspberry\player\Player;

use raspberry\world\World;

abstract class CargoCart extends Cart implements CargoCarrier{
    use CargoHold;

    public const int SIZE = 27;

    public function __construct(World $world, Vector3 $position, float $yaw = 0.0){
        $this->cargo = new Inventory(static::SIZE);

        parent::__construct($world, $position, $yaw);
    }

    public static function fromSave(World $world, CompoundTag $tag) : ?static{
        $cart = parent::fromSave($world, $tag);
        $cart?->cargo->setContents(ContainerBlockEntity::readItems($tag, static::SIZE));
        return $cart;
    }

    public function save() : ?CompoundTag{
        $tag = parent::save();
        $tag?->setTag("Items", ContainerBlockEntity::writeItems($this->cargo->getContents()));
        return $tag;
    }

    protected function addMetadata(EntityMetadata $metadata) : void{
        $metadata->setByte(EntityMetadata::KEY_CONTAINER_TYPE, $this->getWindowType());
        $metadata->setInt(EntityMetadata::KEY_CONTAINER_SIZE, $this->cargo->getSize());
        $metadata->setInt(EntityMetadata::KEY_CONTAINER_STRENGTH_MODIFIER, 0);
    }

    public function getInteractText(Player $player) : string{
        return "action.interact.opencontainer";
    }

    public function interact(Player $player) : void{
        $this->rummage($player);
    }

    protected function getFriction() : float{
        return 0.98 + (15 - RedstoneSignal::getFullness([$this->cargo])) * 0.001;
    }

    public function destroy(bool $drop) : void{
        if(!$this->closed){
            $this->spillCargo();
        }

        parent::destroy($drop);
    }

    public function close() : void{
        if(!$this->closed){
            $this->kickOutRummagers();
        }

        parent::close();
    }
}