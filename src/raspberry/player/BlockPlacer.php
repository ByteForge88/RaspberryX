<?php

declare(strict_types=1);

namespace raspberry\player;

use raspberry\block\BlockCollision;
use raspberry\block\Beds;
use raspberry\block\BigDripleaf;
use raspberry\block\BoneMeal;
use raspberry\block\PointedDripstone;
use raspberry\block\Scaffolding;
use raspberry\block\ToolUse;
use raspberry\block\Fire;
use raspberry\block\Buckets;
use raspberry\block\BlockIds;
use raspberry\block\BlockOrientation;
use raspberry\block\BlockProperties;
use raspberry\block\BlockState;
use raspberry\block\BlockSupport;
use raspberry\block\Cauldrons;
use raspberry\block\ItemBlocks;
use raspberry\block\StairsShape;
use raspberry\block\entity\Sign;
use raspberry\block\entity\Chest;
use raspberry\block\entity\Jukebox;
use raspberry\block\entity\Lectern;
use raspberry\block\entity\FlowerPot;
use raspberry\block\entity\Campfire;
use raspberry\block\entity\Spawner;
use raspberry\block\entity\BlockEntity;
use raspberry\block\entity\BlockEntityFactory;

use raspberry\entity\Human;
use raspberry\entity\EntityFactory;
use raspberry\entity\object\Boat;
use raspberry\entity\object\ChestBoat;
use raspberry\entity\object\Painting;

use raspberry\event\block\BlockPlaceEvent;
use raspberry\event\block\SignChangeEvent;
use raspberry\event\player\PlayerInteractEvent;

use raspberry\item\Item;
use raspberry\item\ItemIds;
use raspberry\item\ToolInfo;
use raspberry\item\enchantment\Enchantment;

use raspberry\math\Facing;
use raspberry\math\Vector3;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\ContainerWindow;
use raspberry\network\mcpe\protocol\AnimatePacket;
use raspberry\network\mcpe\protocol\OpenSignPacket;
use raspberry\network\mcpe\protocol\BlockActorDataPacket;
use raspberry\network\mcpe\protocol\types\LevelEvent;
use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;
use raspberry\network\mcpe\protocol\types\inventory\UseItemData;

use raspberry\world\Chunk;

use function abs;
use function floor;
use function mb_check_encoding;
use function mt_rand;
use function preg_match;
use function rtrim;
use function str_replace;
use function strlen;

class BlockPlacer{

    /** blocks a click replaces instead of placing next to */
    private const string REPLACEABLE = '/^minecraft:(air|water|flowing_water|lava|flowing_lava|fire|soul_fire|short_grass|short_dry_grass|fern|deadbush|bush|vine|glow_lichen|sculk_vein|seagrass|snow_layer|structure_void|light_block_\d+|crimson_roots|warped_roots|nether_sprouts|hanging_roots|leaf_litter)$/D';

    /** clicking these without sneaking uses them instead of placing (their screens come later) */
    private const string USABLE = '/^minecraft:(crafting_table|chest|trapped_chest|ender_chest|barrel|(\w+_)?shulker_box|(lit_)?(furnace|blast_furnace|smoker)|(chipped_|damaged_)?anvil|enchanting_table|brewing_stand|hopper|dispenser|dropper|crafter|beacon|loom|stonecutter_block|grindstone|cartography_table|smithing_table|lectern|bed|cake|\w*candle_cake|noteblock|jukebox|(powered|unpowered)_(repeater|comparator)|daylight_detector(_inverted)?|flower_pot|cauldron|chiseled_bookshelf|respawn_anchor|decorated_pot|vault|composter|\w*_sign|(\w+_)?copper_chest)$/D';

    /** two blocks tall plants (doors are found by their hinge) */
    private const string TALL = '/^minecraft:(tall_grass|large_fern|sunflower|lilac|rose_bush|peony|pitcher_plant|small_dripleaf_block)$/D';

    /** sign text colors of the dyes, as RGB (black text is pure black) */
    private const array DYE_COLORS = [
        "white" => 0xf0f0f0, "orange" => 0xf9801d, "magenta" => 0xc74ebd, "light_blue" => 0x3ab3da,
        "yellow" => 0xfed83d, "lime" => 0x80c71f, "pink" => 0xf38baa, "gray" => 0x474f52,
        "light_gray" => 0x9d9d97, "cyan" => 0x169c9c, "purple" => 0x8932b8, "blue" => 0x3c44aa,
        "brown" => 0x835432, "green" => 0x5e7c16, "red" => 0xb02e26, "black" => 0x000000
    ];

    private Player $player;

    public function __construct(Player $player){
        $this->player = $player;
    }

