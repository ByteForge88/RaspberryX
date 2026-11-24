<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\block\entity\NoteBlock;

use raspberry\network\mcpe\protocol\BlockEventPacket;
use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\world\World;

use function preg_match;
use function str_contains;

class NoteBlocks{

    public const int HARP = 0;
    public const int BASS_DRUM = 1;
    public const int SNARE = 2;
    public const int HAT = 3;
    public const int BASS = 4;
    public const int FLUTE = 5;
    public const int BELL = 6;
    public const int GUITAR = 7;
    public const int CHIME = 8;
    public const int XYLOPHONE = 9;
    public const int IRON_XYLOPHONE = 10;
    public const int COW_BELL = 11;
    public const int DIDGERIDOO = 12;
    public const int BIT = 13;
    public const int BANJO = 14;
    public const int PLING = 15;
    public const int ZOMBIE = 16;
    public const int SKELETON = 17;
    public const int CREEPER = 18;
    public const int DRAGON = 19;
    public const int WITHER_SKELETON = 20;
    public const int PIGLIN = 21;

    private const array HEADS = [
        BlockIds::ZOMBIE_HEAD => self::ZOMBIE,
        BlockIds::SKELETON_SKULL => self::SKELETON,
        BlockIds::CREEPER_HEAD => self::CREEPER,
        BlockIds::DRAGON_HEAD => self::DRAGON,
        BlockIds::WITHER_SKELETON_SKULL => self::WITHER_SKELETON,
        BlockIds::PIGLIN_HEAD => self::PIGLIN
    ];

    private const array BELOW = [
        BlockIds::CLAY => self::FLUTE,
        BlockIds::GOLD_BLOCK => self::BELL,
        BlockIds::PACKED_ICE => self::CHIME,
        BlockIds::BONE_BLOCK => self::XYLOPHONE,
        BlockIds::IRON_BLOCK => self::IRON_XYLOPHONE,
        BlockIds::SOUL_SAND => self::COW_BELL,
        BlockIds::PUMPKIN => self::DIDGERIDOO,
        BlockIds::CARVED_PUMPKIN => self::DIDGERIDOO,
        BlockIds::LIT_PUMPKIN => self::DIDGERIDOO,
        BlockIds::EMERALD_BLOCK => self::BIT,
        BlockIds::HAY_BLOCK => self::BANJO,
        BlockIds::GLOWSTONE => self::PLING,
        BlockIds::SEA_LANTERN => self::HAT,
        BlockIds::BEACON => self::HAT
    ];

    private const string SNARE_BLOCKS = '/^minecraft:((red_)?sand|gravel|suspicious_(sand|gravel)|\w+_concrete_powder)$/D';

    private const string HAT_BLOCKS = '/glass(_pane)?$/D';

    private const string WOOL = '/_wool$/D';

    private const string NOT_DRUM = '/copper|iron|gold|netherite|anvil|chain|lantern|hopper|cauldron|rail|_bars$|bell$/';

    private function __construct(){
    }

    public static function getEntity(World $world, int $x, int $y, int $z) : NoteBlock{
        $entity = $world->getBlockEntity($x, $y, $z);

        if(!$entity instanceof NoteBlock){
            $entity = new NoteBlock($x, $y, $z);
            $world->addBlockEntity($entity);
        }

        return $entity;
    }

    public static function tune(World $world, int $x, int $y, int $z) : void{
        $entity = self::getEntity($world, $x, $y, $z);
        $entity->setNote(($entity->getNote() + 1) % (NoteBlock::MAX_NOTE + 1));
        self::play($world, $x, $y, $z);
    }

    public static function updatePower(World $world, int $x, int $y, int $z, bool $powered) : void{
        $entity = self::getEntity($world, $x, $y, $z);

        if($entity->isPowered() === $powered){
            return;
        }

        $entity->setPowered($powered);

        if($powered){
            self::play($world, $x, $y, $z);
        }
    }

    public static function play(World $world, int $x, int $y, int $z) : void{
        $above = $world->getBlock($x, $y + 1, $z)->getName();
        $head = self::HEADS[$above] ?? null;

        if($head === null && $above !== BlockIds::AIR){
            return;
        }

        $instrument = $head ?? self::getInstrument($world->getBlock($x, $y - 1, $z));
        $note = $head === null ? self::getEntity($world, $x, $y, $z)->getNote() : 0;

        $world->broadcastPacket($x, $z, BlockEventPacket::create($x, $y, $z, $instrument, $note));
        $world->broadcastPacket($x, $z, LevelSoundEventPacket::create(LevelSoundEvent::NOTE, $x + 0.5, $y + 0.5, $z + 0.5, ($instrument << 8) | $note));
    }

    public static function getInstrument(BlockState $below) : int{
        $name = $below->getName();

        if(isset(self::BELOW[$name])){
            return self::BELOW[$name];
        }

        $tool = BlockBreakInfo::get($name)->getTool();

        return match(true){
            preg_match(self::SNARE_BLOCKS, $name) === 1 => self::SNARE,
            preg_match(self::HAT_BLOCKS, $name) === 1 => self::HAT,
            preg_match(self::WOOL, $name) === 1 => self::GUITAR,
            $tool === "axe" && !str_contains($name, "pumpkin") && !str_contains($name, "melon") => self::BASS,
            $tool === "pickaxe" && preg_match(self::NOT_DRUM, $name) !== 1 => self::BASS_DRUM,
            default => self::HARP
        };
    }
}