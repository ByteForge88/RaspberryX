<?php

declare(strict_types=1);

namespace raspberry\player;

use raspberry\block\BlockCollision;
use raspberry\block\BlockIds;
use raspberry\block\BlockProperties;
use raspberry\block\BlockState;
use raspberry\block\entity\BlockEntity;
use raspberry\block\entity\BlockEntityFactory;
use raspberry\block\entity\BrewingStand;
use raspberry\block\entity\Chest;
use raspberry\block\entity\ContainerBlockEntity;
use raspberry\block\entity\Furnace;
use raspberry\block\entity\Hopper;
use raspberry\block\entity\ShulkerBox;

use raspberry\inventory\Inventory;

use raspberry\math\Facing;
use raspberry\math\Vector3;

use raspberry\network\mcpe\ContainerWindow;
use raspberry\network\mcpe\protocol\BlockEventPacket;
use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;
use raspberry\network\mcpe\protocol\types\inventory\ContainerIds;

use raspberry\world\Chunk;

use Closure;

use function preg_match;

class ContainerOpener{

    private const CHESTS = '/^minecraft:(chest|trapped_chest|(waxed_)?((exposed|weathered|oxidized)_)?copper_chest)$/D';

    /** a screen closes when the player is further than this from its block */
    public const MAX_DISTANCE = 8.0;

    private Player $player;

    public function __construct(Player $player){
        $this->player = $player;
    }

    /**
     * Opens the block's screen. False when the block has none.
     */
    public function open(int $x, int $y, int $z, BlockState $block) : bool{
        $name = $block->getName();

        return match(true){
            $name === BlockIds::CRAFTING_TABLE => $this->openCraftingTable($x, $y, $z),
            preg_match(self::CHESTS, $name) === 1 => $this->openChest($x, $y, $z, $block),
            $name === BlockIds::ENDER_CHEST => $this->openEnderChest($x, $y, $z),
            $name === BlockIds::BARREL => $this->openBarrel($x, $y, $z),
            preg_match('/^minecraft:\w+_shulker_box$/D', $name) === 1 => $this->openShulkerBox($x, $y, $z, $block),
            preg_match('/^minecraft:(lit_)?(furnace|blast_furnace|smoker)$/D', $name) === 1 => $this->openFurnace($x, $y, $z, $block),
            $name === BlockIds::BREWING_STAND => $this->openSimple($x, $y, $z, $block, BrewingStand::class, ContainerWindow::TYPE_BREWING_STAND, [ContainerIds::BREWING_STAND_INPUT, ContainerIds::BREWING_STAND_RESULT, ContainerIds::BREWING_STAND_FUEL]),
            $name === BlockIds::HOPPER => $this->openSimple($x, $y, $z, $block, Hopper::class, ContainerWindow::TYPE_HOPPER, [ContainerIds::LEVEL_ENTITY]),
            default => $this->openWorkBlock($x, $y, $z, match($name){
                BlockIds::ANVIL, BlockIds::CHIPPED_ANVIL, BlockIds::DAMAGED_ANVIL => ContainerWindow::TYPE_ANVIL,
                BlockIds::ENCHANTING_TABLE => ContainerWindow::TYPE_ENCHANTMENT,
                BlockIds::GRINDSTONE => ContainerWindow::TYPE_GRINDSTONE,
                BlockIds::STONECUTTER_BLOCK => ContainerWindow::TYPE_STONECUTTER,
                BlockIds::LOOM => ContainerWindow::TYPE_LOOM,
                BlockIds::SMITHING_TABLE => ContainerWindow::TYPE_SMITHING_TABLE,
                BlockIds::CARTOGRAPHY_TABLE => ContainerWindow::TYPE_CARTOGRAPHY,
                default => null
            })
        };
    }

    /**
     * Closes the open screen once the player is too far from it.
     */
    public function tick() : void{
        $window = $this->player->getNetworkSession()->getInventoryManager()?->getWindow();

        if($window === null){
            return;
        }

        [$x, $y, $z] = $window->getPositions()[0];

        if($this->player->getEyePosition()->distance(new Vector3($x + 0.5, $y + 0.5, $z + 0.5)) > self::MAX_DISTANCE){
            $this->player->getNetworkSession()->getInventoryManager()?->closeWindow(true);
        }
    }

    private function openCraftingTable(int $x, int $y, int $z) : bool{
        $session = $this->player->getNetworkSession();
        $manager = $session->getInventoryManager();

        if($manager === null){
            return true;
        }

        $manager->openWindow(ContainerWindow::TYPE_WORKBENCH, [[$x, $y, $z]], [], [], function() use ($session) : void{
            $session->setLargeCraftingGrid(false);
            $this->player->moveUIItemsToInventory();
        });
        $session->setLargeCraftingGrid(true);

        return true;
    }

