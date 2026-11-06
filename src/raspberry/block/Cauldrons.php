<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\block\entity\BlockEntityFactory;
use raspberry\block\entity\Cauldron;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\nbt\tag\ListTag;
use raspberry\nbt\tag\Tag;

use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelEvent;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\player\Player;

use raspberry\world\World;

use function array_pop;
use function in_array;
use function intdiv;
use function max;
use function min;
use function preg_match;

class Cauldrons{

    public const MAX_LEVEL = 6;

    /** a bottle fills a third of the cauldron */
    private const BOTTLE_LEVELS = 2;

    /** each third of a potion tips this many arrows */
    private const ARROWS_PER_BOTTLE = 16;

    private const LIQUID_WATER = "water";
    private const LIQUID_LAVA = "lava";
    private const LIQUID_POWDER_SNOW = "powder_snow";

    /** dye => 0xRRGGBB */
    private const DYE_COLORS = [
        ItemIds::WHITE_DYE => 0xf0f0f0,
        ItemIds::ORANGE_DYE => 0xf9801d,
        ItemIds::MAGENTA_DYE => 0xc74ebd,
        ItemIds::LIGHT_BLUE_DYE => 0x3ab3da,
        ItemIds::YELLOW_DYE => 0xfed83d,
        ItemIds::LIME_DYE => 0x80c71f,
        ItemIds::PINK_DYE => 0xf38baa,
        ItemIds::GRAY_DYE => 0x474f52,
        ItemIds::LIGHT_GRAY_DYE => 0x9d9d97,
        ItemIds::CYAN_DYE => 0x169c9c,
        ItemIds::PURPLE_DYE => 0x8932b8,
        ItemIds::BLUE_DYE => 0x3c44aa,
        ItemIds::BROWN_DYE => 0x835432,
        ItemIds::GREEN_DYE => 0x5e7c16,
        ItemIds::RED_DYE => 0xb02e26,
        ItemIds::BLACK_DYE => 0x1d1d21
    ];

    /** items dyed water colors */
    private const DYEABLE = [ItemIds::LEATHER_HELMET, ItemIds::LEATHER_CHESTPLATE, ItemIds::LEATHER_LEGGINGS, ItemIds::LEATHER_BOOTS, ItemIds::LEATHER_HORSE_ARMOR, ItemIds::WOLF_ARMOR];

    private const POTIONS = [
        ItemIds::POTION => Cauldron::TYPE_NORMAL,
        ItemIds::SPLASH_POTION => Cauldron::TYPE_SPLASH,
        ItemIds::LINGERING_POTION => Cauldron::TYPE_LINGERING
    ];

    /** bucket => liquid it holds, and the events of filling and emptying */
    private const BUCKETS = [
        ItemIds::WATER_BUCKET => [self::LIQUID_WATER, LevelEvent::CAULDRON_FILL_WATER, LevelEvent::CAULDRON_TAKE_WATER, LevelSoundEvent::BUCKET_EMPTY_WATER, LevelSoundEvent::BUCKET_FILL_WATER],
        ItemIds::LAVA_BUCKET => [self::LIQUID_LAVA, LevelEvent::CAULDRON_FILL_LAVA, LevelEvent::CAULDRON_TAKE_LAVA, LevelSoundEvent::BUCKET_EMPTY_LAVA, LevelSoundEvent::BUCKET_FILL_LAVA],
        ItemIds::POWDER_SNOW_BUCKET => [self::LIQUID_POWDER_SNOW, LevelEvent::CAULDRON_FILL_POWDER_SNOW, LevelEvent::CAULDRON_TAKE_POWDER_SNOW, LevelSoundEvent::BUCKET_EMPTY_POWDER_SNOW, LevelSoundEvent::BUCKET_FILL_POWDER_SNOW]
    ];

    private function __construct(){
    }