    /**
     * The client used the held item on a block.
     */
    public function useItemOn(UseItemData $data) : void{
        $player = $this->player;
        $world = $player->getWorld();
        [$x, $y, $z, $face] = [$data->x, $data->y, $data->z, $data->face];

        if($player->isDead() || !$player->getGameMode()->allowsInteraction() || $face < Facing::DOWN || $face > Facing::EAST || !$this->isLoaded($x, $y, $z)){
            return;
        }

        $reach = $player->getGameMode()->hasInstantBuild() ? BlockBreaker::CREATIVE_REACH : BlockBreaker::REACH;

        if($player->getEyePosition()->distance(new Vector3($x + 0.5, $y + 0.5, $z + 0.5)) > $reach){
            $this->resend($x, $y, $z, $face);
            return;
        }

        $clicked = $world->getBlock($x, $y, $z);
        $held = $player->getInventory()->getItemInHand();

        $event = new PlayerInteractEvent($player, $clicked, new Vector3($x, $y, $z), $face, $held, PlayerInteractEvent::RIGHT_CLICK_BLOCK);
        $event->call();

        if($event->isCancelled()){
            $this->resend($x, $y, $z, $face);
            return;
        }

        if(($held->getId() === ItemIds::FLINT_AND_STEEL || $held->getId() === ItemIds::FIRE_CHARGE) && $this->light($x, $y, $z, $clicked, $held)){
            $player->swingArm(AnimatePacket::SWING_INTERACT);
            return;
        }

        if(ToolUse::isTool($held)){
            $result = $player->getGameMode()->allowsBuilding() ? ToolUse::use($player, $x, $y, $z, $face, $clicked, $held) : ToolUse::NOTHING;

            if($result !== ToolUse::NOTHING){
                $player->swingArm(AnimatePacket::SWING_INTERACT);
                $result === ToolUse::USE_UP ? $this->useUpHeld($held) : $this->wearHeld();
                return;
            }
        }

        if(!$player->isSneaking() || $held->isNull()){
            if($this->useBlock($x, $y, $z, $clicked)){
                $player->swingArm(AnimatePacket::SWING_INTERACT);
                return;
            }

            if(preg_match(self::USABLE, $clicked->getName()) === 1){
                return;
            }
        }

        if($held->isNull()){
            return;
        }

        if($held->getId() === ItemIds::BONE_MEAL){
            if($player->getGameMode()->allowsBuilding() && BoneMeal::grow($world, $x, $y, $z)){
                $player->swingArm(AnimatePacket::SWING_INTERACT);
                $this->useUpHeld($held);
            }

            return;
        }

        if($held->getId() === ItemIds::FLINT_AND_STEEL || $held->getId() === ItemIds::FIRE_CHARGE){
            if($player->getGameMode()->allowsBuilding() && Fire::ignite($player, $x, $y, $z, $face, $clicked, $held)){
                $player->swingArm(AnimatePacket::SWING_INTERACT);
            }else{
                $this->resend($x, $y, $z, $face);
            }

            return;
        }

        if(Buckets::isBucket($held->getId())){
            if($player->getGameMode()->allowsBuilding() && Buckets::use($player, $x, $y, $z, $face, $clicked, $held)){
                $player->swingArm(AnimatePacket::SWING_INTERACT);
            }else{
                $this->resend($x, $y, $z, $face);
            }

            return;
        }

        if($held->getId() === ItemIds::PAINTING){
            if($player->getGameMode()->allowsBuilding() && Painting::place($world, $x, $y, $z, $face) !== null){
                $player->swingArm(AnimatePacket::SWING_INTERACT);
                $this->useUpHeld($held);
            }

            return;
        }

        $chestVariant = ChestBoat::variantOf($held->getId());
        $variant = $chestVariant ?? Boat::variantOf($held->getId());

        if($variant !== null){
            if($this->placeBoat($x, $y, $z, $data, $clicked, $variant, $chestVariant !== null)){
                $player->swingArm(AnimatePacket::SWING_INTERACT);
                $this->useUpHeld($held);
            }

            return;
        }

        $mob = EntityFactory::getSpawnEggType($held->getId());

        if($mob !== null && $clicked->getName() === BlockIds::MOB_SPAWNER && $player->getGameMode() === GameMode::CREATIVE){
            $spawner = $world->getBlockEntity($x, $y, $z);

            if(!$spawner instanceof Spawner){
                $spawner = new Spawner($x, $y, $z);
                $world->addBlockEntity($spawner);
            }

            $spawner->setEntityId($mob);
            $world->sendBlockEntity($spawner);
            $player->swingArm(AnimatePacket::SWING_INTERACT);
            return;
        }

        if($mob !== null){
            [$dx, $dy, $dz] = Facing::offset($face);

            if(EntityFactory::create($mob, $world, new Vector3($x + $dx + 0.5, $y + $dy, $z + $dz + 0.5), (float) mt_rand(0, 359)) !== null){
                $player->swingArm(AnimatePacket::SWING_INTERACT);
                $this->useUpHeld($held);
            }

            return;
        }

        $blocks = ItemBlocks::get($held->getId(), $player->getNetworkSession()->getServer()->getBlockStateRegistry());

        if($blocks === null){
            return;
        }

        if(!$player->getGameMode()->allowsBuilding() || !$this->place($x, $y, $z, $face, $data->clickY, $clicked, $blocks[0], $blocks[1], $held)){
            $this->resend($x, $y, $z, $face);
            return;
        }

        $player->swingArm(AnimatePacket::SWING_BUILD);

        $this->useUpHeld($held);
    }

    private function placeBoat(int $x, int $y, int $z, UseItemData $data, BlockState $clicked, int $variant, bool $withChest) : bool{
        $water = $clicked->getName() === BlockIds::WATER || $clicked->getName() === BlockIds::FLOWING_WATER;
        $position = new Vector3($x + $data->clickX, $y + ($water && $data->clickY <= 0.0 ? 1.0 : $data->clickY), $z + $data->clickZ);
        $world = $this->player->getWorld();
        return ($withChest ? ChestBoat::place($world, $position, $this->player->getYaw(), $variant) : Boat::place($world, $position, $this->player->getYaw(), $variant)) !== null;
    }

