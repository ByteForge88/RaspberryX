<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\Server;

use raspberry\block\growth\Vines;
use raspberry\block\redstone\RedstoneSignal;

use raspberry\event\block\BlockDetachEvent;

use raspberry\item\Item;

use raspberry\math\Facing;
use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\types\LevelEvent;

use raspberry\world\Chunk;
use raspberry\world\GameRules;
use raspberry\world\World;

use JsonException;

use function in_array;
use function preg_match;

class BlockSupport{

    private const int NONE = 0;
    private const int FLOOR = 1;
    private const int FLOOR_ANY = 2;
    private const int SOIL = 3;
    private const int FARMLAND = 4;
    private const int SOUL_SAND = 5;
    private const int CACTUS = 6;
    private const int SUGAR_CANE = 7;
    private const int BAMBOO = 8;
    private const int ON_WATER = 9;
    private const int DRY = 10;
    private const int TORCH = 11;
    private const int FACING = 12;
    private const int TRIPWIRE_HOOK = 13;
    private const int COCOA = 14;
    private const int BLOCK_FACE = 15;
    private const int CORAL_WALL = 16;
    private const int HANGING = 17;
    private const int CEILING = 18;
    private const int HANGS_FROM = 19;
    private const int GROWS_FROM = 20;
    private const int VINE = 21;
    private const int MULTI_FACE = 22;
    private const int BELL = 23;
    private const int HANGING_SIGN = 24;
    private const int DOOR = 25;
    private const int BED = 26;
    private const int PROPAGULE = 27;
    private const int SNOW = 28;
    private const int UNDERWATER = 29;
    private const int LEVER = 30;

    /** what plants grow on */
    private const string SOIL_BLOCKS = '/^minecraft:(grass_block|dirt|coarse_dirt|podzol|dirt_with_roots|farmland|mycelium|moss_block|pale_moss_block|mud|muddy_mangrove_roots)$/D';

    /** what dead bushes and dry grass grow on */
    private const string DRY_BLOCKS = '/^minecraft:(sand|red_sand|suspicious_sand|\w*terracotta|grass_block|dirt|coarse_dirt|podzol|dirt_with_roots|farmland|mycelium|moss_block|pale_moss_block|mud|muddy_mangrove_roots)$/D';

    /** blocks the redstone updater already pops off itself */
    private const string REDSTONE = '/^minecraft:(redstone_wire|(powered|unpowered)_(repeater|comparator)|\w*pressure_plate|(unlit_)?redstone_torch|lever|\w+_button)$/D';

