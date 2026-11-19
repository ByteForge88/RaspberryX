<?php

declare(strict_types=1);

namespace raspberry\block\growth;

use raspberry\block\BlockState;
use raspberry\block\BlockProperties;

use raspberry\world\World;

use raspberry\event\block\BlockGrowEvent;

use function in_array;
use function mt_rand;

class Saplings implements RandomTickBehavior{

    public const array NAMES = [
        "minecraft:oak_sapling",
        "minecraft:spruce_sapling",
        "minecraft:birch_sapling",
        "minecraft:jungle_sapling",
        "minecraft:acacia_sapling",
        "minecraft:dark_oak_sapling",
        "minecraft:cherry_sapling",
        "minecraft:pale_oak_sapling"
    ];

    private const string PROPAGULE = "minecraft:mangrove_propagule";

    private const int PROPAGULE_GROWN = 4;

    public function getBlockNames() : array{
        return [...self::NAMES, self::PROPAGULE];
    }

    public function ticks(BlockState $state) : bool{
        return $state->getName() !== self::PROPAGULE || $state->getProperty(BlockProperties::HANGING) === false || $state->getProperty(BlockProperties::PROPAGULE_STAGE) < self::PROPAGULE_GROWN;
    }

    public function onRandomTick(World $world, int $x, int $y, int $z, BlockState $state) : void{
        if($state->getName() === self::PROPAGULE){
            if($state->getProperty(BlockProperties::HANGING) === true){
                BlockGrowEvent::set($world, $x, $y, $z, $state->withProperty(BlockProperties::PROPAGULE_STAGE, $state->getProperty(BlockProperties::PROPAGULE_STAGE) + 1));
            }elseif(mt_rand(0, 6) === 0){
                Trees::grow($world, $x, $y, $z, $state);
            }

            return;
        }

        if($world->getLight($x, $y + 1, $z) >= 9 && mt_rand(0, 6) === 0){
            self::advance($world, $x, $y, $z, $state);
        }
    }

    /**
     * One step of growing, also what bone meal does.
     */
    public static function advance(World $world, int $x, int $y, int $z, BlockState $sapling) : void{
        if($sapling->getName() === self::PROPAGULE){
            $stage = $sapling->getProperty(BlockProperties::PROPAGULE_STAGE);

            if($sapling->getProperty(BlockProperties::HANGING) === false){
                Trees::grow($world, $x, $y, $z, $sapling);
            }elseif($stage < self::PROPAGULE_GROWN){
                BlockGrowEvent::set($world, $x, $y, $z, $sapling->withProperty(BlockProperties::PROPAGULE_STAGE, $stage + 1));
            }
        }elseif($sapling->getProperty(BlockProperties::AGE_BIT) === false){
            BlockGrowEvent::set($world, $x, $y, $z, $sapling->withProperty(BlockProperties::AGE_BIT, true));
        }else{
            Trees::grow($world, $x, $y, $z, $sapling);
        }
    }

    public static function isSapling(string $name) : bool{
        return $name === self::PROPAGULE || in_array($name, self::NAMES, true);
    }
}