    /**
     * Takes one of the held item, outside creative.
     */
    private function useUpHeld(Item $held) : void{
        if(!$this->player->getGameMode()->hasInstantBuild()){
            $this->player->getInventory()->setItemInHand($held->getCount() > 1 ? $held->setCount($held->getCount() - 1) : Item::air());
        }
    }

    /**
     * Gives the player an item, it drops at their feet when there is no room.
     */
    private function give(Item $item) : void{
        foreach($this->player->getInventory()->addItem($item) as $left){
            $this->player->getWorld()->dropItem($this->player->getPosition(), $left);
        }
    }

    /**
     * Opens and closes doors, trapdoors and fence gates, flips levers and presses buttons.
     */
    private function useBlock(int $x, int $y, int $z, BlockState $block) : bool{
        $name = $block->getName();
        $entity = $this->player->getWorld()->getBlockEntity($x, $y, $z);

        if($entity instanceof Sign){
            $this->useSign($entity, $block);
            return true;
        }

        if($entity instanceof Jukebox){
            return $this->useJukebox($entity);
        }

        if($entity instanceof Lectern){
            return $this->useLectern($entity);
        }

        if($entity instanceof FlowerPot){
            return $this->useFlowerPot($entity);
        }

        if(Campfire::isCampfire($name)){
            return $this->useCampfire($x, $y, $z, $block, $entity);
        }

        if($name === BlockIds::CAULDRON){
            return Cauldrons::use($this->player, $x, $y, $z, $block);
        }

        if($name === BlockIds::BED){
            return Beds::use($this->player, $x, $y, $z, $block);
        }

        if($name === BlockIds::SWEET_BERRY_BUSH){
            return $this->pickBerries($x, $y, $z, $block);
        }

        if($this->player->getContainerOpener()->open($x, $y, $z, $block)){
            return true;
        }

        if($block->hasProperty(BlockProperties::DOOR_HINGE_BIT)){
            if($name === BlockIds::IRON_DOOR){
                return false;
            }

            $lowerY = $block->getProperty(BlockProperties::UPPER_BLOCK_BIT) === true ? $y - 1 : $y;
            $open = !$this->player->getWorld()->getBlock($x, $lowerY, $z)->getProperty(BlockProperties::OPEN_BIT);

            foreach([$lowerY, $lowerY + 1] as $doorY){
                $half = $this->player->getWorld()->getBlock($x, $doorY, $z);

                if($half->getName() === $name){
                    $this->player->getWorld()->setBlock($x, $doorY, $z, $half->withProperty(BlockProperties::OPEN_BIT, $open));
                }
            }

            $this->playSound($open ? LevelSoundEvent::DOOR_OPEN : LevelSoundEvent::DOOR_CLOSE, $x, $lowerY, $z, $this->player->getWorld()->getBlock($x, $lowerY, $z));
            return true;
        }

        if(preg_match('/trapdoor$/', $name) === 1 && $block->hasProperty(BlockProperties::OPEN_BIT)){
            if($name === BlockIds::IRON_TRAPDOOR){
                return false;
            }

            $block = $block->withProperty(BlockProperties::OPEN_BIT, !$block->getProperty(BlockProperties::OPEN_BIT));
            $this->player->getWorld()->setBlock($x, $y, $z, $block);
            $this->playSound($block->getProperty(BlockProperties::OPEN_BIT) === true ? LevelSoundEvent::TRAPDOOR_OPEN : LevelSoundEvent::TRAPDOOR_CLOSE, $x, $y, $z, $block);
            return true;
        }

        if(preg_match('/fence_gate$/', $name) === 1){
            $open = $block->getProperty(BlockProperties::OPEN_BIT) !== true;
            $block = $block->withProperty(BlockProperties::OPEN_BIT, $open);
            $facing = Facing::fromYaw($this->player->getYaw());

            if($open && $block->getProperty(BlockProperties::MINECRAFT_CARDINAL_DIRECTION) === Facing::name(Facing::opposite($facing))){
                $block = $block->withProperty(BlockProperties::MINECRAFT_CARDINAL_DIRECTION, Facing::name($facing));
            }

            $this->player->getWorld()->setBlock($x, $y, $z, $block);
            $this->playSound($open ? LevelSoundEvent::FENCE_GATE_OPEN : LevelSoundEvent::FENCE_GATE_CLOSE, $x, $y, $z, $block);
            return true;
        }

        if(preg_match('/^minecraft:(powered|unpowered)_repeater$/D', $name) === 1){
            $this->player->getWorld()->setBlock($x, $y, $z, $block->withProperty(BlockProperties::REPEATER_DELAY, ((int) $block->getProperty(BlockProperties::REPEATER_DELAY) + 1) % 4));
            return true;
        }

        if(preg_match('/^minecraft:(powered|unpowered)_comparator$/D', $name) === 1){
            $subtract = $block->getProperty(BlockProperties::OUTPUT_SUBTRACT_BIT) !== true;
            $this->player->getWorld()->setBlock($x, $y, $z, $block->withProperty(BlockProperties::OUTPUT_SUBTRACT_BIT, $subtract));
            $this->playSound($subtract ? LevelSoundEvent::POWER_ON : LevelSoundEvent::POWER_OFF, $x, $y, $z, null);
            return true;
        }

        if($name === BlockIds::DAYLIGHT_DETECTOR || $name === BlockIds::DAYLIGHT_DETECTOR_INVERTED){
            $other = $this->player->getNetworkSession()->getServer()->getBlockStateRegistry()->getDefaultState($name === BlockIds::DAYLIGHT_DETECTOR ? BlockIds::DAYLIGHT_DETECTOR_INVERTED : BlockIds::DAYLIGHT_DETECTOR);
            $this->player->getWorld()->setBlock($x, $y, $z, $other);
            return true;
        }

        if($name === BlockIds::LEVER){
            $block = $block->withProperty(BlockProperties::OPEN_BIT, $block->getProperty(BlockProperties::OPEN_BIT) !== true);
            $this->player->getWorld()->setBlock($x, $y, $z, $block);
            $this->playSound($block->getProperty(BlockProperties::OPEN_BIT) === true ? LevelSoundEvent::POWER_ON : LevelSoundEvent::POWER_OFF, $x, $y, $z, null);
            return true;
        }

        if($block->hasProperty(BlockProperties::BUTTON_PRESSED_BIT)){
            if($block->getProperty(BlockProperties::BUTTON_PRESSED_BIT) === true){
                return true;
            }

            $world = $this->player->getWorld();
            $world->setBlock($x, $y, $z, $block->withProperty(BlockProperties::BUTTON_PRESSED_BIT, true));
            $this->playSound(LevelSoundEvent::BUTTON_CLICK_ON, $x, $y, $z, $block);

            $world->scheduleUpdate($name === BlockIds::STONE_BUTTON || $name === BlockIds::POLISHED_BLACKSTONE_BUTTON ? 20 : 30, function() use ($world, $x, $y, $z, $name) : void{
                $current = $world->getBlock($x, $y, $z);

                if($current->getName() === $name && $current->getProperty(BlockProperties::BUTTON_PRESSED_BIT) === true){
                    $current = $current->withProperty(BlockProperties::BUTTON_PRESSED_BIT, false);
                    $world->setBlock($x, $y, $z, $current);
                    $world->broadcastPacket($x, $z, LevelSoundEventPacket::create(LevelSoundEvent::BUTTON_CLICK_OFF, $x + 0.5, $y + 0.5, $z + 0.5, $this->getNetworkId($current)));
                }
            });
            return true;
        }

        return false;
    }