    /** @var array<string, int> */
    private const array KINDS = [
        '/^minecraft:(\w+_sapling|short_grass|fern|tall_grass|large_fern|sunflower|lilac|rose_bush|peony|(golden_)?dandelion|poppy|blue_orchid|allium|azure_bluet|\w+_tulip|oxeye_daisy|cornflower|lily_of_the_valley|wither_rose|torchflower|pitcher_plant|(open|closed)_eyeblossom|pink_petals|wildflowers|sweet_berry_bush|bush|firefly_bush|azalea|flowering_azalea|small_dripleaf_block)$/D' => self::SOIL,
        '/^minecraft:(wheat|carrots|potatoes|beetroot|melon_stem|pumpkin_stem|torchflower_crop|pitcher_crop)$/D' => self::FARMLAND,
        '/^minecraft:nether_wart$/D' => self::SOUL_SAND,
        '/^minecraft:cactus$/D' => self::CACTUS,
        '/^minecraft:reeds$/D' => self::SUGAR_CANE,
        '/^minecraft:(bamboo|bamboo_sapling)$/D' => self::BAMBOO,
        '/^minecraft:(waterlily|frog_spawn)$/D' => self::ON_WATER,
        '/^minecraft:(deadbush|(short|tall)_dry_grass)$/D' => self::DRY,
        '/^minecraft:(\w*_door)$/D' => self::DOOR,
        '/^minecraft:bed$/D' => self::BED,
        '/^minecraft:mangrove_propagule$/D' => self::PROPAGULE,
        '/^minecraft:snow_layer$/D' => self::SNOW,
        '/^minecraft:(kelp|seagrass)$/D' => self::UNDERWATER,
        '/^minecraft:(\w*carpet|cake|\w*candle_cake)$/D' => self::FLOOR_ANY,
        '/^minecraft:(\w*standing_sign|standing_banner|\w*rail|redstone_wire|(powered|unpowered)_(repeater|comparator)|\w*pressure_plate|\w*candle|sea_pickle|' .
        '(dead_)?\w+_coral(_fan)?|(brown|red)_mushroom|(crimson|warped)_(fungus|roots)|nether_sprouts|cactus_flower|turtle_egg|leaf_litter)$/D' => self::FLOOR,
        '/^minecraft:(torch|soul_torch|copper_torch|underwater_torch|colored_torch_\w+|(unlit_)?redstone_torch)$/D' => self::TORCH,
        '/^minecraft:(ladder|\w*wall_sign|wall_banner|(glow_)?frame|\w+_button)$/D' => self::FACING,
        '/^minecraft:lever$/D' => self::LEVER,
        '/^minecraft:tripwire_hook$/D' => self::TRIPWIRE_HOOK,
        '/^minecraft:cocoa$/D' => self::COCOA,
        '/^minecraft:((small|medium|large)_amethyst_bud|amethyst_cluster)$/D' => self::BLOCK_FACE,
        '/^minecraft:(dead_)?\w+_coral_wall_fan$/D' => self::CORAL_WALL,
        '/^minecraft:(\w*lantern)$/D' => self::HANGING,
        '/^minecraft:(spore_blossom|hanging_roots)$/D' => self::CEILING,
        '/^minecraft:(cave_vines\w*|weeping_vines)$/D' => self::HANGS_FROM,
        '/^minecraft:twisting_vines$/D' => self::GROWS_FROM,
        '/^minecraft:vine$/D' => self::VINE,
        '/^minecraft:(glow_lichen|sculk_vein)$/D' => self::MULTI_FACE,
        '/^minecraft:bell$/D' => self::BELL,
        '/^minecraft:\w+_hanging_sign$/D' => self::HANGING_SIGN
    ];

    /** multi_face_direction_bits */
    private const array MULTI_FACE_BITS = [1 => Facing::DOWN, 2 => Facing::UP, 4 => Facing::SOUTH, 8 => Facing::WEST, 16 => Facing::NORTH, 32 => Facing::EAST];

    private const array CORAL_WALL_FACES = [Facing::WEST, Facing::EAST, Facing::NORTH, Facing::SOUTH];

    /** @var array<string, int> block name => kind */
    private static array $kinds = [];

    private function __construct(){
    }

    private static function kind(string $name) : int{
        if(isset(self::$kinds[$name])){
            return self::$kinds[$name];
        }

        //the sea lantern is a full block, and trapdoors are not doors
        if($name === BlockIds::SEA_LANTERN || preg_match('/trapdoor$/', $name) === 1){
            return self::$kinds[$name] = self::NONE;
        }

        foreach(self::KINDS as $pattern => $kind){
            if(preg_match($pattern, $name) === 1){
                return self::$kinds[$name] = $kind;
            }
        }

        return self::$kinds[$name] = self::NONE;
    }

    /**
     * Whether the block needs something to stay where it is.
     */
    public static function needsSupport(BlockState $block) : bool{
        return self::kind($block->getName()) !== self::NONE;
    }

    /**
     * Whether $block can be at x y z. While placing, the other half of a door, bed or tall
     * plant is not there yet and is not asked for.
     */
    public static function canStay(World $world, int $x, int $y, int $z, BlockState $block, bool $placing = false) : bool{
        return self::check($world, $x, $y, $z, $block, $placing) === null;
    }

