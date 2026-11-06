<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\Server;

use raspberry\block\entity\Dispenser;
use raspberry\block\entity\ShulkerBox;
use raspberry\block\entity\BlockEntityFactory;
use raspberry\block\redstone\RedstoneUpdater;

use raspberry\entity\object\ThrownPotion;

use raspberry\inventory\ArmorInventory;

use raspberry\item\Item;
use raspberry\item\ItemIds;
use raspberry\item\enchantment\Enchantment;

use raspberry\math\Facing;
use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelEvent;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\player\GameMode;
use raspberry\player\Player;

use raspberry\world\Chunk;
use raspberry\world\World;

use function mt_getrandmax;
use function mt_rand;
use function preg_match;

class Dispensing{

    /** blocks a liquid or a shulker box can be put in */
    private const REPLACEABLE = '/^minecraft:(air|flowing_water|flowing_lava|fire|soul_fire|short_grass|short_dry_grass|fern|deadbush|bush|vine|glow_lichen|seagrass|snow_layer|structure_void|crimson_roots|warped_roots|nether_sprouts|leaf_litter)$/D';

    private const FILLED_BUCKETS = [
        ItemIds::WATER_BUCKET => [BlockIds::WATER, LevelSoundEvent::BUCKET_EMPTY_WATER],
        ItemIds::LAVA_BUCKET => [BlockIds::LAVA, LevelSoundEvent::BUCKET_EMPTY_LAVA],
        ItemIds::POWDER_SNOW_BUCKET => [BlockIds::POWDER_SNOW, LevelSoundEvent::BUCKET_EMPTY_POWDER_SNOW]
    ];

    private function __construct(){
    }

    /**
     * Fires one of $item out of a dispenser. Returns what is left of the stack, null when
     * nothing happened.
     */
    public static function dispense(World $world, Dispenser $dispenser, Item $item, int $facing) : ?Item{
        [$dx, $dy, $dz] = Facing::offset($facing);
        [$x, $y, $z] = [$dispenser->getX() + $dx, $dispenser->getY() + $dy, $dispenser->getZ() + $dz];
        $id = $item->getId();

        if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y || !$world->isChunkLoaded($x >> 4, $z >> 4)){
            return null;
        }

        $result = match(true){
            $id === ItemIds::CARVED_PUMPKIN, preg_match('/^minecraft:(\w+_head|\w+_skull)$/D', $id) === 1 => self::equip($world, $item, $x, $y, $z),
            ArmorInventory::getSlotFor($id) !== null => self::equip($world, $item, $x, $y, $z) ?? self::dropOne($world, $dispenser, $item, $facing),
            isset(self::FILLED_BUCKETS[$id]) => self::emptyBucket($world, $item, $x, $y, $z) ?? self::dropOne($world, $dispenser, $item, $facing),
            $id === ItemIds::BUCKET => self::fillBucket($world, $dispenser, $item, $x, $y, $z) ?? self::dropOne($world, $dispenser, $item, $facing),
            $id === ItemIds::BONE_MEAL => BoneMeal::grow($world, $x, $y, $z) ? $item->setCount($item->getCount() - 1) : null,
            preg_match('/^minecraft:\w+_shulker_box$/D', $id) === 1 => self::placeShulkerBox($world, $item, $x, $y, $z, $facing),
            $id === ItemIds::FLINT_AND_STEEL => self::ignite($world, $item, $x, $y, $z),
            $id === ItemIds::SPLASH_POTION, $id === ItemIds::LINGERING_POTION => self::shootPotion($world, $dispenser, $item, $facing),
            default => self::dropOne($world, $dispenser, $item, $facing)
        };

        if($result !== null){
            self::playClickSound($world, $dispenser->getX(), $dispenser->getY(), $dispenser->getZ());
            $world->broadcastPacket($x, $z, LevelEventPacket::create(LevelEvent::PARTICLES_SHOOT, $dispenser->getX() + 0.5, $dispenser->getY() + 0.5, $dispenser->getZ() + 0.5, ($dx + 1) + ($dz + 1) * 3));
        }