    /**
     * Places $blockName (or $wallBlockName on a wall) against the clicked block. False when it
     * cannot go there.
     */
    private function place(int $x, int $y, int $z, int $face, float $clickY, BlockState $clicked, string $blockName, ?string $wallBlockName, Item $held) : bool{
        $world = $this->player->getWorld();
        $registry = $this->player->getNetworkSession()->getServer()->getBlockStateRegistry();

        if($this->mergeSlab($x, $y, $z, $face, $clicked, $blockName)){
            return true;
        }

        if($blockName === BlockIds::SCAFFOLDING){
            return $this->placeScaffolding($x, $y, $z, $face, $clicked, $held);
        }

        if($this->isReplaceable($clicked) && $clicked->getName() !== $blockName){
            $face = Facing::UP;
        }else{
            [$dx, $dy, $dz] = Facing::offset($face);
            [$x, $y, $z] = [$x + $dx, $y + $dy, $z + $dz];

            if(!$this->isLoaded($x, $y, $z)){
                return false;
            }

            $target = $world->getBlock($x, $y, $z);

            if(!$this->isReplaceable($target) || $target->getName() === $blockName){
                return false;
            }
        }

        if($wallBlockName !== null && Facing::isHorizontal($face)){
            $blockName = $wallBlockName;
        }

        $facing = Facing::fromYaw($this->player->getYaw());
        [$ax, $ay, $az] = Facing::offset(Facing::opposite($face));
        $state = BlockOrientation::orient($registry->getDefaultState($blockName), $face, $facing, Facing::rotationFromYaw($this->player->getYaw()), $clickY, $this->getFaceTowardPlayer($x, $y, $z, $facing), $this->getBlockOrAir($x + $ax, $y + $ay, $z + $az));

        if($blockName === BlockIds::POINTED_DRIPSTONE){
            $state = PointedDripstone::getPlacedState($world, $x, $y, $z, $face, !$this->player->isSneaking());
        }elseif($blockName === BlockIds::BIG_DRIPLEAF && $state !== null){
            $state = BigDripleaf::getPlacedState($world, $x, $y, $z, $state);
        }

        if($state === null || !$registry->isValid($state) || !$this->hasSupport($state, $x, $y, $z)){
            return false;
        }

        if($state->hasProperty(BlockProperties::PERSISTENT_BIT)){
            $state = $state->withProperty(BlockProperties::PERSISTENT_BIT, true);
        }

        $blocks = [[$x, $y, $z, $state]];

        if($state->hasProperty(BlockProperties::DOOR_HINGE_BIT)){
            //the hinge goes on the right when there is a door on the left
            [$lx, , $lz] = Facing::offset(Facing::rotateLeft($facing));

            if($this->getBlockOrAir($x + $lx, $y, $z + $lz)->hasProperty(BlockProperties::DOOR_HINGE_BIT)){
                $state = $state->withProperty(BlockProperties::DOOR_HINGE_BIT, true);
                $blocks = [[$x, $y, $z, $state]];
            }
        }

        if($state->hasProperty(BlockProperties::DOOR_HINGE_BIT) || preg_match(self::TALL, $blockName) === 1){
            $blocks[] = [$x, $y + 1, $z, $state->withProperty(BlockProperties::UPPER_BLOCK_BIT, true)];
        }elseif($state->hasProperty(BlockProperties::HEAD_PIECE_BIT)){
            [$fx, , $fz] = Facing::offset($facing);
            $blocks[] = [$x + $fx, $y, $z + $fz, $state->withProperty(BlockProperties::HEAD_PIECE_BIT, true)];
        }

        foreach($blocks as $i => [$bx, $by, $bz, $block]){
            if($i > 0 && (!$this->isLoaded($bx, $by, $bz) || !$this->isReplaceable($world->getBlock($bx, $by, $bz)) || !$this->hasSupport($block, $bx, $by, $bz))){
                return false;
            }

            if($this->isInsidePlayer($block, $bx, $by, $bz)){
                return false;
            }
        }

        $event = new BlockPlaceEvent($this->player, $state, new Vector3($x, $y, $z), $held);
        $event->call();

        if($event->isCancelled()){
            return false;
        }

        foreach($blocks as [$bx, $by, $bz, $block]){
            $world->setBlock($bx, $by, $bz, $block);
        }

        foreach($blocks as [$bx, $by, $bz, $block]){
            $entity = BlockEntityFactory::create($block->getName(), $bx, $by, $bz);

            if($entity !== null){
                $entity->onPlace($held, $this->player, $face);
                $world->addBlockEntity($entity);
            }
        }

        $entity = $world->getBlockEntity($x, $y, $z);

        if($entity instanceof Chest){
            $this->pairChest($entity, $state);
        }

        if($state->hasProperty(BlockProperties::MINECRAFT_CORNER)){
            StairsShape::updateAround($world, $x, $y, $z);
        }

        $this->playSound(LevelSoundEvent::PLACE, $x, $y, $z, $world->getBlock($x, $y, $z));

        if($entity instanceof Sign){
            $this->openSign($entity, true);
        }

        return true;
    }