    /**
     * Null when the block can stay, else whether it drops: a block that lost what holds it
     * drops, the leftover half of a door or tall plant does not, the other half drops already.
     */
    private static function check(World $world, int $x, int $y, int $z, BlockState $block, bool $placing) : ?bool{
        if(BigDripleaf::is($block)){
            return BigDripleaf::stays($world, $x, $y, $z, $block) ? null : true;
        }

        $kind = self::kind($block->getName());

        if($kind === self::NONE){
            return null;
        }

        $below = fn() : BlockState => self::at($world, $x, $y - 1, $z);
        $above = fn() : BlockState => self::at($world, $x, $y + 1, $z);
        $solid = fn(int $face) : bool => BlockCollision::isSolid(self::side($world, $x, $y, $z, $face));
        $upper = $block->hasProperty(BlockProperties::UPPER_BLOCK_BIT) && $block->getProperty(BlockProperties::UPPER_BLOCK_BIT) === true;

        if($block->hasProperty(BlockProperties::UPPER_BLOCK_BIT) && $kind !== self::BED){
            if($upper){
                return $placing || self::isHalf($below(), $block, false) ? null : false;
            }

            if(!$placing && !self::isHalf($above(), $block, true)){
                return false;
            }
        }

        $stays = match($kind){
            self::FLOOR => $solid(Facing::DOWN),
            self::FLOOR_ANY => $below()->getName() !== BlockIds::AIR,
            self::SOIL => preg_match(self::SOIL_BLOCKS, $below()->getName()) === 1,
            self::FARMLAND => $below()->getName() === BlockIds::FARMLAND,
            self::SOUL_SAND => $below()->getName() === BlockIds::SOUL_SAND,
            self::CACTUS => self::cactusStays($world, $x, $y, $z, $below()),
            self::SUGAR_CANE => self::sugarCaneStays($world, $x, $y, $z, $below()),
            self::BAMBOO => preg_match('/^minecraft:(bamboo|bamboo_sapling|grass_block|dirt|coarse_dirt|podzol|dirt_with_roots|mycelium|moss_block|pale_moss_block|mud|muddy_mangrove_roots|sand|red_sand|suspicious_sand|gravel|suspicious_gravel)$/D', $below()->getName()) === 1,
            self::ON_WATER => in_array($below()->getName(), [BlockIds::WATER, BlockIds::FLOWING_WATER, BlockIds::ICE, BlockIds::FROSTED_ICE], true),
            self::DRY => preg_match(self::DRY_BLOCKS, $below()->getName()) === 1,
            self::DOOR => $upper || $solid(Facing::DOWN),
            self::BED => $placing || self::bedStays($world, $x, $y, $z, $block),
            self::PROPAGULE => $block->getProperty(BlockProperties::HANGING) === true ?
                preg_match('/^minecraft:mangrove_leaves$/D', $above()->getName()) === 1 :
                preg_match('/^minecraft:(grass_block|dirt|coarse_dirt|podzol|dirt_with_roots|farmland|mycelium|moss_block|pale_moss_block|mud|muddy_mangrove_roots|clay)$/D', $below()->getName()) === 1,
            self::SNOW => self::snowStays($below()),
            self::UNDERWATER => $solid(Facing::DOWN) || $below()->getName() === BlockIds::KELP,
            self::TORCH => $solid(self::torchAttachment($block)),
            self::LEVER => $solid(RedstoneSignal::getAttachment($block)),
            self::FACING => $solid(Facing::opposite((int) $block->getProperty(BlockProperties::FACING_DIRECTION))),
            self::TRIPWIRE_HOOK => $solid(Facing::opposite(Facing::fromLegacyDirection((int) $block->getProperty(BlockProperties::DIRECTION)))),
            self::COCOA => preg_match('/^minecraft:(stripped_)?jungle_(log|wood)$/D', self::side($world, $x, $y, $z, Facing::fromLegacyDirection((int) $block->getProperty(BlockProperties::DIRECTION)))->getName()) === 1,
            self::BLOCK_FACE => $solid(Facing::opposite(Facing::fromName((string) $block->getProperty(BlockProperties::MINECRAFT_BLOCK_FACE)))),
            self::CORAL_WALL => $solid(Facing::opposite(self::CORAL_WALL_FACES[(int) $block->getProperty(BlockProperties::CORAL_DIRECTION) & 3])),
            self::HANGING => $solid($block->getProperty(BlockProperties::HANGING) === true ? Facing::UP : Facing::DOWN),
            self::CEILING => $solid(Facing::UP),
            self::HANGS_FROM => $solid(Facing::UP) || self::sameVine($above()->getName(), $block->getName()),
            self::GROWS_FROM => $solid(Facing::DOWN) || $below()->getName() === $block->getName(),
            self::VINE => self::vineStays($world, $x, $y, $z, $block),
            self::MULTI_FACE => self::multiFaceStays($world, $x, $y, $z, $block),
            self::BELL => match((string) $block->getProperty(BlockProperties::ATTACHMENT)){
                "standing" => $solid(Facing::DOWN),
                "hanging" => $solid(Facing::UP),
                "side" => $solid(Facing::opposite(Facing::fromLegacyDirection((int) $block->getProperty(BlockProperties::DIRECTION)))),
                default => true
            },
            self::HANGING_SIGN => $block->getProperty(BlockProperties::HANGING) === true ?
                $solid(Facing::UP) || preg_match('/chain$|_hanging_sign$/', $above()->getName()) === 1 :
                $solid(Facing::opposite((int) $block->getProperty(BlockProperties::FACING_DIRECTION))),
            default => true
        };

        return $stays ? null : !$upper && $kind !== self::BED;
    }

