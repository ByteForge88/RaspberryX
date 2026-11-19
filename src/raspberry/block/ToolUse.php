<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\Server;

use raspberry\block\growth\Oxidation;

use raspberry\event\block\BlockChangeEvent;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\math\Facing;
use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelEvent;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\player\Player;

use raspberry\world\Chunk;
use raspberry\world\World;

use function preg_match;
use function str_ends_with;
use function str_starts_with;
use function substr;

class ToolUse{

    public const int NOTHING = 0;

    /** the tool loses a use */
    public const int WEAR = 1;

    /** one of the held item goes, like honeycomb */
    public const int USE_UP = 2;

    private const string STRIPPABLE = '/^minecraft:(?!stripped_)(\w+_(log|wood|stem|hyphae)|bamboo_block)$/D';

    private const array TILLED = [
        BlockIds::DIRT => BlockIds::FARMLAND,
        BlockIds::GRASS_BLOCK => BlockIds::FARMLAND,
        BlockIds::GRASS_PATH => BlockIds::FARMLAND,
        BlockIds::COARSE_DIRT => BlockIds::DIRT,
        BlockIds::DIRT_WITH_ROOTS => BlockIds::DIRT
    ];

    private const array PATHS = [
        BlockIds::GRASS_BLOCK => true,
        BlockIds::DIRT => true,
        BlockIds::PODZOL => true,
        BlockIds::MYCELIUM => true,
        BlockIds::COARSE_DIRT => true,
        BlockIds::DIRT_WITH_ROOTS => true
    ];

    private function __construct(){
    }

    public static function isTool(Item $item) : bool{
        return $item->getId() === ItemIds::HONEYCOMB || preg_match('/^minecraft:\w+_(axe|hoe|shovel)$/D', $item->getId()) === 1;
    }

    /**
     * Uses the held item on the block, one of the result constants.
     */
    public static function use(Player $player, int $x, int $y, int $z, int $face, BlockState $block, Item $held) : int{
        $world = $player->getWorld();
        $id = $held->getId();

        if($id === ItemIds::HONEYCOMB){
            return self::wax($world, $x, $y, $z, $block) ? self::USE_UP : self::NOTHING;
        }

        if(str_ends_with($id, "_axe")){
            return self::strip($world, $x, $y, $z, $block) || self::scrape($world, $x, $y, $z, $block) ? self::WEAR : self::NOTHING;
        }

        if(str_ends_with($id, "_hoe")){
            return self::till($world, $x, $y, $z, $face, $block) ? self::WEAR : self::NOTHING;
        }

        return self::flatten($world, $x, $y, $z, $face, $block) ? self::WEAR : self::NOTHING;
    }

    private static function strip(World $world, int $x, int $y, int $z, BlockState $block) : bool{
        if(preg_match(self::STRIPPABLE, $block->getName()) !== 1){
            return false;
        }

        $stripped = self::rename($block, "minecraft:stripped_" . substr($block->getName(), 10));

        if($stripped === null || !self::change($world, $x, $y, $z, $stripped)){
            return false;
        }

        self::sound($world, $x, $y, $z, LevelSoundEvent::ITEM_USE_ON, $stripped);
        return true;
    }

    /**
     * Takes the wax off, or one stage of weathering when there is no wax.
     */
    private static function scrape(World $world, int $x, int $y, int $z, BlockState $block) : bool{
        $name = $block->getName();

        if(str_starts_with($name, "minecraft:waxed_")){
            $plain = "minecraft:" . substr($name, 16);
            $unwaxed = self::rename($block, $plain === "minecraft:copper" ? BlockIds::COPPER_BLOCK : $plain);

            if($unwaxed === null || !self::changeCopper($world, $x, $y, $z, $block, $unwaxed)){
                return false;
            }

            self::effect($world, $x, $y, $z, LevelEvent::WAX_OFF, LevelSoundEvent::COPPER_WAX_OFF);
            return true;
        }

        $scraped = Oxidation::scraped($block);

        if($scraped === null || !self::changeCopper($world, $x, $y, $z, $block, $scraped)){
            return false;
        }

        self::effect($world, $x, $y, $z, LevelEvent::SCRAPE, LevelSoundEvent::SCRAPE);
        return true;
    }

