<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\Server;

use raspberry\block\growth\Nearby;
use raspberry\block\growth\RandomTickBehavior;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\math\Facing;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\player\Player;

use raspberry\world\GameRules;
use raspberry\world\World;

use function in_array;
use function intdiv;
use function max;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function preg_match;

class Fire implements RandomTickBehavior{

    public const MAX_AGE = 15;

    private const INFINIBURN = [BlockIds::NETHERRACK, BlockIds::MAGMA];

    private const SOUL_GROUND = ["minecraft:soul_sand", "minecraft:soul_soil"];

    private const WOODS = '(oak|spruce|birch|jungle|acacia|dark_oak|mangrove|cherry|pale_oak|bamboo)';

    private const FLAMMABLE = [
        '/^minecraft:(' . self::WOODS . '_(planks|slab|double_slab|stairs|fence|fence_gate)|fence_gate|bamboo_mosaic(_slab|_double_slab|_stairs)?|mangrove_roots|composter|beehive)$/D' => [5, 20],
        '/^minecraft:((stripped_)?' . self::WOODS . '_(log|wood)|(stripped_)?bamboo_block|coal_block)$/D' => [5, 5],
        '/^minecraft:\w+_leaves(_flowered)?$/D' => [30, 60],
        '/^minecraft:(bookshelf|chiseled_bookshelf|lectern|bee_nest)$/D' => [30, 20],
        '/^minecraft:tnt$/D' => [15, 100],
        '/^minecraft:(short_grass|tall_grass|fern|large_fern|deadbush|dead_bush|short_dry_grass|tall_dry_grass|bush|firefly_bush|leaf_litter|sweet_berry_bush|spore_blossom|small_dripleaf_block|pink_petals|wildflowers|cactus_flower)$/D' => [60, 100],
        '/^minecraft:(dandelion|poppy|blue_orchid|allium|azure_bluet|\w+_tulip|oxeye_daisy|cornflower|lily_of_the_valley|wither_rose|torchflower|sunflower|lilac|rose_bush|peony|pitcher_plant|open_eyeblossom|closed_eyeblossom)$/D' => [60, 100],
        '/^minecraft:\w+_wool$/D' => [30, 60],
        '/^minecraft:(moss_carpet|pale_moss_carpet|moss_block|pale_moss_block|pale_hanging_moss)$/D' => [5, 100],
        '/^minecraft:\w+_carpet$/D' => [60, 20],
        '/^minecraft:(vine|glow_lichen|big_dripleaf)$/D' => [15, 100],
        '/^minecraft:(hay_block)$/D' => [60, 20],
        '/^minecraft:target$/D' => [15, 20],
        '/^minecraft:dried_kelp_block$/D' => [30, 60],
        '/^minecraft:(bamboo|scaffolding)$/D' => [60, 60],
        '/^minecraft:(azalea|flowering_azalea|hanging_roots)$/D' => [30, 60],
        '/^minecraft:(cave_vines|cave_vines_body_with_berries|cave_vines_head_with_berries)$/D' => [15, 60]
    ];

    /** @var array<string, array{int, int}> */
    private static array $flammability = [];

    public static function isFire(BlockState $block) : bool{
        $name = $block->getName();

        return $name === BlockIds::FIRE || $name === BlockIds::SOUL_FIRE;
    }

    public static function delay() : int{
        return 30 + mt_rand(0, 9);
    }

    /**
     * @return array{int, int}
     */
    public static function flammability(BlockState $block) : array{
        $name = $block->getName();

        if(!isset(self::$flammability[$name])){
            self::$flammability[$name] = [0, 0];

            foreach(self::FLAMMABLE as $pattern => $values){
                if(preg_match($pattern, $name) === 1){
                    self::$flammability[$name] = $values;
                    break;
                }
            }
        }

        return self::$flammability[$name];
    }

    private static function burns(?BlockState $block) : bool{
        return $block !== null && self::flammability($block)[0] > 0;
    }

    public static function canSurviveAt(World $world, int $x, int $y, int $z) : bool{
        $below = Nearby::block($world, $x, $y - 1, $z);

        return ($below !== null && BlockCollision::isFullCube($below)) || self::touchesFlammable($world, $x, $y, $z);
    }

    private static function touchesFlammable(World $world, int $x, int $y, int $z) : bool{
        foreach(Facing::ALL as $face){
            [$dx, $dy, $dz] = Facing::offset($face);

            if(self::burns(Nearby::block($world, $x + $dx, $y + $dy, $z + $dz))){
                return true;
            }
        }

        return false;
    }