    /**
     * The player used their held item on the cauldron at x y z. False when that does nothing.
     */
    public static function use(Player $player, int $x, int $y, int $z, BlockState $block) : bool{
        $world = $player->getWorld();
        $held = $player->getInventory()->getItemInHand();

        if($held->isNull()){
            return false;
        }

        $cauldron = $world->getBlockEntity($x, $y, $z);

        if(!$cauldron instanceof Cauldron){
            $cauldron = BlockEntityFactory::create(BlockIds::CAULDRON, $x, $y, $z);

            if(!$cauldron instanceof Cauldron){
                return false;
            }

            $world->addBlockEntity($cauldron);
        }

        $id = $held->getId();
        $level = (int) $block->getProperty(BlockProperties::FILL_LEVEL);
        $liquid = $level === 0 ? null : (string) $block->getProperty(BlockProperties::CAULDRON_LIQUID);
        $water = $liquid === self::LIQUID_WATER && !$cauldron->hasPotion();
        $potion = $liquid === self::LIQUID_WATER && $cauldron->hasPotion();

        if(isset(self::BUCKETS[$id])){
            [$bucketLiquid, $event, , $sound] = self::BUCKETS[$id];

            //water can wash a potion or dye out, other liquids only go in an empty cauldron
            if($liquid !== null && ($bucketLiquid !== self::LIQUID_WATER || $liquid !== self::LIQUID_WATER)){
                return false;
            }

            $cauldron->clear();
            self::update($world, $x, $y, $z, $block, $cauldron, $bucketLiquid, self::MAX_LEVEL);
            self::effects($world, $x, $y, $z, $event, $sound);
            self::exchange($player, $held, new Item(ItemIds::BUCKET));
            return true;
        }

        if($id === ItemIds::BUCKET){
            if($level < self::MAX_LEVEL || $potion){
                return false;
            }

            foreach(self::BUCKETS as $bucket => [$bucketLiquid, , $event, , $sound]){
                if($bucketLiquid === $liquid){
                    $cauldron->clear();
                    self::update($world, $x, $y, $z, $block, $cauldron, self::LIQUID_WATER, 0);
                    self::effects($world, $x, $y, $z, $event, $sound);
                    self::exchange($player, $held, new Item($bucket));
                    return true;
                }
            }

            return false;
        }

        if(isset(self::POTIONS[$id])){
            return self::pourPotion($player, $world, $x, $y, $z, $block, $cauldron, $held, $level, $liquid);
        }

        if($id === ItemIds::GLASS_BOTTLE){
            if(!$water && !$potion){
                return false;
            }

            $bottle = $potion ? new Item(self::potionItem($cauldron->getPotionType()), 1, $cauldron->getPotionId()) : new Item(ItemIds::POTION);
            self::update($world, $x, $y, $z, $block, $cauldron, self::LIQUID_WATER, max(0, $level - self::BOTTLE_LEVELS));
            self::effects($world, $x, $y, $z, $potion ? LevelEvent::CAULDRON_TAKE_POTION : LevelEvent::CAULDRON_TAKE_WATER, LevelSoundEvent::BOTTLE_FILL);
            self::exchange($player, $held, $bottle);
            return true;
        }

        if($id === ItemIds::ARROW && $held->getMeta() === 0 && $potion){
            return self::tipArrows($player, $world, $x, $y, $z, $block, $cauldron, $held, $level);
        }

        if(isset(self::DYE_COLORS[$id])){
            if(!$water){
                return false;
            }

            $color = self::DYE_COLORS[$id];
            $old = $cauldron->getColor();
            $cauldron->setColor($old === null ? $color : self::mix($old, $color));
            self::update($world, $x, $y, $z, $block, $cauldron, self::LIQUID_WATER, $level);
            $world->broadcastPacket($x, $z, LevelEventPacket::create(LevelEvent::CAULDRON_ADD_DYE, $x + 0.5, $y + 0.5, $z + 0.5, -16777216 | (int) $cauldron->getColor()));
            self::exchange($player, $held, null);
            return true;
        }

        return $water && self::wash($player, $world, $x, $y, $z, $block, $cauldron, $held, $level);
    }