        return $result;
    }

    /**
     * Throws $item out of the front of the block at x y z (a dispenser, dropper or crafter).
     */
    public static function drop(World $world, int $x, int $y, int $z, Item $item, int $facing) : void{
        [$dx, $dy, $dz] = Facing::offset($facing);
        $position = new Vector3(
            $x + 0.5 + 0.7 * $dx,
            $y + 0.5 + 0.7 * $dy - ($dy === 0 ? 0.15625 : 0.125),
            $z + 0.5 + 0.7 * $dz
        );
        $speed = self::random() * 0.1 + 0.2;
        $spread = 0.0172275 * 6;

        $world->dropItem($position, $item, new Vector3(
            $dx * $speed + self::triangle($spread),
            0.2 + self::triangle($spread),
            $dz * $speed + self::triangle($spread)
        ));
    }

    public static function playClickSound(World $world, int $x, int $y, int $z) : void{
        $world->broadcastPacket($x, $z, LevelEventPacket::create(LevelEvent::SOUND_CLICK, $x + 0.5, $y + 0.5, $z + 0.5, 1000));
    }

    public static function playFailSound(World $world, int $x, int $y, int $z) : void{
        $world->broadcastPacket($x, $z, LevelEventPacket::create(LevelEvent::SOUND_CLICK_FAIL, $x + 0.5, $y + 0.5, $z + 0.5, 1200));
    }

    /**
     * Potions fly out much faster than a player throws them.
     */
    private static function shootPotion(World $world, Dispenser $dispenser, Item $item, int $facing) : Item{
        [$dx, $dy, $dz] = Facing::offset($facing);
        $speed = 1.375;
        $spread = 0.0075 * 3.0;
        $position = new Vector3($dispenser->getX() + 0.5 + 0.7 * $dx, $dispenser->getY() + 0.5 + 0.7 * $dy + ($dy === 0 ? 0.1 : 0.0), $dispenser->getZ() + 0.5 + 0.7 * $dz);
        $motion = new Vector3(($dx + self::triangle($spread)) * $speed, ($dy + ($dy === 0 ? 0.1 : 0.0) + self::triangle($spread)) * $speed, ($dz + self::triangle($spread)) * $speed);

        new ThrownPotion($world, $position, $item, $motion, null);

        return $item->setCount($item->getCount() - 1);
    }

    private static function dropOne(World $world, Dispenser $dispenser, Item $item, int $facing) : Item{
        self::drop($world, $dispenser->getX(), $dispenser->getY(), $dispenser->getZ(), (clone $item)->setCount(1), $facing);

        return $item->setCount($item->getCount() - 1);
    }

    /**
     * Puts armor, a carved pumpkin or a head on a player standing in front, when that slot is free.
     */
    private static function equip(World $world, Item $item, int $x, int $y, int $z) : ?Item{
        $slot = ArmorInventory::getSlotFor($item->getId());

        if($slot === null){
            return null;
        }

        foreach($world->getPlayers() as $player){
            if(!self::isInBlock($player, $x, $y, $z) || !$player->getArmorInventory()->getItem($slot)->isNull()){
                continue;
            }

            $player->getArmorInventory()->setItem($slot, (clone $item)->setCount(1));
            $player->broadcastArmor();

            $position = $player->getPosition();
            $world->broadcastPacket($x, $z, LevelSoundEventPacket::create(LevelSoundEvent::ARMOR_EQUIP_GENERIC, $position->x, $position->y, $position->z));

            return $item->setCount($item->getCount() - 1);
        }

        return null;
    }

    private static function isInBlock(Player $player, int $x, int $y, int $z) : bool{
        if($player->isDead() || $player->getGameMode() === GameMode::SPECTATOR){
            return false;
        }

        $position = $player->getPosition();
        $halfWidth = Player::WIDTH / 2;

        return $position->x + $halfWidth > $x && $position->x - $halfWidth < $x + 1 &&
            $position->y + Player::HEIGHT > $y && $position->y < $y + 1 &&
            $position->z + $halfWidth > $z && $position->z - $halfWidth < $z + 1;
    }

    /**
     * Pours a water, lava or powder snow bucket in front, leaving an empty bucket.
     */
    private static function emptyBucket(World $world, Item $item, int $x, int $y, int $z) : ?Item{
        if(preg_match(self::REPLACEABLE, $world->getBlock($x, $y, $z)->getName()) !== 1){
            return null;
        }

        [$block, $sound] = self::FILLED_BUCKETS[$item->getId()];
        $world->setBlock($x, $y, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState($block));
        $world->broadcastPacket($x, $z, LevelSoundEventPacket::create($sound, $x + 0.5, $y + 0.5, $z + 0.5));

        return new Item(ItemIds::BUCKET);
    }

    /**
     * Scoops up a water or lava source, or powder snow, in front.
     */
    private static function fillBucket(World $world, Dispenser $dispenser, Item $item, int $x, int $y, int $z) : ?Item{
        $block = $world->getBlock($x, $y, $z);

        [$filled, $sound] = match(true){
            $block->getName() === BlockIds::WATER && (int) $block->getProperty(BlockProperties::LIQUID_DEPTH) === 0 => [ItemIds::WATER_BUCKET, LevelSoundEvent::BUCKET_FILL_WATER],
            $block->getName() === BlockIds::LAVA && (int) $block->getProperty(BlockProperties::LIQUID_DEPTH) === 0 => [ItemIds::LAVA_BUCKET, LevelSoundEvent::BUCKET_FILL_LAVA],
            $block->getName() === BlockIds::POWDER_SNOW => [ItemIds::POWDER_SNOW_BUCKET, LevelSoundEvent::BUCKET_FILL_POWDER_SNOW],
            default => [null, null]
        };

        if($filled === null){
            return null;
        }

        $world->setBlock($x, $y, $z, RedstoneUpdater::air());
        $world->broadcastPacket($x, $z, LevelSoundEventPacket::create($sound, $x + 0.5, $y + 0.5, $z + 0.5));

        if($item->getCount() === 1){
            return new Item($filled);
        }

        //the full bucket goes back in, or out the front when there is no room
        foreach($dispenser->getInventory()->addItem(new Item($filled)) as $left){
            $world->dropItem(new Vector3($x + 0.5, $y + 0.5, $z + 0.5), $left);
        }

        return $item->setCount($item->getCount() - 1);
    }

    private static function placeShulkerBox(World $world, Item $item, int $x, int $y, int $z, int $facing) : ?Item{
        if(preg_match(self::REPLACEABLE, $world->getBlock($x, $y, $z)->getName()) !== 1){
            return null;
        }

        $state = Server::getInstance()->getBlockStateRegistry()->getDefaultState($item->getId());
        $world->setBlock($x, $y, $z, $state);

        $entity = BlockEntityFactory::create($state->getName(), $x, $y, $z);

        if($entity instanceof ShulkerBox){
            //the lid opens the way the box was fired, or up when it lands on nothing
            $below = $y - 1 >= Chunk::MIN_Y ? $world->getBlock($x, $y - 1, $z) : RedstoneUpdater::air();
            $entity->loadFromItem($item, $below->getName() === BlockIds::AIR ? $facing : Facing::UP);
            $world->addBlockEntity($entity);
        }

        $world->broadcastPacket($x, $z, LevelSoundEventPacket::create(LevelSoundEvent::PLACE, $x + 0.5, $y + 0.5, $z + 0.5, Server::getInstance()->getBlockTranslator()->getNetworkId($state)));

        return $item->setCount($item->getCount() - 1);
    }

    /**
     * Lights a candle, candle cake or campfire in front. Fire comes with fire spreading.
     */
    private static function ignite(World $world, Item $item, int $x, int $y, int $z) : ?Item{
        $block = $world->getBlock($x, $y, $z);
        $name = $block->getName();

        $lit = match(true){
            preg_match('/^minecraft:(\w+_)?candle(_cake)?$/D', $name) === 1 && $block->getProperty(BlockProperties::LIT) !== true => $block->withProperty(BlockProperties::LIT, true),
            ($name === BlockIds::CAMPFIRE || $name === BlockIds::SOUL_CAMPFIRE) && $block->getProperty(BlockProperties::EXTINGUISHED) === true => $block->withProperty(BlockProperties::EXTINGUISHED, false),
            default => null
        };

        if($lit === null){
            return null;
        }

        $world->setBlock($x, $y, $z, $lit);
        $world->broadcastPacket($x, $z, LevelSoundEventPacket::create(LevelSoundEvent::IGNITE, $x + 0.5, $y + 0.5, $z + 0.5));

        $unbreaking = $item->getEnchantmentLevel(Enchantment::UNBREAKING);

        if($unbreaking > 0 && mt_rand(0, $unbreaking) !== 0){
            return $item;
        }

        return $item->getDamage() + 1 >= $item->getMaxDurability() ? Item::air() : $item->setDamage($item->getDamage() + 1);
    }

    private static function random() : float{
        return mt_rand() / mt_getrandmax();
    }

    private static function triangle(float $spread) : float{
        return $spread * (self::random() - self::random());
    }
}