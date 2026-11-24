<?php

declare(strict_types=1);

namespace raspberry\entity\object;

use raspberry\block\entity\Hopper;

use raspberry\entity\EntityIds;

use raspberry\item\ItemIds;

use raspberry\math\Vector3;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\ContainerWindow;

use raspberry\world\World;

use function floor;

class HopperMinecart extends CargoCart{

    public const int SIZE = 5;

    private const float PICKUP_REACH = 0.25;

    private bool $enabled = true;

    public static function fromSave(World $world, CompoundTag $tag) : ?static{
        $cart = parent::fromSave($world, $tag);

        if($cart !== null){
            $cart->enabled = ($tag->getByte("Enabled", 1) ?? 1) === 1;
        }

        return $cart;
    }

    public function save() : ?CompoundTag{
        $tag = parent::save();
        $tag?->setByte("Enabled", $this->enabled ? 1 : 0);
        return $tag;
    }

    public function getNetworkTypeId() : string{
        return EntityIds::HOPPER_MINECART;
    }

    public function getItemId() : string{
        return ItemIds::HOPPER_MINECART;
    }

    protected function getWindowType() : int{
        return ContainerWindow::TYPE_MINECART_HOPPER;
    }

    public function isEnabled() : bool{
        return $this->enabled;
    }

    public function setEnabled(bool $enabled) : void{
        $this->enabled = $enabled;
    }

    public function tick(int $currentTick) : void{
        parent::tick($currentTick);

        if(!$this->closed && $this->enabled && $this->world->isChunkLoaded($this->position->getChunkX(), $this->position->getChunkZ())){
            $this->suckIn();
        }
    }

    protected function onActivatorRail(bool $powered) : void{
        $this->enabled = !$powered;
    }

    private function suckIn() : void{
        $source = Hopper::getContainer($this->world, $this->position->getFloorX(), (int) floor($this->position->y + 0.5) + 1, $this->position->getFloorZ());

        if($source !== null){
            Hopper::takeOne($source, $this->getCargo());
            return;
        }

        $half = self::WIDTH / 2 + self::PICKUP_REACH;

        foreach($this->world->getEntities() as $entity){
            if(!$entity instanceof ItemEntity || $entity->isClosed()){
                continue;
            }

            $position = $entity->getPosition();

            if($position->x < $this->position->x - $half || $position->x > $this->position->x + $half ||
                $position->z < $this->position->z - $half || $position->z > $this->position->z + $half ||
                $position->y < $this->position->y || $position->y > $this->position->y + self::HEIGHT + 1.0){
                continue;
            }

            $item = $entity->getItem();
            $left = $this->getCargo()->addItem(clone $item)[0] ?? null;

            if($left !== null && $left->getCount() === $item->getCount()){
                continue;
            }

            $entity->close();

            if($left !== null){
                $this->world->dropItem($position, $left, new Vector3(0, 0, 0), 0);
            }

            return;
        }
    }
}