    private static function pourPotion(Player $player, World $world, int $x, int $y, int $z, BlockState $block, Cauldron $cauldron, Item $held, int $level, ?string $liquid) : bool{
        $type = self::POTIONS[$held->getId()];
        $isWater = $held->getMeta() === 0 && $type === Cauldron::TYPE_NORMAL;

        if($liquid !== null && $liquid !== self::LIQUID_WATER){
            return false;
        }

        $same = $liquid === null || ($isWater ? !$cauldron->hasPotion() : $cauldron->getPotionId() === $held->getMeta() && $cauldron->getPotionType() === $type);

        if(!$same){
            $cauldron->clear();
            self::update($world, $x, $y, $z, $block, $cauldron, self::LIQUID_WATER, 0);
            self::effects($world, $x, $y, $z, LevelEvent::CAULDRON_EXPLODE, LevelSoundEvent::FIZZ);
            self::exchange($player, $held, new Item(ItemIds::GLASS_BOTTLE));
            return true;
        }

        if($level >= self::MAX_LEVEL){
            return false;
        }

        if($liquid === null && !$isWater){
            $cauldron->setPotion($held->getMeta(), $type);
        }

        self::update($world, $x, $y, $z, $block, $cauldron, self::LIQUID_WATER, min(self::MAX_LEVEL, $level + self::BOTTLE_LEVELS));
        self::effects($world, $x, $y, $z, $isWater ? LevelEvent::CAULDRON_FILL_WATER : LevelEvent::CAULDRON_FILL_POTION, LevelSoundEvent::BOTTLE_EMPTY);
        self::exchange($player, $held, new Item(ItemIds::GLASS_BOTTLE));

        return true;
    }

    /**
     * Arrows dipped in a potion become tipped arrows of it, 16 for each bottle of it there is.
     */
    private static function tipArrows(Player $player, World $world, int $x, int $y, int $z, BlockState $block, Cauldron $cauldron, Item $held, int $level) : bool{
        $bottles = intdiv($level + self::BOTTLE_LEVELS - 1, self::BOTTLE_LEVELS);
        $tipped = min($held->getCount(), $bottles * self::ARROWS_PER_BOTTLE);
        $used = intdiv($tipped + self::ARROWS_PER_BOTTLE - 1, self::ARROWS_PER_BOTTLE) * self::BOTTLE_LEVELS;
        $arrows = new Item(ItemIds::ARROW, $tipped, $cauldron->getPotionId() + 1);

        self::update($world, $x, $y, $z, $block, $cauldron, self::LIQUID_WATER, max(0, $level - $used));
        self::effects($world, $x, $y, $z, LevelEvent::CAULDRON_TAKE_POTION, null);

        if($player->getGameMode()->hasInstantBuild()){
            self::give($player, $arrows);
            return true;
        }

        $player->getInventory()->setItemInHand($held->getCount() > $tipped ? (clone $held)->setCount($held->getCount() - $tipped) : Item::air());
        self::give($player, $arrows);

        return true;
    }