    public static function stateAt(World $world, int $x, int $y, int $z, int $age = 0) : BlockState{
        $registry = Server::getInstance()->getBlockStateRegistry();
        $below = Nearby::block($world, $x, $y - 1, $z);

        if($below !== null && in_array($below->getName(), self::SOUL_GROUND, true)){
            return $registry->getDefaultState(BlockIds::SOUL_FIRE);
        }

        $fire = $registry->getDefaultState(BlockIds::FIRE)->withProperty("age", $age);

        if($below !== null && BlockCollision::isFullCube($below)){
            return $fire;
        }

        foreach(["minecraft:connection_north" => [0, 0, -1], "minecraft:connection_south" => [0, 0, 1], "minecraft:connection_west" => [-1, 0, 0], "minecraft:connection_east" => [1, 0, 0], "minecraft:connection_up" => [0, 1, 0]] as $property => [$dx, $dy, $dz]){
            $fire = $fire->withProperty($property, self::burns(Nearby::block($world, $x + $dx, $y + $dy, $z + $dz)));
        }

        return $fire;
    }

    public static function tick(World $world, int $x, int $y, int $z, BlockState $fire) : void{
        $air = Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::AIR);

        if($fire->getName() === BlockIds::SOUL_FIRE){
            if(!in_array(Nearby::name($world, $x, $y - 1, $z), self::SOUL_GROUND, true)){
                $world->setBlock($x, $y, $z, $air);
            }

            return;
        }

        if(!self::canSurviveAt($world, $x, $y, $z)){
            $world->setBlock($x, $y, $z, $air);
            return;
        }

        $world->getBlockTicker()->schedule($x, $y, $z, self::delay());

        if(!$world->getGameRules()->getBool(GameRules::DO_FIRE_TICK)){
            return;
        }

        $forever = in_array(Nearby::name($world, $x, $y - 1, $z), self::INFINIBURN, true);
        $age = $fire->getProperty("age");

        if(!$forever && $world->getWeather()->isRaining() && self::isNearRain($world, $x, $y, $z) && mt_rand() / mt_getrandmax() < 0.2 + $age * 0.03){
            $world->setBlock($x, $y, $z, $air);
            return;
        }

        $newAge = min(self::MAX_AGE, $age + intdiv(mt_rand(0, 2), 2));

        if($newAge !== $age){
            $world->setBlock($x, $y, $z, $fire->withProperty("age", $newAge));
        }

        if(!$forever){
            if(!self::touchesFlammable($world, $x, $y, $z)){
                $below = Nearby::block($world, $x, $y - 1, $z);

                if($below === null || !BlockCollision::isFullCube($below) || $age > 3){
                    $world->setBlock($x, $y, $z, $air);
                }

                return;
            }

            if($age === self::MAX_AGE && mt_rand(0, 3) === 0 && !self::burns(Nearby::block($world, $x, $y - 1, $z))){
                $world->setBlock($x, $y, $z, $air);
                return;
            }
        }

        foreach([[1, 0, 0, 300], [-1, 0, 0, 300], [0, -1, 0, 250], [0, 1, 0, 250], [0, 0, -1, 300], [0, 0, 1, 300]] as [$dx, $dy, $dz, $chance]){
            self::burnAway($world, $x + $dx, $y + $dy, $z + $dz, $chance, $age);
        }

        $difficulty = Server::getInstance()->getDifficulty()->value;

