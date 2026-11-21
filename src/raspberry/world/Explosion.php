<?php

declare(strict_types=1);

namespace raspberry\world;

use raspberry\Server;

use raspberry\block\BlockBreakInfo;
use raspberry\block\BlockCollision;
use raspberry\block\BlockIds;
use raspberry\block\BlockStateIds;
use raspberry\block\TNT;

use raspberry\entity\Entity;
use raspberry\entity\Living;
use raspberry\entity\damage\DamageSource;
use raspberry\entity\object\FallingBlock;
use raspberry\entity\object\Boat;
use raspberry\entity\object\ItemEntity;
use raspberry\entity\object\Painting;
use raspberry\entity\object\PrimedTNT;

use raspberry\event\world\ExplosionEvent;

use raspberry\item\Item;

use raspberry\math\Vector3;

use raspberry\native\Native;

use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelEvent;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;
use raspberry\network\mcpe\protocol\types\ParticleType;

use FFI;
use RuntimeException;
use UnexpectedValueException;

use function count;
use function dirname;
use function file_get_contents;
use function is_array;
use function json_decode;
use function max;
use function mt_getrandmax;
use function mt_rand;
use function sqrt;
use function strlen;
use function unpack;

use const JSON_THROW_ON_ERROR;

class Explosion{

    private const float DEFAULT_RESISTANCE = 6.0;

    private static bool $taught = false;

    private World $world;

    private Vector3 $center;

    private float $radius;

    private ?Entity $source;

    private float $dropChance;

    private bool $blockBreaking = true;

    public function __construct(World $world, Vector3 $center, float $radius, ?Entity $source = null, float $dropChance = 1.0){
        $this->world = $world;
        $this->center = $center;
        $this->radius = $radius;
        $this->source = $source;
        $this->dropChance = $dropChance;
    }

    /**
     * Whether the blast breaks blocks, or only hurts and pushes things.
     */
    public function setBlockBreaking(bool $blockBreaking) : void{
        $this->blockBreaking = $blockBreaking;
    }

    private static function teach() : void{
        if(self::$taught){
            return;
        }

        $path = dirname(__DIR__, 3) . "/resources/vanilla/block_resistance.json";
        $contents = @file_get_contents($path);

        if($contents === false){
            throw new RuntimeException("Cannot read block blast resistance: {$path}");
        }

        $data = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);

        if(!is_array($data) || !is_array($data["blocks"] ?? null)){
            throw new UnexpectedValueException("Block blast resistance has an invalid layout: {$path}");
        }

        $registry = Server::getInstance()->getBlockStateRegistry();
        $entries = [];

        foreach($registry->getBlockNames() as $name){
            $resistance = (float) ($data["blocks"][$name] ?? self::DEFAULT_RESISTANCE);

            foreach($registry->getAllStates($name) as $state){
                $entries[] = [BlockStateIds::get($state), $resistance, BlockCollision::isSolid($state) ? 1 : 0];
            }
        }

        $count = count($entries);
        $ffi = Native::get();
        $states = $ffi->new("uint32_t[{$count}]");
        $resistances = $ffi->new("float[{$count}]");
        $solids = $ffi->new("uint8_t[{$count}]");

        foreach($entries as $i => [$id, $resistance, $solid]){
            $states[$i] = $id;
            $resistances[$i] = $resistance;
            $solids[$i] = $solid;
        }