    /**
     * Dyed water dyes leather, plain water washes the dye out of leather, the top pattern off a
     * banner and the color off a shulker box. Each takes a little of the water.
     */
    private static function wash(Player $player, World $world, int $x, int $y, int $z, BlockState $block, Cauldron $cauldron, Item $held, int $level) : bool{
        $id = $held->getId();
        $color = $cauldron->getColor();
        $result = (clone $held)->setCount(1);
        $event = LevelEvent::CAULDRON_CLEAN_ARMOR;
        $data = 0;

        if(in_array($id, self::DYEABLE, true)){
            if($color !== null){
                $result->getExtraTags()->setInt("customColor", -16777216 | $color);
                $event = LevelEvent::CAULDRON_DYE_ARMOR;
                $data = -16777216 | $color;
            }elseif($result->getExtraTags()->hasTag("customColor")){
                $result->getExtraTags()->removeTag("customColor");
            }else{
                return false;
            }
        }elseif($id === ItemIds::BANNER){
            $patterns = $result->getExtraTags()->getListTag("Patterns")?->getValue() ?? [];

            if($patterns === [] || ($result->getExtraTags()->getInt("Type", 0) ?? 0) !== 0){
                return false;
            }

            array_pop($patterns);

            if($patterns === []){
                $result->getExtraTags()->removeTag("Patterns");
            }else{
                $result->getExtraTags()->setTag("Patterns", new ListTag($patterns, Tag::TAG_COMPOUND));
            }

            $event = LevelEvent::CAULDRON_CLEAN_BANNER;
        }elseif(preg_match('/^minecraft:\w+_shulker_box$/D', $id) === 1 && $id !== ItemIds::UNDYED_SHULKER_BOX){
            $result = $result->withId(ItemIds::UNDYED_SHULKER_BOX);
        }else{
            return false;
        }

        self::update($world, $x, $y, $z, $block, $cauldron, self::LIQUID_WATER, $level - 1);
        $world->broadcastPacket($x, $z, LevelEventPacket::create($event, $x + 0.5, $y + 0.5, $z + 0.5, $data));

        if($held->getCount() > 1){
            $player->getInventory()->setItemInHand((clone $held)->setCount($held->getCount() - 1));
            self::give($player, $result);
        }else{
            $player->getInventory()->setItemInHand($result);
        }

        return true;
    }

    /**
     * Sets the cauldron's liquid and level. The block entity goes first so the client draws the
     * new block with its color.
     */
    private static function update(World $world, int $x, int $y, int $z, BlockState $block, Cauldron $cauldron, string $liquid, int $level) : void{
        if($level <= 0){
            $cauldron->clear();
            $liquid = self::LIQUID_WATER;
        }

        $world->sendBlockEntity($cauldron);
        $world->setBlock($x, $y, $z, $block->withProperty(BlockProperties::CAULDRON_LIQUID, $liquid)->withProperty(BlockProperties::FILL_LEVEL, max(0, $level)));
    }

    private static function effects(World $world, int $x, int $y, int $z, int $event, ?string $sound) : void{
        $world->broadcastPacket($x, $z, LevelEventPacket::create($event, $x + 0.5, $y + 0.5, $z + 0.5));

        if($sound !== null){
            $world->broadcastPacket($x, $z, LevelSoundEventPacket::create($sound, $x + 0.5, $y + 0.5, $z + 0.5));
        }
    }

    /**
     * Uses one of the held item up for $result. Creative players keep what they hold, and only
     * get $result when they do not have one yet.
     */
    private static function exchange(Player $player, Item $held, ?Item $result) : void{
        $inventory = $player->getInventory();

        if($player->getGameMode()->hasInstantBuild()){
            if($result !== null && $inventory->countMatching(fn(Item $item) : bool => $item->canStackWith($result)) === 0){
                self::give($player, $result);
            }

            return;
        }

        if($held->getCount() > 1){
            $inventory->setItemInHand((clone $held)->setCount($held->getCount() - 1));

            if($result !== null){
                self::give($player, $result);
            }
        }else{
            $inventory->setItemInHand($result ?? Item::air());
        }
    }

    private static function give(Player $player, Item $item) : void{
        foreach($player->getInventory()->addItem($item) as $left){
            $player->dropItem($left);
        }
    }

    private static function potionItem(int $type) : string{
        return match($type){
            Cauldron::TYPE_SPLASH => ItemIds::SPLASH_POTION,
            Cauldron::TYPE_LINGERING => ItemIds::LINGERING_POTION,
            default => ItemIds::POTION
        };
    }

    /**
     * Dyes mixed in water average out.
     */
    private static function mix(int $a, int $b) : int{
        return intdiv(($a >> 16 & 0xff) + ($b >> 16 & 0xff), 2) << 16 | intdiv(($a >> 8 & 0xff) + ($b >> 8 & 0xff), 2) << 8 | intdiv(($a & 0xff) + ($b & 0xff), 2);
    }
}