    /**
     * Screens whose slots live in the player's UI inventory, what is left in them goes back to
     * the inventory when the screen closes.
     */
    private function openWorkBlock(int $x, int $y, int $z, ?int $type) : bool{
        if($type === null){
            return false;
        }

        $this->player->getNetworkSession()->getInventoryManager()?->openWindow($type, [[$x, $y, $z]], [], [], function() : void{
            $this->player->moveUIItemsToInventory();
        });

        return true;
    }

    private function openChest(int $x, int $y, int $z, BlockState $block) : bool{
        $world = $this->player->getWorld();
        $chest = $this->getEntity($x, $y, $z, $block);

        if(!$chest instanceof Chest || $this->isBlockedAbove($x, $y, $z)){
            return true;
        }

        $chests = [$chest];
        $positions = [[$x, $y, $z]];
        $pair = $chest->getPair();

        if($pair !== null){
            [$pairX, $pairZ] = $pair;
            $other = $world->isChunkLoaded($pairX >> 4, $pairZ >> 4) ? $world->getBlockEntity($pairX, $y, $pairZ) : null;

            if($other instanceof Chest && Chest::canPair($world->getBlock($pairX, $y, $pairZ), $block)){
                if($this->isBlockedAbove($pairX, $y, $pairZ)){
                    return true;
                }

                [$rightX, , $rightZ] = Facing::offset(Facing::rotateRight(Facing::fromName((string) $block->getProperty(BlockProperties::MINECRAFT_CARDINAL_DIRECTION))));
                $chests = $pairX === $x + $rightX && $pairZ === $z + $rightZ ? [$other, $chest] : [$chest, $other];
                $positions[] = [$pairX, $y, $pairZ];
            }
        }

        $inventories = [];

        foreach($chests as $part){
            $inventories[] = $part->getInventory();
        }

        $this->openShared([ContainerIds::LEVEL_ENTITY], $positions, $chests, $inventories, LevelSoundEvent::CHEST_OPEN, LevelSoundEvent::CHEST_CLOSED, $this->lidAnimation($positions));

        return true;
    }

    private function openEnderChest(int $x, int $y, int $z) : bool{
        $entity = $this->getEntity($x, $y, $z, $this->player->getWorld()->getBlock($x, $y, $z));

        if($entity === null || $this->isBlockedAbove($x, $y, $z)){
            return true;
        }

        $this->openShared([ContainerIds::LEVEL_ENTITY], [[$x, $y, $z]], [$entity], [$this->player->getEnderInventory()], LevelSoundEvent::ENDERCHEST_OPEN, LevelSoundEvent::ENDERCHEST_CLOSED, $this->lidAnimation([[$x, $y, $z]]));

        return true;
    }

    private function openBarrel(int $x, int $y, int $z) : bool{
        $world = $this->player->getWorld();
        $barrel = $this->getEntity($x, $y, $z, $world->getBlock($x, $y, $z));

        if(!$barrel instanceof ContainerBlockEntity){
            return true;
        }

        $this->openShared([ContainerIds::BARREL], [[$x, $y, $z]], [$barrel], [$barrel->getInventory()], LevelSoundEvent::BLOCK_BARREL_OPEN, LevelSoundEvent::BLOCK_BARREL_CLOSE, function(bool $open) use ($world, $x, $y, $z) : void{
            $block = $world->getBlock($x, $y, $z);

            if($block->getName() === BlockIds::BARREL){
                $world->setBlock($x, $y, $z, $block->withProperty(BlockProperties::OPEN_BIT, $open));
            }
        });

        return true;
    }

    private function openShulkerBox(int $x, int $y, int $z, BlockState $block) : bool{
        $box = $this->getEntity($x, $y, $z, $block);

        if(!$box instanceof ShulkerBox){
            return true;
        }

        //the lid needs room to open
        [$dx, $dy, $dz] = Facing::offset($box->getFacing());

        if($this->isFullCubeAt($x + $dx, $y + $dy, $z + $dz)){
            return true;
        }

        $this->openShared([ContainerIds::SHULKER_BOX], [[$x, $y, $z]], [$box], [$box->getInventory()], LevelSoundEvent::SHULKERBOX_OPEN, LevelSoundEvent::SHULKERBOX_CLOSED, $this->lidAnimation([[$x, $y, $z]]));

        return true;
    }

    private function openFurnace(int $x, int $y, int $z, BlockState $block) : bool{
        $furnace = $this->getEntity($x, $y, $z, $block);

        if(!$furnace instanceof Furnace){
            return true;
        }

        [$type, $ingredient] = match($furnace->getSaveId()){
            Furnace::ID_BLAST_FURNACE => [ContainerWindow::TYPE_BLAST_FURNACE, ContainerIds::BLAST_FURNACE_INGREDIENT],
            Furnace::ID_SMOKER => [ContainerWindow::TYPE_SMOKER, ContainerIds::SMOKER_INGREDIENT],
            default => [ContainerWindow::TYPE_FURNACE, ContainerIds::FURNACE_INGREDIENT]
        };

        $this->openShared([$ingredient, ContainerIds::FURNACE_FUEL, ContainerIds::FURNACE_RESULT], [[$x, $y, $z]], [$furnace], [$furnace->getInventory()], null, null, null, $type);

        return true;
    }