    /**
     * Clicking the bottom of scaffolding, or where scaffolding already is, builds the column up.
     */
    private function placeScaffolding(int $x, int $y, int $z, int $face, BlockState $clicked, Item $held) : bool{
        $world = $this->player->getWorld();
        $target = Scaffolding::getPlacement($world, $x, $y, $z, $face, $this->isReplaceable($clicked));

        if($target === null){
            return false;
        }

        [$clickedX, $clickedY, $clickedZ] = [$x, $y, $z];
        [$x, $y, $z] = $target;
        $replaced = $world->getBlock($x, $y, $z);

        if(!$this->isReplaceable($replaced) || $replaced->getName() === BlockIds::LAVA || $replaced->getName() === BlockIds::FLOWING_LAVA){
            return false;
        }

        $state = Scaffolding::getPlacedState($world, $x, $y, $z);

        if($state === null){
            return false;
        }

        $event = new BlockPlaceEvent($this->player, $state, new Vector3($x, $y, $z), $held);
        $event->call();

        if($event->isCancelled()){
            return false;
        }

        $world->setBlock($x, $y, $z, $state);
        $this->playSound(LevelSoundEvent::PLACE, $x, $y, $z, $state);

        //the client may have put its piece where the click was, not on top of the column
        $this->resend($clickedX, $clickedY, $clickedZ, $face);

        return true;
    }

    /**
     * Picks the berries off a bush that has some, it is left as a bush without berries. Bone
     * meal on a bush that is not full grows it instead.
     */
    private function pickBerries(int $x, int $y, int $z, BlockState $bush) : bool{
        $growth = (int) $bush->getProperty(BlockProperties::GROWTH);

        if($growth < 2 || !$this->player->getGameMode()->allowsBuilding()){
            return false;
        }

        if($growth < 3 && $this->player->getInventory()->getItemInHand()->getId() === ItemIds::BONE_MEAL){
            return false;
        }

        $world = $this->player->getWorld();
        $world->setBlock($x, $y, $z, $bush->withProperty(BlockProperties::GROWTH, 1));
        $world->dropItem(new Vector3($x + 0.5, $y + 0.5, $z + 0.5), new Item(ItemIds::SWEET_BERRIES, mt_rand(1, 2) + ($growth >= 3 ? 1 : 0)));
        $this->playSound(LevelSoundEvent::BLOCK_SWEET_BERRY_BUSH_PICK, $x, $y, $z, null);
        return true;
    }

    /**
     * Lights a campfire or candles that are out. False when the block does not light.
     */
    private function light(int $x, int $y, int $z, BlockState $block, Item $held) : bool{
        $name = $block->getName();

        $lit = match(true){
            Campfire::isCampfire($name) && $block->getProperty(BlockProperties::EXTINGUISHED) === true => $block->withProperty(BlockProperties::EXTINGUISHED, false),
            preg_match('/^minecraft:(\w+_)?candle(_cake)?$/D', $name) === 1 && $block->getProperty(BlockProperties::LIT) === false => $block->withProperty(BlockProperties::LIT, true),
            default => null
        };

        if($lit === null || !$this->player->getGameMode()->allowsBuilding()){
            return false;
        }

        $this->player->getWorld()->setBlock($x, $y, $z, $lit);

        if($held->getId() === ItemIds::FIRE_CHARGE){
            $this->player->getWorld()->broadcastPacket($x, $z, LevelEventPacket::create(LevelEvent::SOUND_BLAZE_FIREBALL, $x + 0.5, $y + 0.5, $z + 0.5));
            $this->useUpHeld($held);
        }else{
            $this->playSound(LevelSoundEvent::IGNITE, $x, $y, $z, null);
            $this->wearHeld();
        }

        return true;
    }

