<?php

declare(strict_types=1);

namespace raspberry\block\growth;

use raspberry\Server;

use raspberry\block\BlockProperties;
use raspberry\block\BlockState;

use raspberry\world\World;

use raspberry\event\block\BlockFormEvent;

use function abs;
use function mt_getrandmax;
use function mt_rand;

class Oxidation implements RandomTickBehavior{

    private const float CHANCE = 0.05688889;

    private const float UNAFFECTED_MODIFIER = 0.75;

    private const int REACH = 4;

    private const array BLOCKS = [
        "copper_block",
        "chiseled_copper",
        "cut_copper",
        "cut_copper_slab",
        "cut_copper_stairs",
        "double_cut_copper_slab",
        "copper_door",
        "copper_trapdoor",
        "copper_grate",
        "copper_bulb",
        "copper_bars",
        "copper_chain",
        "copper_lantern",
        "copper_chest",
        "copper_golem_statue",
        "lightning_rod"
    ];

    private const array STAGES = ["", "exposed_", "weathered_", "oxidized_"];

    /** @var array<string, array{string, int}>|null */
    private static ?array $stages = null;

    /** @var array<string, list<string>> */
    private static array $chains = [];

    public function getBlockNames() : array{
        $names = [];

        foreach(self::stages() as $name => [, $stage]){
            if($stage < 3){
                $names[] = $name;
            }
        }

        return $names;
    }

    public function ticks(BlockState $state) : bool{
        return !$state->hasProperty(BlockProperties::UPPER_BLOCK_BIT) || $state->getProperty(BlockProperties::UPPER_BLOCK_BIT) === false;
    }

    public function onRandomTick(World $world, int $x, int $y, int $z, BlockState $state) : void{
        if(mt_rand() / mt_getrandmax() >= self::CHANCE){
            return;
        }

        [$base, $stage] = self::stages()[$state->getName()];
        $same = 0;
        $further = 0;

        for($dx = -self::REACH; $dx <= self::REACH; ++$dx){
            for($dy = -self::REACH + abs($dx); $dy <= self::REACH - abs($dx); ++$dy){
                $reach = self::REACH - abs($dx) - abs($dy);

                for($dz = -$reach; $dz <= $reach; ++$dz){
                    if($dx === 0 && $dy === 0 && $dz === 0){
                        continue;
                    }

                    $other = self::stages()[Nearby::name($world, $x + $dx, $y + $dy, $z + $dz)] ?? null;

                    if($other === null){
                        continue;
                    }

                    if($other[1] < $stage){
                        return;
                    }

                    $other[1] > $stage ? ++$further : ++$same;
                }
            }
        }

        $chance = ($further + 1) / ($further + $same + 1);
        $chance *= $chance * ($stage === 0 ? self::UNAFFECTED_MODIFIER : 1.0);

        if(mt_rand() / mt_getrandmax() >= $chance){
            return;
        }

        $next = self::$chains[$base][$stage + 1];
        BlockFormEvent::set($world, $x, $y, $z, self::rename($state, $next));

        if($state->hasProperty(BlockProperties::UPPER_BLOCK_BIT)){
            $upper = Nearby::block($world, $x, $y + 1, $z);

            if($upper !== null && $upper->getName() === $state->getName()){
                BlockFormEvent::set($world, $x, $y + 1, $z, self::rename($upper, $next));
            }
        }
    }

    /**
     * The copper with its weathering stripped off, what lightning does to it. Null when it is
     * not weathered copper.
     */
    public static function unweathered(BlockState $state) : ?BlockState{
        $stage = self::stages()[$state->getName()] ?? null;
        return $stage === null || $stage[1] === 0 ? null : self::rename($state, $stage[0]);
    }

    /**
     * Whether it is unwaxed copper that weathers, at any stage.
     */
    public static function isCopper(string $name) : bool{
        return isset(self::stages()[$name]);
    }

    /**
     * The copper one weathering stage back, what scraping it with an axe does. Null when it has
     * not weathered.
     */
    public static function scraped(BlockState $state) : ?BlockState{
        $stage = self::stages()[$state->getName()] ?? null;
        return $stage === null || $stage[1] === 0 ? null : self::rename($state, self::$chains[$stage[0]][$stage[1] - 1]);
    }

    private static function rename(BlockState $state, string $name) : BlockState{
        $renamed = new BlockState($name, $state->getProperties());
        $registry = Server::getInstance()->getBlockStateRegistry();
        return $registry->isValid($renamed) ? $renamed : $registry->getDefaultState($name);
    }

    /**
     * @return array<string, array{string, int}>
     */
    private static function stages() : array{
        if(self::$stages !== null){
            return self::$stages;
        }

        $registry = Server::getInstance()->getBlockStateRegistry();
        self::$stages = [];

        foreach(self::BLOCKS as $block){
            $chain = [];

            foreach(self::STAGES as $stage => $prefix){
                $name = "minecraft:" . ($stage > 0 && $block === "copper_block" ? $prefix . "copper" : $prefix . $block);

                if(!$registry->isRegistered($name)){
                    continue 2;
                }

                $chain[] = $name;
            }

            self::$chains[$chain[0]] = $chain;

            foreach($chain as $stage => $name){
                self::$stages[$name] = [$chain[0], $stage];
            }
        }

        return self::$stages;
    }
}