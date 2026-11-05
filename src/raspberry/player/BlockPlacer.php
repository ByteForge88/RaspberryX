<?php

declare(strict_types=1);

namespace raspberry\player;

use raspberry\block\BlockCollision;
use raspberry\block\BlockIds;
use raspberry\block\BlockOrientation;
use raspberry\block\BlockProperties;
use raspberry\block\BlockState;
use raspberry\block\ItemBlocks;
use raspberry\block\StairsShape;
use raspberry\block\entity\Sign;
use raspberry\block\entity\Chest;
use raspberry\block\entity\BlockEntityFactory;

use raspberry\entity\Human;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\math\Facing;
use raspberry\math\Vector3;

use raspberry\nbt\tag\CompoundTag;

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
use function preg_match;
use function rtrim;
use function str_replace;
use function strlen;

class BlockPlacer{

    /** blocks a click replaces instead of placing next to */
    private const REPLACEABLE = '/^minecraft:(air|water|flowing_water|lava|flowing_lava|fire|soul_fire|short_grass|short_dry_grass|fern|deadbush|bush|vine|glow_lichen|sculk_vein|seagrass|snow_layer|structure_void|light_block_\d+|crimson_roots|warped_roots|nether_sprouts|hanging_roots|leaf_litter)$/D';

    /** clicking these without sneaking uses them instead of placing (their screens come later) */
    private const USABLE = '/^minecraft:(crafting_table|chest|trapped_chest|ender_chest|barrel|(\w+_)?shulker_box|(lit_)?(furnace|blast_furnace|smoker)|(chipped_|damaged_)?anvil|enchanting_table|brewing_stand|hopper|dispenser|dropper|crafter|beacon|loom|stonecutter_block|grindstone|cartography_table|smithing_table|lectern|bed|cake|\w*candle_cake|noteblock|jukebox|(powered|unpowered)_(repeater|comparator)|daylight_detector(_inverted)?|flower_pot|cauldron|chiseled_bookshelf|respawn_anchor|decorated_pot|vault|composter|\w*_sign|(\w+_)?copper_chest)$/D';

    /** crops grow on farmland */
    private const CROPS = '/^minecraft:(wheat|carrots|potatoes|beetroot|melon_stem|pumpkin_stem|torchflower_crop|pitcher_crop)$/D';

    /** plants that need soil under them */
    private const PLANTS = '/^minecraft:(\w+_sapling|short_grass|fern|tall_grass|large_fern|sunflower|lilac|rose_bush|peony|(golden_)?dandelion|poppy|blue_orchid|allium|azure_bluet|\w+_tulip|oxeye_daisy|cornflower|lily_of_the_valley|wither_rose|torchflower|pitcher_plant|(open|closed)_eyeblossom|pink_petals|wildflowers|sweet_berry_bush|bush|firefly_bush|azalea|flowering_azalea)$/D';

    private const SOIL = '/^minecraft:(grass_block|dirt|coarse_dirt|podzol|dirt_with_roots|farmland|mycelium|moss_block|pale_moss_block|mud|muddy_mangrove_roots)$/D';

    /** blocks that stand on a solid block */
    private const ON_FLOOR = '/^minecraft:(\w*_door|bed|straw_bed|\w*standing_sign|standing_banner|\w*carpet|\w*rail|redstone_wire|(powered|unpowered)_(repeater|comparator)|\w*pressure_plate|cake|\w*candle|snow_layer|sea_pickle|deadbush|(short|tall)_dry_grass|(brown|red)_mushroom|(crimson|warped)_(fungus|roots)|nether_sprouts|cactus_flower|turtle_egg|leaf_litter)$/D';

    /** blocks that hang on the block they were placed against */
    private const ATTACHED = '/^minecraft:(\w*torch(_\w+)?|\w+_button|lever|ladder|\w*wall_sign|wall_banner|tripwire_hook|\w+_coral_wall_fan|(\w+_)?amethyst_(bud|cluster)|(glow_)?frame)$/D';

    /** two blocks tall plants (doors are found by their hinge) */
    private const TALL = '/^minecraft:(tall_grass|large_fern|sunflower|lilac|rose_bush|peony|pitcher_plant|small_dripleaf_block)$/D';