    /**
     * A block changed: what stood on it, hung from it or leaned on it may have to pop off.
     *
     * @throws JsonException
     */
    public static function onBlockChanged(World $world, int $x, int $y, int $z, BlockState $old, BlockState $new) : void{
        if($old->getName() === $new->getName() && !$old->hasProperty(BlockProperties::UPPER_BLOCK_BIT) && $new->getName() !== BlockIds::VINE){
            return;
        }

        foreach(Facing::ALL as $face){
            [$dx, $dy, $dz] = Facing::offset($face);
            [$nx, $ny, $nz] = [$x + $dx, $y + $dy, $z + $dz];

            if($ny < Chunk::MIN_Y || $ny > Chunk::MAX_Y || !$world->isChunkLoaded($nx >> 4, $nz >> 4)){
                continue;
            }

            $neighbor = $world->getBlock($nx, $ny, $nz);

            if(BigDripleaf::is($neighbor)){
                BigDripleaf::onNeighborChanged($world, $nx, $ny, $nz, $neighbor);
                continue;
            }

            if(self::kind($neighbor->getName()) === self::NONE || preg_match(self::REDSTONE, $neighbor->getName()) === 1){
                continue;
            }

            if($neighbor->getName() === BlockIds::VINE){
                self::trimVine($world, $nx, $ny, $nz, $neighbor);
                continue;
            }

            $drops = self::check($world, $nx, $ny, $nz, $neighbor, false);

            if($drops !== null){
                self::popOff($world, $nx, $ny, $nz, $neighbor, $drops);
            }
        }
    }

    /**
     * Breaks the block the way it falls off, with its drops and the breaking particles.
     */
    public static function popOff(World $world, int $x, int $y, int $z, BlockState $block, bool $drops = true) : void{
        $items = [];

        if($drops && $world->getGameRules()->getBool(GameRules::DO_TILE_DROPS)){
            $items = BlockBreakInfo::get($block->getName())->getDrops($block, Item::air(), true);
        }

        $event = new BlockDetachEvent($world, new Vector3($x, $y, $z), $block, $items);
        $event->call();

        if($event->isCancelled()){
            return;
        }

        $center = new Vector3($x + 0.5, $y + 0.5, $z + 0.5);
        $entity = $world->getBlockEntity($x, $y, $z);

        $world->setBlock($x, $y, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::AIR));
        $world->broadcastPacket($x, $z, LevelEventPacket::create(LevelEvent::PARTICLES_DESTROY_BLOCK, $center->x, $center->y, $center->z, Server::getInstance()->getBlockTranslator()->getNetworkId($block)));

        foreach($entity?->onBreak($world) ?? [] as $content){
            $world->dropItem($center, $content);
        }

