<?php

declare(strict_types=1);

namespace raspberry\block\growth;

use raspberry\Server;

use raspberry\block\BlockState;

use raspberry\world\World;

use function array_keys;
use function array_rand;
use function mt_rand;

class Others implements RandomTickBehavior{

    private const BUDDING_AMETHYST = "minecraft:budding_amethyst";

    private const BUDS = [
        "minecraft:small_amethyst_bud" => "minecraft:medium_amethyst_bud",
        "minecraft:medium_amethyst_bud" => "minecraft:large_amethyst_bud",
        "minecraft:large_amethyst_bud" => "minecraft:amethyst_cluster"
    ];

    private const FACES = ["down" => [0, -1, 0], "up" => [0, 1, 0], "north" => [0, 0, -1], "south" => [0, 0, 1], "west" => [-1, 0, 0], "east" => [1, 0, 0]];

    private const UNLIT = [
        "minecraft:lit_redstone_ore" => "minecraft:redstone_ore",
        "minecraft:lit_deepslate_redstone_ore" => "minecraft:deepslate_redstone_ore"
    ];

    private const OPEN_EYEBLOSSOM = "minecraft:open_eyeblossom";
    private const CLOSED_EYEBLOSSOM = "minecraft:closed_eyeblossom";

    public function getBlockNames() : array{
        return [self::BUDDING_AMETHYST, self::OPEN_EYEBLOSSOM, self::CLOSED_EYEBLOSSOM, ...array_keys(self::UNLIT)];
    }

    public function ticks(BlockState $state) : bool{
        return true;
    }

    public function onRandomTick(World $world, int $x, int $y, int $z, BlockState $state) : void{
        $registry = Server::getInstance()->getBlockStateRegistry();
        $name = $state->getName();

        if(isset(self::UNLIT[$name])){
            $world->setBlock($x, $y, $z, $registry->getDefaultState(self::UNLIT[$name]));
        }elseif($name === self::OPEN_EYEBLOSSOM && $world->isDaytime()){
            $world->setBlock($x, $y, $z, $registry->getDefaultState(self::CLOSED_EYEBLOSSOM));
        }elseif($name === self::CLOSED_EYEBLOSSOM && !$world->isDaytime()){
            $world->setBlock($x, $y, $z, $registry->getDefaultState(self::OPEN_EYEBLOSSOM));
        }elseif($name === self::BUDDING_AMETHYST && mt_rand(0, 4) === 0){
            $this->growBud($world, $x, $y, $z);
        }
    }

    private function growBud(World $world, int $x, int $y, int $z) : void{
        $face = array_rand(self::FACES);
        [$dx, $dy, $dz] = self::FACES[$face];
        $target = Nearby::block($world, $x + $dx, $y + $dy, $z + $dz);

        if($target === null){
            return;
        }

        $name = $target->getName();

        $next = match(true){
            $name === "minecraft:air" || Nearby::isWaterSource($target) => "minecraft:small_amethyst_bud",
            isset(self::BUDS[$name]) && $target->getProperty("minecraft:block_face") === $face => self::BUDS[$name],
            default => null
        };

        if($next !== null){
            $world->setBlock($x + $dx, $y + $dy, $z + $dz, Server::getInstance()->getBlockStateRegistry()->get($next, ["minecraft:block_face" => $face]));
        }
    }
}