    /**
     * @param class-string<ContainerBlockEntity> $class
     * @param list<int>                          $slotContainerIds
     */
    private function openSimple(int $x, int $y, int $z, BlockState $block, string $class, int $type, array $slotContainerIds) : bool{
        $entity = $this->getEntity($x, $y, $z, $block);

        if($entity instanceof $class){
            $this->openShared($slotContainerIds, [[$x, $y, $z]], [$entity], [$entity->getInventory()], null, null, null, $type);
        }

        return true;
    }

    /**
     * @param list<int>                  $slotContainerIds
     * @param list<array{int, int, int}> $positions
     * @param list<BlockEntity>          $entities
     * @param list<Inventory>            $inventories
     * @param (Closure(bool) : void)|null $animate
     */
    private function openShared(array $slotContainerIds, array $positions, array $entities, array $inventories, ?string $openSound, ?string $closeSound, ?Closure $animate, int $type = ContainerWindow::TYPE_CONTAINER) : void{
        $manager = $this->player->getNetworkSession()->getInventoryManager();

        if($manager === null){
            return;
        }

        $manager->closeWindow(true);

        $wasOpen = $entities[0]->getViewers() !== [];

        foreach($entities as $entity){
            $entity->addViewer($this->player);
        }

        $this->updateTrappedChests($positions);

        [$x, $y, $z] = $positions[0];

        $manager->openWindow($type, $positions, $slotContainerIds, $inventories, function() use ($entities, $animate, $closeSound, $positions, $x, $y, $z) : void{
            foreach($entities as $entity){
                $entity->removeViewer($this->player);
            }

            $this->updateTrappedChests($positions);

            if($entities[0]->getViewers() === []){
                $this->animate($animate, $closeSound, false, $x, $y, $z);
            }
        }, $entities[0]);

        if($entities[0] instanceof ContainerBlockEntity){
            $entities[0]->onOpen($this->player);
        }

        if(!$wasOpen){
            $this->animate($animate, $openSound, true, $x, $y, $z);
        }
    }

    /**
     * A trapped chest gives power while someone has it open.
     *
     * @param list<array{int, int, int}> $positions
     */
    private function updateTrappedChests(array $positions) : void{
        $world = $this->player->getWorld();

        foreach($positions as [$x, $y, $z]){
            if($world->getBlock($x, $y, $z)->getName() === BlockIds::TRAPPED_CHEST){
                $world->getRedstoneUpdater()->update($x, $y, $z);
            }
        }
    }

    /**
     * @param (Closure(bool) : void)|null $animate
     */
    private function animate(?Closure $animate, ?string $sound, bool $open, int $x, int $y, int $z) : void{
        if($animate !== null){
            $animate($open);
        }

        if($sound !== null){
            $this->playSound($sound, $x, $y, $z);
        }
    }

    /**
     * @param list<array{int, int, int}> $positions
     *
     * @return Closure(bool) : void
     */
    private function lidAnimation(array $positions) : Closure{
        $world = $this->player->getWorld();

        return function(bool $open) use ($world, $positions) : void{
            foreach($positions as [$x, $y, $z]){
                $world->broadcastPacket($x, $z, BlockEventPacket::create($x, $y, $z, BlockEventPacket::TYPE_CHANGE_CHEST_STATE, $open ? 1 : 0));
            }
        };
    }

    /**
     * The block entity of a container, made if the block has none yet.
     */
    private function getEntity(int $x, int $y, int $z, BlockState $block) : ?BlockEntity{
        $world = $this->player->getWorld();
        $entity = $world->getBlockEntity($x, $y, $z);

        if($entity === null){
            $entity = BlockEntityFactory::create($block->getName(), $x, $y, $z);

            if($entity !== null){
                $world->addBlockEntity($entity);
            }
        }

        return $entity;
    }

    /**
     * Chests and ender chests do not open with a full block on top.
     */
    private function isBlockedAbove(int $x, int $y, int $z) : bool{
        return $this->isFullCubeAt($x, $y + 1, $z);
    }

    private function isFullCubeAt(int $x, int $y, int $z) : bool{
        return $y >= Chunk::MIN_Y && $y <= Chunk::MAX_Y && BlockCollision::isFullCube($this->player->getWorld()->getBlock($x, $y, $z));
    }

    private function playSound(string $sound, int $x, int $y, int $z) : void{
        $this->player->getWorld()->broadcastPacket($x, $z, LevelSoundEventPacket::create($sound, $x + 0.5, $y + 0.5, $z + 0.5));
    }
}