    /**
     * A shovel puts a lit campfire out, raw food goes on it to cook.
     */
    private function useCampfire(int $x, int $y, int $z, BlockState $block, ?BlockEntity $entity) : bool{
        $world = $this->player->getWorld();
        $held = $this->player->getInventory()->getItemInHand();

        if($held->isNull() || !$this->player->getGameMode()->allowsBuilding()){
            return false;
        }

        if(!$entity instanceof Campfire){
            $entity = new Campfire($x, $y, $z);
            $world->addBlockEntity($entity);
        }

        if(ToolInfo::getType($held->getId()) === "shovel"){
            if($entity->extinguish($world)){
                $this->wearHeld();
                return true;
            }

            return false;
        }

        if($entity->addItem($world, $held)){
            $this->useUpHeld($held);
            return true;
        }

        return false;
    }

    /**
     * Wears the held tool down by one use, unbreaking can save it.
     */
    private function wearHeld() : void{
        $inventory = $this->player->getInventory();
        $item = $inventory->getItemInHand();

        if($this->player->getGameMode()->hasInstantBuild() || !$item->hasDurability()){
            return;
        }

        $unbreaking = $item->getEnchantmentLevel(Enchantment::UNBREAKING);

        if($unbreaking > 0 && mt_rand(0, $unbreaking) !== 0){
            return;
        }

        if($item->getDamage() + 1 >= $item->getMaxDurability()){
            $inventory->setItemInHand(Item::air());
            $position = $this->player->getPosition();
            $this->player->getNetworkSession()->sendDataPacket(LevelSoundEventPacket::create(LevelSoundEvent::BREAK, $position->x, $position->y, $position->z));
            return;
        }

        $inventory->setItemInHand($item->setDamage($item->getDamage() + 1));
    }

    /**
     * A disc goes in, or the one playing comes out.
     */
    private function useJukebox(Jukebox $jukebox) : bool{
        $held = $this->player->getInventory()->getItemInHand();

        if($jukebox->getRecord() !== null){
            $jukebox->eject($this->player->getWorld());
            return true;
        }

        if(!Jukebox::isDisc($held)){
            return false;
        }

        $jukebox->insert($this->player->getWorld(), $held);
        $this->useUpHeld($held);

        return true;
    }

    /**
     * A book is put on the lectern, or the one on it is opened.
     */
    private function useLectern(Lectern $lectern) : bool{
        $world = $this->player->getWorld();
        $held = $this->player->getInventory()->getItemInHand();
        [$x, $y, $z] = [$lectern->getX(), $lectern->getY(), $lectern->getZ()];

        if($lectern->getBook() !== null){
            $this->player->getNetworkSession()->getInventoryManager()?->openScreen(ContainerWindow::TYPE_LECTERN, $x, $y, $z);
            return true;
        }

        if(!Lectern::isBook($held)){
            return false;
        }

        $lectern->setBook($held);
        $this->useUpHeld($held);
        $world->sendBlockEntity($lectern);
        $world->getRedstoneUpdater()->update($x, $y, $z);
        $this->playSound(LevelSoundEvent::ITEM_BOOK_PUT, $x, $y, $z, null);

        return true;
    }

    /**
     * The client turned a page of the book on a lectern.
     */
    public function turnLecternPage(int $x, int $y, int $z, int $page) : void{
        if(!$this->isLoaded($x, $y, $z) || $this->player->getEyePosition()->distance(new Vector3($x + 0.5, $y + 0.5, $z + 0.5)) > ContainerOpener::MAX_DISTANCE){
            return;
        }

        $world = $this->player->getWorld();
        $lectern = $world->getBlockEntity($x, $y, $z);

        if(!$lectern instanceof Lectern || $lectern->getBook() === null || $page < 0 || $page >= $lectern->getTotalPages() || $page === $lectern->getPage()){
            return;
        }

        $lectern->setPage($page);
        $world->sendBlockEntity($lectern);
        $world->getRedstoneUpdater()->pulseLectern($x, $y, $z);
        $world->getRedstoneUpdater()->update($x, $y, $z);
    }

    /**
     * A plant goes in the pot, or the one in it comes out.
     */
    private function useFlowerPot(FlowerPot $pot) : bool{
        $held = $this->player->getInventory()->getItemInHand();
        $plant = $pot->getPlant();
        $registry = $this->player->getNetworkSession()->getServer()->getBlockStateRegistry();
        $blocks = $held->isNull() ? null : ItemBlocks::get($held->getId(), $registry);
        $pottable = $blocks !== null && FlowerPot::canHold($blocks[0]);

        if($plant !== null){
            if($pottable){
                return false;
            }

            $this->setPotPlant($pot, null);
            $this->give(new Item($plant->getName()));
            return true;
        }

        if(!$pottable){
            return false;
        }

        $this->setPotPlant($pot, $registry->getDefaultState($blocks[0]));
        $this->useUpHeld($held);

        return true;
    }

    /**
     * The client only redraws a pot when the block itself changes, update_bit is flipped for that.
     */
    private function setPotPlant(FlowerPot $pot, ?BlockState $plant) : void{
        $world = $this->player->getWorld();
        [$x, $y, $z] = [$pot->getX(), $pot->getY(), $pot->getZ()];

        $pot->setPlant($plant);
        $world->setBlock($x, $y, $z, $world->getBlock($x, $y, $z)->withProperty(BlockProperties::UPDATE_BIT, $plant !== null));
        $world->sendBlockEntity($pot);
    }