    /** sign text colors of the dyes, as RGB (black text is pure black) */
    private const DYE_COLORS = [
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

        $blocks = ItemBlocks::get($held->getId(), $player->getNetworkSession()->getServer()->getBlockStateRegistry());

        if($blocks === null){
            return;
        }

        if(!$player->getGameMode()->allowsBuilding() || !$this->place($x, $y, $z, $face, $data->clickY, $clicked, $blocks[0], $blocks[1], $held)){
            $this->resend($x, $y, $z, $face);
            return;
        }

        $player->swingArm(AnimatePacket::SWING_BUILD);

        if(!$player->getGameMode()->hasInstantBuild()){
            $player->getInventory()->setItemInHand($held->getCount() > 1 ? $held->setCount($held->getCount() - 1) : Item::air());
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

            //a gate opens away from the player
            if($open && $block->getProperty(BlockProperties::MINECRAFT_CARDINAL_DIRECTION) === Facing::name(Facing::opposite($facing))){
                $block = $block->withProperty(BlockProperties::MINECRAFT_CARDINAL_DIRECTION, Facing::name($facing));
            }

            $this->player->getWorld()->setBlock($x, $y, $z, $block);
            $this->playSound($open ? LevelSoundEvent::FENCE_GATE_OPEN : LevelSoundEvent::FENCE_GATE_CLOSE, $x, $y, $z, $block);
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

            //stone buttons stay pressed for a second, wooden ones for a second and a half
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

        if($state === null || !$registry->isValid($state) || !$this->hasSupport($state, $x, $y, $z, $face)){
            return false;
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
            if($i > 0 && (!$this->isLoaded($bx, $by, $bz) || !$this->isReplaceable($world->getBlock($bx, $by, $bz)) || !$this->hasSupport($block, $bx, $by, $bz, Facing::UP))){
                return false;
            }

            if($this->isInsidePlayer($block, $bx, $by, $bz)){
                return false;
            }
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

            if($text !== $sign->getText($front)){
                $sign->setText($front, $text, $this->player->getXuid());
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
     * on or hang from. $face is the face it was placed against.
     */
    private function hasSupport(BlockState $state, int $x, int $y, int $z, int $face) : bool{
        $name = $state->getName();
        $below = $this->getBlockOrAir($x, $y - 1, $z);

        if(preg_match(self::CROPS, $name) === 1){
            return $below->getName() === BlockIds::FARMLAND;
        }

        if($name === BlockIds::NETHER_WART){
            return $below->getName() === BlockIds::SOUL_SAND;
        }

        if($name === BlockIds::CACTUS){
            return preg_match('/^minecraft:(sand|red_sand|cactus)$/D', $below->getName()) === 1;
        }

        if($name === BlockIds::REEDS){
            return $below->getName() === BlockIds::REEDS || preg_match(self::SOIL, $below->getName()) === 1 || preg_match('/^minecraft:(red_)?sand$/D', $below->getName()) === 1;
        }

        if(preg_match(self::PLANTS, $name) === 1){
            return preg_match(self::SOIL, $below->getName()) === 1 || $this->isUpperHalf($state);
        }

        if($name === BlockIds::COCOA){
            [$dx, , $dz] = Facing::offset(Facing::opposite($face));

            return preg_match('/^minecraft:(stripped_)?jungle_(log|wood)$/D', $this->getBlockOrAir($x + $dx, $y, $z + $dz)->getName()) === 1;
        }

        if($state->hasProperty(BlockProperties::HANGING) && preg_match('/lantern$/', $name) === 1){
            return BlockCollision::isSolid($this->getBlockOrAir($x, $y + ($state->getProperty(BlockProperties::HANGING) === true ? 1 : -1), $z));
        }

        if(preg_match(self::ATTACHED, $name) === 1){
            [$dx, $dy, $dz] = Facing::offset(Facing::opposite($face));

            return BlockCollision::isSolid($this->getBlockOrAir($x + $dx, $y + $dy, $z + $dz));
        }

        if(preg_match(self::ON_FLOOR, $name) === 1){
            return $this->isUpperHalf($state) || BlockCollision::isSolid($below);
        }

        return true;
    }

    /**
     * The top of a door or tall plant stands on its bottom half.
     */
    private function isUpperHalf(BlockState $state) : bool{
        return $state->hasProperty(BlockProperties::UPPER_BLOCK_BIT) && $state->getProperty(BlockProperties::UPPER_BLOCK_BIT) === true;
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