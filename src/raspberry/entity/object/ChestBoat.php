<?php

declare(strict_types=1);

namespace raspberry\entity\object;

use raspberry\block\entity\ContainerBlockEntity;

use raspberry\entity\EntityIds;

use raspberry\inventory\Inventory;

use raspberry\item\ItemIds;

use raspberry\math\Vector3;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\ContainerWindow;
use raspberry\network\mcpe\protocol\types\inventory\ContainerIds;

use raspberry\player\Player;

use raspberry\world\World;

use function array_values;

class ChestBoat extends Boat{

    protected const array ITEMS = [
        ItemIds::OAK_CHEST_BOAT,
        ItemIds::SPRUCE_CHEST_BOAT,
        ItemIds::BIRCH_CHEST_BOAT,
        ItemIds::JUNGLE_CHEST_BOAT,
        ItemIds::ACACIA_CHEST_BOAT,
        ItemIds::DARK_OAK_CHEST_BOAT,
        ItemIds::MANGROVE_CHEST_BOAT,
        ItemIds::BAMBOO_CHEST_RAFT,
        ItemIds::CHERRY_CHEST_BOAT,
        ItemIds::PALE_OAK_CHEST_BOAT,
        ItemIds::POPLAR_CHEST_BOAT
    ];

    public const int MAX_PASSENGERS = 1;

    public const int SIZE = 27;

    private Inventory $cargo;

    /** @var array<int, Player> */
    private array $rummagers = [];

    public function __construct(World $world, Vector3 $position, float $yaw, int $variant){
        $this->cargo = new Inventory(self::SIZE);

        parent::__construct($world, $position, $yaw, $variant);
    }

    public static function fromSave(World $world, CompoundTag $tag) : ?static{
        $boat = parent::fromSave($world, $tag);
        $boat?->cargo->setContents(ContainerBlockEntity::readItems($tag, self::SIZE));
        return $boat;
    }

    public function save() : ?CompoundTag{
        $tag = parent::save();
        $tag?->setTag("Items", ContainerBlockEntity::writeItems($this->cargo->getContents()));
        return $tag;
    }

    public function getNetworkTypeId() : string{
        return EntityIds::CHEST_BOAT;
    }

    public function getCargo() : Inventory{
        return $this->cargo;
    }

    public function getSeat(Player $rider) : Vector3{
        return new Vector3(0.2, $this->getVariant() === self::BAMBOO ? 0.1 : -0.2, 0.0);
    }

    public function rummage(Player $player) : bool{
        $manager = $player->getNetworkSession()->getInventoryManager();

        if($this->closed || $manager === null || $player->getWorld() !== $this->world){
            return false;
        }

        $position = $this->position;
        $manager->openWindow(ContainerWindow::TYPE_CHEST_BOAT, [[$position->getFloorX(), $position->getFloorY(), $position->getFloorZ()]], [ContainerIds::LEVEL_ENTITY], [$this->cargo], function() use ($player) : void{
            unset($this->rummagers[$player->getId()]);
        }, null, $this);

        $this->rummagers[$player->getId()] = $player;

        return true;
    }

    public function destroy(bool $drop) : void{
        if(!$this->closed){
            $this->kickOutRummagers();

            foreach(array_values($this->cargo->getContents()) as $item){
                $this->world->dropItem($this->position->add(0, 0.5, 0), $item);
            }

            $this->cargo->clearAll();
        }

        parent::destroy($drop);
    }

    public function close() : void{
        if(!$this->closed){
            $this->kickOutRummagers();
        }

        parent::close();
    }

    private function kickOutRummagers() : void{
        foreach($this->rummagers as $player){
            if($player->getNetworkSession()->getInventoryManager()?->getWindow()?->getActor() === $this){
                $player->getNetworkSession()->getInventoryManager()?->closeWindow(true);
            }
        }

        $this->rummagers = [];
    }

    public function touchesBlock(int $x, int $y, int $z) : bool{
        $half = self::WIDTH / 2;
        $position = $this->position;

        return !$this->closed &&
            $position->x - $half < $x + 1 &&
            $position->x + $half > $x &&
            $position->y < $y + 1 &&
            $position->y + self::HEIGHT > $y &&
            $position->z - $half < $z + 1 &&
            $position->z + $half > $z;
    }

    public static function findAt(World $world, int $x, int $y, int $z) : ?self{
        foreach($world->getEntities() as $entity){
            if($entity instanceof self && $entity->touchesBlock($x, $y, $z)){
                return $entity;
            }
        }

        return null;
    }
}