    private function pairChest(Chest $chest, BlockState $state) : void{
        $world = $this->player->getWorld();
        $facing = Facing::fromName((string) $state->getProperty(BlockProperties::MINECRAFT_CARDINAL_DIRECTION));

        foreach([Facing::rotateLeft($facing), Facing::rotateRight($facing)] as $side){
            [$dx, , $dz] = Facing::offset($side);
            [$x, $y, $z] = [$chest->getX() + $dx, $chest->getY(), $chest->getZ() + $dz];

            if(!$this->isLoaded($x, $y, $z) || !Chest::canPair($world->getBlock($x, $y, $z), $state)){
                continue;
            }

            $other = $world->getBlockEntity($x, $y, $z);

            if($other instanceof Chest && !$other->isPaired()){
                $chest->pairWith($other);
                $world->sendBlockEntity($chest);
                $world->sendBlockEntity($other);
                return;
            }
        }
    }

    private function useSign(Sign $sign, BlockState $block) : void{
        $world = $this->player->getWorld();
        [$x, $y, $z] = [$sign->getX(), $sign->getY(), $sign->getZ()];
        $held = $this->player->getInventory()->getItemInHand();
        $id = $held->getId();
        $front = $sign->isFrontFacing($block, $this->player->getPosition());

        if($sign->isWaxed()){
            $this->playSound(LevelSoundEvent::BLOCK_SIGN_WAXED_INTERACT_FAIL, $x, $y, $z, null);
            return;
        }

        if($id === ItemIds::HONEYCOMB){
            $sign->setWaxed(true);
            $world->broadcastPacket($x, $z, LevelEventPacket::create(LevelEvent::WAX_ON, $x + 0.5, $y + 0.5, $z + 0.5));
        }elseif(preg_match('/^minecraft:(\w+)_dye$/D', $id, $matches) === 1 && isset(self::DYE_COLORS[$matches[1]]) && $sign->getColor($front) !== self::toArgb(self::DYE_COLORS[$matches[1]])){
            $sign->setColor($front, self::toArgb(self::DYE_COLORS[$matches[1]]));
        }elseif(($id === ItemIds::GLOW_INK_SAC || $id === ItemIds::INK_SAC) && $sign->isGlowing($front) !== ($id === ItemIds::GLOW_INK_SAC)){
            $sign->setGlowing($front, $id === ItemIds::GLOW_INK_SAC);
        }else{
            $this->openSign($sign, $front);
            return;
        }

        $world->sendBlockEntity($sign);

        if(!$this->player->getGameMode()->hasInstantBuild()){
            $this->player->getInventory()->setItemInHand($held->getCount() > 1 ? $held->setCount($held->getCount() - 1) : Item::air());
        }
    }

    /**
     * An opaque RGB color as the signed ARGB int signs store.
     */
    private static function toArgb(int $rgb) : int{
        $argb = $rgb | 0xff000000;
        return $argb > 0x7fffffff ? $argb - 0x100000000 : $argb;
    }

    private function openSign(Sign $sign, bool $front) : void{
        $sign->setEditor($this->player->getId());
        $this->player->getNetworkSession()->sendDataPacket(OpenSignPacket::create($sign->getX(), $sign->getY(), $sign->getZ(), $front));
    }

    /**
     * The client finished editing a sign and sends its text. Only the player the editor was
     * opened for may change it.
     */
    public function editSign(int $x, int $y, int $z, CompoundTag $nbt) : void{
        $world = $this->player->getWorld();

        if(!$this->isLoaded($x, $y, $z)){
            return;
        }

        $sign = $world->getBlockEntity($x, $y, $z);

        if(!$sign instanceof Sign){
            return;
        }

        $reach = $this->player->getGameMode()->hasInstantBuild() ? BlockBreaker::CREATIVE_REACH : BlockBreaker::REACH;

        if($sign->getEditor() !== $this->player->getId() || $sign->isWaxed() || $this->player->getEyePosition()->distance(new Vector3($x + 0.5, $y + 0.5, $z + 0.5)) > $reach){
            $this->player->getNetworkSession()->sendDataPacket(BlockActorDataPacket::create($x, $y, $z, $sign->getSpawnData()));
            return;
        }

        $sign->setEditor(null);

        foreach([[true, "FrontText"], [false, "BackText"]] as [$front, $name]){
            $text = $nbt->getCompoundTag($name)?->getString("Text");

            if($text === null){
                continue;
            }

            $text = rtrim($text, "\n");

            if(strlen($text) > Sign::MAX_TEXT_LENGTH || !mb_check_encoding($text, "UTF-8")){
                $this->player->getNetworkSession()->sendDataPacket(BlockActorDataPacket::create($x, $y, $z, $sign->getSpawnData()));
                return;
            }

            if($text === $sign->getText($front)){
                continue;
            }

            $event = new SignChangeEvent($this->player, $sign, $front, $text);
            $event->call();

            if(!$event->isCancelled()){
                $sign->setText($front, $event->getText(), $this->player->getXuid());
            }
        }

        $world->sendBlockEntity($sign);
    }