        foreach($event->getDrops() as $item){
            $world->dropItem($center, $item);
        }
    }

    private static function at(World $world, int $x, int $y, int $z) : BlockState{
        if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y || !$world->isChunkLoaded($x >> 4, $z >> 4)){
            return Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::STONE);
        }

        return $world->getBlock($x, $y, $z);
    }

    private static function side(World $world, int $x, int $y, int $z, int $face) : BlockState{
        [$dx, $dy, $dz] = Facing::offset($face);

        return self::at($world, $x + $dx, $y + $dy, $z + $dz);
    }

    private static function isHalf(BlockState $other, BlockState $block, bool $upper) : bool{
        return self::withoutWeathering($other->getName()) === self::withoutWeathering($block->getName()) && $other->hasProperty(BlockProperties::UPPER_BLOCK_BIT) && $other->getProperty(BlockProperties::UPPER_BLOCK_BIT) === $upper;
    }

    /**
     * Copper doors weather and get waxed one half at a time, the halves still belong together.
     */
    private static function withoutWeathering(string $name) : string{
        return preg_replace('/^minecraft:(waxed_)?(exposed_|weathered_|oxidized_)?/', "minecraft:", $name) ?? $name;
    }

    private static function torchAttachment(BlockState $block) : int{
        $value = (string) $block->getProperty(BlockProperties::TORCH_FACING_DIRECTION);
        return $value === "top" || $value === "unknown" ? Facing::DOWN : Facing::fromName($value);
    }

    /**
     * Cactus stands on sand or cactus, with nothing solid on its sides.
     */
    private static function cactusStays(World $world, int $x, int $y, int $z, BlockState $below) : bool{
        if(preg_match('/^minecraft:(sand|red_sand|suspicious_sand|cactus)$/D', $below->getName()) !== 1){
            return false;
        }

        foreach(Facing::HORIZONTAL as $face){
            if(BlockCollision::isSolid(self::side($world, $x, $y, $z, $face))){
                return false;
            }
        }

        return true;
    }

    /**
     * Sugar cane stands on sugar cane, or on soil or sand next to water.
     */
    private static function sugarCaneStays(World $world, int $x, int $y, int $z, BlockState $below) : bool{
        if($below->getName() === BlockIds::REEDS){
            return true;
        }

        if(preg_match('/^minecraft:(grass_block|dirt|coarse_dirt|podzol|dirt_with_roots|mycelium|moss_block|pale_moss_block|mud|muddy_mangrove_roots|sand|red_sand|suspicious_sand)$/D', $below->getName()) !== 1){
            return false;
        }

        foreach(Facing::HORIZONTAL as $face){
            $next = self::side($world, $x, $y - 1, $z, $face)->getName();

            if($next === BlockIds::WATER || $next === BlockIds::FLOWING_WATER || $next === BlockIds::FROSTED_ICE){
                return true;
            }
        }

        return false;
    }

    private static function bedStays(World $world, int $x, int $y, int $z, BlockState $block) : bool{
        $facing = Facing::fromLegacyDirection((int) $block->getProperty(BlockProperties::DIRECTION));
        $head = $block->getProperty(BlockProperties::HEAD_PIECE_BIT) === true;
        $other = self::side($world, $x, $y, $z, $head ? Facing::opposite($facing) : $facing);

        return $other->getName() === $block->getName() && $other->getProperty(BlockProperties::HEAD_PIECE_BIT) === !$head;
    }

    /**
     * Snow lies on anything solid but ice and packed ice, and on leaves.
     */
    private static function snowStays(BlockState $below) : bool{
        $name = $below->getName();

        if(in_array($name, [BlockIds::ICE, BlockIds::PACKED_ICE, "minecraft:barrier"], true)){
            return false;
        }

        if($name === BlockIds::SNOW_LAYER){
            return (int) $below->getProperty(BlockProperties::HEIGHT) === 7;
        }

        return BlockCollision::isSolid($below);
    }

    private static function sameVine(string $above, string $vine) : bool{
        return $above === $vine || (preg_match('/^minecraft:cave_vines/', $vine) === 1 && preg_match('/^minecraft:cave_vines/', $above) === 1);
    }

    /**
     * A vine stays while one of its sides leans on a solid block or hangs from the vine above.
     */
    private static function vineStays(World $world, int $x, int $y, int $z, BlockState $vine) : bool{
        return Vines::getHeldBits($world, $x, $y, $z, (int) $vine->getProperty(BlockProperties::VINE_DIRECTION_BITS)) !== 0;
    }

    /**
     * Only the sides that lost their hold come off, the vine goes when none are left.
     */
    private static function trimVine(World $world, int $x, int $y, int $z, BlockState $vine) : void{
        $bits = (int) $vine->getProperty(BlockProperties::VINE_DIRECTION_BITS);
        $held = Vines::getHeldBits($world, $x, $y, $z, $bits);

        if($held === 0){
            self::popOff($world, $x, $y, $z, $vine);
        }elseif($held !== $bits){
            $world->setBlock($x, $y, $z, Vines::withBits($held));
        }
    }

    private static function multiFaceStays(World $world, int $x, int $y, int $z, BlockState $block) : bool{
        $bits = (int) $block->getProperty(BlockProperties::MULTI_FACE_DIRECTION_BITS);

        foreach(self::MULTI_FACE_BITS as $bit => $face){
            if(($bits & $bit) !== 0 && BlockCollision::isSolid(self::side($world, $x, $y, $z, $face))){
                return true;
            }
        }

        return false;
    }
}