        $ffi->rx_explosion_blocks($states, $resistances, $solids, $count, BlockStateIds::get($registry->getDefaultState(BlockIds::AIR)));
        self::$taught = true;
    }

    public function explode() : void{
        self::teach();

        $world = $this->world;
        $center = $this->center;
        $diameter = $this->radius * 2;
        $ffi = Native::get();

        $handles = [];
        $reach = (int) (($diameter + 2) / 16) + 1;

        for($cx = $center->getChunkX() - $reach; $cx <= $center->getChunkX() + $reach; ++$cx){
            for($cz = $center->getChunkZ() - $reach; $cz <= $center->getChunkZ() + $reach; ++$cz){
                $chunk = $world->getChunk($cx, $cz);

                if($chunk !== null){
                    $handles[] = [$cx, $cz, $chunk->getHandle()];
                }
            }
        }

        if($handles === []){
            return;
        }

        $near = [];

        foreach($world->getEntities() as $entity){
            if($entity !== $this->source && !$entity->isClosed() && $entity->getPosition()->distance($center) <= $diameter + 1){
                $near[] = $entity;
            }
        }

        $chunkCount = count($handles);
        $chunks = $ffi->new("uint64_t[{$chunkCount}]");
        $positions = $ffi->new("int32_t[" . ($chunkCount * 2) . "]");

        foreach($handles as $i => [$cx, $cz, $handle]){
            $chunks[$i] = $handle;
            $positions[$i * 2] = $cx;
            $positions[$i * 2 + 1] = $cz;
        }

        $boxCount = count($near);
        $boxes = $ffi->new("double[" . max(1, $boxCount * 6) . "]");
        $exposures = $ffi->new("float[" . max(1, $boxCount) . "]");

        foreach($near as $i => $entity){
            $position = $entity->getPosition();
            $half = $entity->getWidth() / 2;

            foreach([$position->x - $half, $position->y, $position->z - $half, $position->x + $half, $position->y + $entity->getHeight(), $position->z + $half] as $j => $value){
                $boxes[$i * 6 + $j] = $value;
            }
        }

        $out = $ffi->new("char*");
        $length = $ffi->new("size_t");

        if($ffi->rx_explode($chunks, $positions, $chunkCount, $center->x, $center->y, $center->z, $this->radius, mt_rand(), $boxes, $boxCount, $exposures, FFI::addr($out), FFI::addr($length)) !== 1){
            return;
        }

        $data = Native::take($out, $length->cdata);
        $blocks = [];

        if($this->blockBreaking){
            for($offset = 0, $size = strlen($data); $offset < $size; $offset += 12){
                $block = unpack("lx/ly/lz", $data, $offset);
                $blocks[] = new Vector3($block["x"], $block["y"], $block["z"]);
            }
        }

        $event = new ExplosionEvent($world, $center, $this->radius, $this->source, $blocks, $this->dropChance);
        $event->call();

        if($event->isCancelled()){
            return;
        }

        $this->dropChance = $event->getDropChance();

        foreach($near as $i => $entity){
            $this->hit($entity, $exposures[$i]);
        }

        foreach($event->getBlocks() as $block){
            $this->breakBlock($block->getFloorX(), $block->getFloorY(), $block->getFloorZ());
        }

        $world->broadcastPacket($center->getFloorX(), $center->getFloorZ(), LevelSoundEventPacket::create(LevelSoundEvent::EXPLODE, $center->x, $center->y, $center->z));
        $particle = $this->radius >= 2.0 ? ParticleType::HUGE_EXPLOSION : ParticleType::LARGE_EXPLODE;
        $world->broadcastPacket($center->getFloorX(), $center->getFloorZ(), LevelEventPacket::create(LevelEvent::PARTICLE_LEGACY_EVENT | $particle, $center->x, $center->y, $center->z));
    }

    private function hit(Entity $entity, float $exposure) : void{
        $diameter = $this->radius * 2;
        $position = $entity->getPosition();
        $distance = $position->distance($this->center) / $diameter;

        if($distance > 1.0){
            return;
        }

        $impact = (1.0 - $distance) * $exposure;

        if($impact <= 0.0){
            return;
        }

        if($entity instanceof ItemEntity){
            $entity->close();
            return;
        }

        if($entity instanceof Painting){
            $entity->destroy(true);
            return;
        }

        if($entity instanceof Boat){
            $entity->hurt((float) (int) (($impact * $impact + $impact) / 2 * 7 * $diameter + 1));
            return;
        }

        $target = $entity instanceof Living ? $entity->getEyePosition() : $position;
        $dx = $target->x - $this->center->x;
        $dy = $target->y - $this->center->y;
        $dz = $target->z - $this->center->z;
        $length = sqrt($dx * $dx + $dy * $dy + $dz * $dz);

        if($length > 0.0){
            [$dx, $dy, $dz] = [$dx / $length, $dy / $length, $dz / $length];
        }

        if($entity instanceof Living){
            if(!$entity->isAlive()){
                return;
            }

            $cause = $this->source instanceof PrimedTNT || $this->source === null ? DamageSource::CAUSE_BLOCK_EXPLOSION : DamageSource::CAUSE_ENTITY_EXPLOSION;
            $entity->damage(new DamageSource($cause, (float) (int) (($impact * $impact + $impact) / 2 * 7 * $diameter + 1)));

            $push = $impact * (1 - $entity->getKnockbackResistance());
            $motion = $entity->getMotion();
            $entity->setMotion(new Vector3($motion->x + $dx * $push, $motion->y + $dy * $push, $motion->z + $dz * $push));
        }elseif($entity instanceof PrimedTNT || $entity instanceof FallingBlock){
            $motion = $entity->getMotion();
            $entity->setMotion(new Vector3($motion->x + $dx * $impact, $motion->y + $dy * $impact, $motion->z + $dz * $impact));
        }
    }

    private function breakBlock(int $x, int $y, int $z) : void{
        $world = $this->world;
        $block = $world->getBlock($x, $y, $z);

        if($block->getName() === BlockIds::TNT){
            TNT::ignite($world, $x, $y, $z, TNT::chainFuse());
            return;
        }

        $center = new Vector3($x + 0.5, $y + 0.5, $z + 0.5);
        $drops = $world->getGameRules()->getBool(GameRules::DO_TILE_DROPS);

        foreach($world->getBlockEntity($x, $y, $z)?->onBreak($world) ?? [] as $content){
            $world->dropItem($center, $content);
        }

        if($drops && mt_rand() / mt_getrandmax() < $this->dropChance){
            foreach(BlockBreakInfo::get($block->getName())->getDrops($block, Item::air(), true) as $item){
                $world->dropItem($center, $item);
            }
        }

        $world->setBlock($x, $y, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::AIR));
    }
}