    /**
     * A slab put on a slab of the same kind makes a double slab.
     */
    private function mergeSlab(int $x, int $y, int $z, int $face, BlockState $clicked, string $blockName) : bool{
        if(!$clicked->hasProperty(BlockProperties::MINECRAFT_VERTICAL_HALF) || preg_match('/_slab$/', $blockName) !== 1 || preg_match('/_double_slab$/', $blockName) === 1){
            return false;
        }

        $world = $this->player->getWorld();
        $registry = $this->player->getNetworkSession()->getServer()->getBlockStateRegistry();
        $double = str_replace("_slab", "_double_slab", $blockName);

        if(!$registry->isRegistered($double)){
            return false;
        }

        $half = $clicked->getName() === $blockName ? $clicked->getProperty(BlockProperties::MINECRAFT_VERTICAL_HALF) : null;

        if(($face === Facing::UP && $half === "bottom") || ($face === Facing::DOWN && $half === "top")){
            $target = [$x, $y, $z];
        }else{
            [$dx, $dy, $dz] = Facing::offset($face);

            if(!$this->isLoaded($x + $dx, $y + $dy, $z + $dz) || $world->getBlock($x + $dx, $y + $dy, $z + $dz)->getName() !== $blockName || $this->isReplaceable($clicked)){
                return false;
            }

            $target = [$x + $dx, $y + $dy, $z + $dz];
        }

        $state = $registry->getDefaultState($double);

        if($this->isInsidePlayer($state, ...$target)){
            return false;
        }

        $world->setBlock($target[0], $target[1], $target[2], $state);
        $this->playSound(LevelSoundEvent::PLACE, $target[0], $target[1], $target[2], $state);

        return true;
    }

    /**
     * Whether the block has what it needs to stay: soil, farmland, or a solid block to stand
     * on or hang from.
     */
    private function hasSupport(BlockState $state, int $x, int $y, int $z) : bool{
        return BlockSupport::canStay($this->player->getWorld(), $x, $y, $z, $state, true);
    }

    /**
     * Solid blocks cannot be placed where a player stands. Doors and beds are let through,
     * like vanilla.
     */
    private function isInsidePlayer(BlockState $block, int $x, int $y, int $z) : bool{
        if(!BlockCollision::isSolid($block) || $block->hasProperty(BlockProperties::DOOR_HINGE_BIT) || $block->hasProperty(BlockProperties::HEAD_PIECE_BIT)){
            return false;
        }

        $halfWidth = Human::WIDTH / 2 - 0.001;

        foreach($this->player->getWorld()->getPlayers() as $player){
            if($player->isDead() || !$player->getGameMode()->hasCollision()){
                continue;
            }

            $position = $player->getPosition();

            if(
                $position->x + $halfWidth > $x && $position->x - $halfWidth < $x + 1 &&
                $position->y + Human::HEIGHT - 0.001 > $y && $position->y + 0.001 < $y + 1 &&
                $position->z + $halfWidth > $z && $position->z - $halfWidth < $z + 1
            ){
                return true;
            }
        }

        return false;
    }

    /**
     * The side of the new block that faces the player: up or down when the player is right
     * above or below it, else the horizontal side (like dispensers and pistons are placed).
     */
    private function getFaceTowardPlayer(int $x, int $y, int $z, int $facing) : int{
        $position = $this->player->getPosition();

        if(abs((int) floor($position->x) - $x) < 2 && abs((int) floor($position->z) - $z) < 2){
            $eyeY = $this->player->getEyePosition()->y;

            if($eyeY - $y > 2){
                return Facing::UP;
            }

            if($y - $eyeY > 0){
                return Facing::DOWN;
            }
        }

        return Facing::opposite($facing);
    }

    private function isReplaceable(BlockState $block) : bool{
        return preg_match(self::REPLACEABLE, $block->getName()) === 1;
    }

    private function isLoaded(int $x, int $y, int $z) : bool{
        return $y >= Chunk::MIN_Y && $y <= Chunk::MAX_Y && $this->player->getWorld()->isChunkLoaded($x >> 4, $z >> 4);
    }

    private function getBlockOrAir(int $x, int $y, int $z) : BlockState{
        if(!$this->isLoaded($x, $y, $z)){
            return $this->player->getNetworkSession()->getServer()->getBlockStateRegistry()->getDefaultState(BlockIds::AIR);
        }

        return $this->player->getWorld()->getBlock($x, $y, $z);
    }

    /**
     * Undoes what the client predicted: the blocks around the click and the held item.
     */
    private function resend(int $x, int $y, int $z, int $face) : void{
        $session = $this->player->getNetworkSession();
        [$dx, $dy, $dz] = Facing::offset($face);

        foreach([[$x, $y, $z], [$x + $dx, $y + $dy, $z + $dz], [$x + $dx, $y + $dy + 1, $z + $dz]] as [$bx, $by, $bz]){
            if($this->isLoaded($bx, $by, $bz)){
                $session->sendBlock($bx, $by, $bz, $this->player->getWorld()->getBlock($bx, $by, $bz));
            }
        }

        $session->resendHeldItem();
    }

    /**
     * Plays a sound for everyone who can see the block, the player too.
     */
    private function playSound(string $sound, int $x, int $y, int $z, ?BlockState $block) : void{
        $this->player->getWorld()->broadcastPacket($x, $z, LevelSoundEventPacket::create($sound, $x + 0.5, $y + 0.5, $z + 0.5, $block === null ? -1 : $this->getNetworkId($block)));
    }

    private function getNetworkId(BlockState $block) : int{
        return $this->player->getNetworkSession()->getServer()->getBlockTranslator()->getNetworkId($block);
    }
}