        for($dx = -1; $dx <= 1; ++$dx){
            for($dz = -1; $dz <= 1; ++$dz){
                for($dy = -1; $dy <= 4; ++$dy){
                    if($dx === 0 && $dy === 0 && $dz === 0){
                        continue;
                    }

                    //spreading upwards is easy, higher up harder
                    $odds = 100 + ($dy > 1 ? ($dy - 1) * 100 : 0);
                    [$tx, $ty, $tz] = [$x + $dx, $y + $dy, $z + $dz];
                    $encouragement = self::igniteOdds($world, $tx, $ty, $tz);

                    if($encouragement <= 0){
                        continue;
                    }

                    $spread = intdiv($encouragement + 40 + $difficulty * 7, $age + 30);

                    if($spread > 0 && mt_rand(0, $odds - 1) <= $spread && (!$world->getWeather()->isRaining() || !self::isNearRain($world, $tx, $ty, $tz))){
                        $world->setBlock($tx, $ty, $tz, self::stateAt($world, $tx, $ty, $tz, min(self::MAX_AGE, $age + intdiv(mt_rand(0, 4), 4))));
                    }
                }
            }
        }
    }

    private static function burnAway(World $world, int $x, int $y, int $z, int $chance, int $age) : void{
        $block = Nearby::block($world, $x, $y, $z);

        if($block === null || mt_rand(0, $chance - 1) >= self::flammability($block)[1]){
            return;
        }

        if($block->getName() === BlockIds::TNT){
            Tnt::ignite($world, $x, $y, $z);
            return;
        }

        if(mt_rand(0, $age + 9) < 5 && !$world->isRainingAt($x, $y, $z)){
            $world->setBlock($x, $y, $z, self::stateAt($world, $x, $y, $z, min(self::MAX_AGE, $age + intdiv(mt_rand(0, 4), 4))));
        }else{
            $world->setBlock($x, $y, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::AIR));
        }
    }

    /**
     * How readily fire spreads into an empty block: the most of what is around it.
     */
    private static function igniteOdds(World $world, int $x, int $y, int $z) : int{
        if(!Nearby::isAir($world, $x, $y, $z)){
            return 0;
        }

        $odds = 0;

        foreach(Facing::ALL as $face){
            [$dx, $dy, $dz] = Facing::offset($face);
            $block = Nearby::block($world, $x + $dx, $y + $dy, $z + $dz);

            if($block !== null){
                $odds = max($odds, self::flammability($block)[0]);
            }
        }

        return $odds;
    }

    private static function isNearRain(World $world, int $x, int $y, int $z) : bool{
        return $world->isRainingAt($x, $y, $z) || $world->isRainingAt($x - 1, $y, $z) || $world->isRainingAt($x + 1, $y, $z) || $world->isRainingAt($x, $y, $z - 1) || $world->isRainingAt($x, $y, $z + 1);
    }

    public function getBlockNames() : array{
        return [BlockIds::LAVA, BlockIds::FLOWING_LAVA];
    }

    public function ticks(BlockState $state) : bool{
        return true;
    }

    public function onRandomTick(World $world, int $x, int $y, int $z, BlockState $state) : void{
        if(!$world->getGameRules()->getBool(GameRules::DO_FIRE_TICK)){
            return;
        }

        $tries = mt_rand(0, 2);

        if($tries > 0){
            [$tx, $ty, $tz] = [$x, $y, $z];

            for($i = 0; $i < $tries; ++$i){
                [$tx, $ty, $tz] = [$tx + mt_rand(-1, 1), $ty + 1, $tz + mt_rand(-1, 1)];
                $block = Nearby::block($world, $tx, $ty, $tz);

                if($block === null){
                    return;
                }

                if($block->getName() === BlockIds::AIR){
                    if(self::touchesFlammable($world, $tx, $ty, $tz)){
                        $world->setBlock($tx, $ty, $tz, self::stateAt($world, $tx, $ty, $tz));
                        return;
                    }
                }elseif(BlockCollision::isSolid($block)){
                    return;
                }
            }

            return;
        }

        for($i = 0; $i < 3; ++$i){
            [$tx, $tz] = [$x + mt_rand(-1, 1), $z + mt_rand(-1, 1)];

            if(Nearby::isAir($world, $tx, $y + 1, $tz) && self::burns(Nearby::block($world, $tx, $y, $tz))){
                $world->setBlock($tx, $y + 1, $tz, self::stateAt($world, $tx, $y + 1, $tz));
            }
        }
    }

    public static function ignite(Player $player, int $x, int $y, int $z, int $face, BlockState $clicked, Item $held) : bool{
        $world = $player->getWorld();
        $name = $clicked->getName();
        $lit = null;

        if($name === BlockIds::TNT){
            Tnt::ignite($world, $x, $y, $z);
        }elseif($clicked->hasProperty("extinguished") && $clicked->getProperty("extinguished") === true){
            $lit = $clicked->withProperty("extinguished", false);
        }elseif($clicked->hasProperty("lit") && $clicked->getProperty("lit") === false && preg_match('/candle(_cake)?$/D', $name) === 1){
            $lit = $clicked->withProperty("lit", true);
        }else{
            [$dx, $dy, $dz] = Facing::offset($face);
            [$x, $y, $z] = [$x + $dx, $y + $dy, $z + $dz];

            if(!Nearby::isAir($world, $x, $y, $z) || !self::canSurviveAt($world, $x, $y, $z)){
                return false;
            }

            $lit = self::stateAt($world, $x, $y, $z);
        }

        if($lit !== null){
            $world->setBlock($x, $y, $z, $lit);
        }

        $world->broadcastPacket($x, $z, LevelSoundEventPacket::create(LevelSoundEvent::IGNITE, $x + 0.5, $y + 0.5, $z + 0.5));

        if(!$player->getGameMode()->hasInstantBuild()){
            if($held->getId() === ItemIds::FIRE_CHARGE){
                $player->getInventory()->setItemInHand($held->getCount() > 1 ? (clone $held)->setCount($held->getCount() - 1) : Item::air());
            }else{
                $player->wearHeld(1);
            }
        }

        return true;
    }
}