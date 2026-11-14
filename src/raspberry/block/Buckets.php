<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\Server;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\math\Facing;
use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\player\Player;

use raspberry\world\World;
use raspberry\world\liquid\LiquidEngine;

use function str_starts_with;

class Buckets{

    private const FILLED = [
        ItemIds::WATER_BUCKET => [BlockIds::WATER, LevelSoundEvent::BUCKET_EMPTY_WATER],
        ItemIds::LAVA_BUCKET => [BlockIds::LAVA, LevelSoundEvent::BUCKET_EMPTY_LAVA],
        ItemIds::COD_BUCKET => [BlockIds::WATER, LevelSoundEvent::BUCKET_EMPTY_FISH],
        ItemIds::SALMON_BUCKET => [BlockIds::WATER, LevelSoundEvent::BUCKET_EMPTY_FISH],
        ItemIds::PUFFERFISH_BUCKET => [BlockIds::WATER, LevelSoundEvent::BUCKET_EMPTY_FISH],
        ItemIds::TROPICAL_FISH_BUCKET => [BlockIds::WATER, LevelSoundEvent::BUCKET_EMPTY_FISH],
        ItemIds::AXOLOTL_BUCKET => [BlockIds::WATER, LevelSoundEvent::BUCKET_EMPTY_FISH],
        ItemIds::TADPOLE_BUCKET => [BlockIds::WATER, LevelSoundEvent::BUCKET_EMPTY_FISH]
    ];

    private function __construct(){
    }

    public static function isBucket(string $id) : bool{
        return $id === ItemIds::BUCKET || isset(self::FILLED[$id]);
    }

    public static function use(Player $player, int $x, int $y, int $z, int $face, BlockState $clicked, Item $held) : bool{
        $world = $player->getWorld();

        if($held->getId() === ItemIds::BUCKET){
            [$filled, $sound] = match(true){
                self::isSource($clicked, BlockIds::WATER, BlockIds::FLOWING_WATER) => [ItemIds::WATER_BUCKET, LevelSoundEvent::BUCKET_FILL_WATER],
                self::isSource($clicked, BlockIds::LAVA, BlockIds::FLOWING_LAVA) => [ItemIds::LAVA_BUCKET, LevelSoundEvent::BUCKET_FILL_LAVA],
                $clicked->getName() === BlockIds::POWDER_SNOW => [ItemIds::POWDER_SNOW_BUCKET, LevelSoundEvent::BUCKET_FILL_POWDER_SNOW],
                default => [null, null]
            };

            if($filled === null){
                return false;
            }

            $world->setBlock($x, $y, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::AIR));
            self::playSound($world, $sound, $x, $y, $z);
            self::exchange($player, $held, new Item($filled));

            return true;
        }

        [$liquid, $sound] = self::FILLED[$held->getId()];

        if(!LiquidEngine::canReplace($clicked)){
            [$dx, $dy, $dz] = Facing::offset($face);
            [$x, $y, $z] = [$x + $dx, $y + $dy, $z + $dz];
            $clicked = $world->getBlock($x, $y, $z);

            if(!LiquidEngine::canReplace($clicked)){
                return false;
            }
        }

        if(!str_starts_with($clicked->getName(), "minecraft:flowing_") && $clicked->getName() !== BlockIds::AIR && $clicked->getName() !== BlockIds::WATER && $clicked->getName() !== BlockIds::LAVA){
            foreach(BlockBreakInfo::get($clicked->getName())->getDrops($clicked, Item::air()) as $item){
                $world->dropItem(new Vector3($x + 0.5, $y + 0.5, $z + 0.5), $item);
            }
        }

        $world->setBlock($x, $y, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState($liquid));
        self::playSound($world, $sound, $x, $y, $z);
        self::exchange($player, $held, new Item(ItemIds::BUCKET));

        return true;
    }

    public static function isSource(BlockState $block, string $still, string $flowing) : bool{
        $name = $block->getName();
        return ($name === $still || $name === $flowing) && $block->getProperty(BlockProperties::LIQUID_DEPTH) === 0;
    }

    private static function playSound(World $world, string $sound, int $x, int $y, int $z) : void{
        $world->broadcastPacket($x, $z, LevelSoundEventPacket::create($sound, $x + 0.5, $y + 0.5, $z + 0.5));
    }

    private static function exchange(Player $player, Item $held, Item $result) : void{
        $inventory = $player->getInventory();

        if($player->getGameMode()->hasInstantBuild()){
            if($result->getId() !== ItemIds::BUCKET && $inventory->countMatching(fn(Item $item) : bool => $item->canStackWith($result)) === 0){
                self::give($player, $result);
            }

            return;
        }

        if($held->getCount() > 1){
            $inventory->setItemInHand((clone $held)->setCount($held->getCount() - 1));
            self::give($player, $result);
        }else{
            $inventory->setItemInHand($result);
        }
    }

    private static function give(Player $player, Item $item) : void{
        foreach($player->getInventory()->addItem($item) as $left){
            $player->dropItem($left);
        }
    }
}