    private static function wax(World $world, int $x, int $y, int $z, BlockState $block) : bool{
        $name = $block->getName();

        if(!Oxidation::isCopper($name)){
            return false;
        }

        $waxed = self::rename($block, $name === BlockIds::COPPER_BLOCK ? "minecraft:waxed_copper" : "minecraft:waxed_" . substr($name, 10));

        if($waxed === null || !self::changeCopper($world, $x, $y, $z, $block, $waxed)){
            return false;
        }

        self::effect($world, $x, $y, $z, LevelEvent::WAX_ON, LevelSoundEvent::COPPER_WAX_ON);
        return true;
    }

    private static function till(World $world, int $x, int $y, int $z, int $face, BlockState $block) : bool{
        $tilled = self::TILLED[$block->getName()] ?? null;

        if($tilled === null || $face === Facing::DOWN || !self::isAirAbove($world, $x, $y, $z)){
            return false;
        }

        $state = Server::getInstance()->getBlockStateRegistry()->getDefaultState($tilled);

        if(!self::change($world, $x, $y, $z, $state)){
            return false;
        }

        if($block->getName() === BlockIds::DIRT_WITH_ROOTS){
            $world->dropItem(new Vector3($x + 0.5, $y + 1.1, $z + 0.5), new Item(ItemIds::HANGING_ROOTS));
        }

        self::sound($world, $x, $y, $z, LevelSoundEvent::ITEM_USE_ON, $state);
        return true;
    }

    /**
     * Shovels make paths and put campfires out.
     */
    private static function flatten(World $world, int $x, int $y, int $z, int $face, BlockState $block) : bool{
        $name = $block->getName();

        if(($name === BlockIds::CAMPFIRE || $name === BlockIds::SOUL_CAMPFIRE) && $block->getProperty(BlockProperties::EXTINGUISHED) === false){
            if(!self::change($world, $x, $y, $z, $block->withProperty(BlockProperties::EXTINGUISHED, true))){
                return false;
            }

            self::sound($world, $x, $y, $z, LevelSoundEvent::EXTINGUISH_FIRE, null);
            return true;
        }

        if(!isset(self::PATHS[$name]) || $face === Facing::DOWN || !self::isAirAbove($world, $x, $y, $z)){
            return false;
        }

        $path = Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::GRASS_PATH);

        if(!self::change($world, $x, $y, $z, $path)){
            return false;
        }

        self::sound($world, $x, $y, $z, LevelSoundEvent::ITEM_USE_ON, $path);
        return true;
    }

    /**
     * Doors change both halves.
     */
    private static function changeCopper(World $world, int $x, int $y, int $z, BlockState $old, BlockState $new) : bool{
        if(!self::change($world, $x, $y, $z, $new)){
            return false;
        }

        if($old->hasProperty(BlockProperties::UPPER_BLOCK_BIT)){
            $otherY = $old->getProperty(BlockProperties::UPPER_BLOCK_BIT) === true ? $y - 1 : $y + 1;

            if($otherY >= Chunk::MIN_Y && $otherY <= Chunk::MAX_Y){
                $other = $world->getBlock($x, $otherY, $z);

                if($other->getName() === $old->getName()){
                    $world->setBlock($x, $otherY, $z, new BlockState($new->getName(), $other->getProperties()));
                }
            }
        }

        return true;
    }

    private static function change(World $world, int $x, int $y, int $z, BlockState $new) : bool{
        return BlockChangeEvent::set($world, $x, $y, $z, $new);
    }

    private static function rename(BlockState $block, string $name) : ?BlockState{
        $registry = Server::getInstance()->getBlockStateRegistry();

        if(!$registry->isRegistered($name)){
            return null;
        }

        $renamed = new BlockState($name, $block->getProperties());
        return $registry->isValid($renamed) ? $renamed : $registry->getDefaultState($name);
    }

    private static function isAirAbove(World $world, int $x, int $y, int $z) : bool{
        return $y >= Chunk::MAX_Y || $world->getBlock($x, $y + 1, $z)->getName() === BlockIds::AIR;
    }

    private static function sound(World $world, int $x, int $y, int $z, string $sound, ?BlockState $block) : void{
        $world->broadcastPacket($x, $z, LevelSoundEventPacket::create($sound, $x + 0.5, $y + 0.5, $z + 0.5, $block === null ? -1 : Server::getInstance()->getBlockTranslator()->getNetworkId($block)));
    }

    private static function effect(World $world, int $x, int $y, int $z, int $event, string $sound) : void{
        $world->broadcastPacket($x, $z, LevelEventPacket::create($event, $x + 0.5, $y + 0.5, $z + 0.5));
        self::sound($world, $x, $y, $z, $sound, null);
    }
}