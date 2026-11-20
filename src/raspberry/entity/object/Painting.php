<?php

declare(strict_types=1);

namespace raspberry\entity\object;

use raspberry\block\BlockCollision;

use raspberry\entity\Entity;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\math\Facing;
use raspberry\math\Vector3;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\types\LevelEvent;

use raspberry\world\GameRules;
use raspberry\world\World;

use function count;
use function intdiv;
use function max;
use function min;
use function mt_rand;
use function preg_match;

class Painting extends Entity{

    public const string NETWORK_ID = "minecraft:painting";

    private const float THICKNESS = 1 / 16;

    private const int BREAK_DELAY = 20;

    private const string SUPPORT = '/^minecraft:(powered|unpowered)_(repeater|comparator)$/D';

    private PaintingMotive $motive;

    private int $facing;

    private int $blockX;
    private int $blockY;
    private int $blockZ;

    private int $unsupportedTicks = 0;

    public function __construct(World $world, int $x, int $y, int $z, int $facing, PaintingMotive $motive){
        $this->blockX = $x;
        $this->blockY = $y;
        $this->blockZ = $z;
        $this->facing = $facing;
        $this->motive = $motive;
        $this->yaw = match($facing){
            Facing::SOUTH => 0.0,
            Facing::WEST => 90.0,
            Facing::NORTH => 180.0,
            default => 270.0
        };

        parent::__construct($world, self::centerOf($x, $y, $z, $facing, $motive));
    }

    /**
     * Hangs a painting on the $face side of the block at x/y/z, picking one of the biggest that
     * fit there. Null when none fits.
     */
    public static function place(World $world, int $x, int $y, int $z, int $face) : ?self{
        if(!Facing::isHorizontal($face)){
            return null;
        }

        [$dx, , $dz] = Facing::offset($face);
        [$x, $z] = [$x + $dx, $z + $dz];

        $fitting = [];
        $biggest = 0;

        foreach(PaintingMotive::cases() as $motive){
            $area = $motive->getWidth() * $motive->getHeight();

            if($area < $biggest || !self::canFit($world, $x, $y, $z, $face, $motive, null)){
                continue;
            }

            if($area > $biggest){
                $biggest = $area;
                $fitting = [];
            }

            $fitting[] = $motive;
        }

        if($fitting === []){
            return null;
        }

        $painting = new self($world, $x, $y, $z, $face, $fitting[mt_rand(0, count($fitting) - 1)]);
        $world->broadcastPacket($x, $z, LevelEventPacket::create(LevelEvent::SOUND_ITEM_FRAME_PLACE, $painting->position->x, $painting->position->y, $painting->position->z));

        return $painting;
    }

    public static function fromSave(World $world, CompoundTag $tag) : ?self{
        $motive = PaintingMotive::tryFrom($tag->getString("Motif", "") ?? "");
        [$x, $y, $z] = [$tag->getInt("TileX"), $tag->getInt("TileY"), $tag->getInt("TileZ")];

        if($motive === null || $x === null || $y === null || $z === null){
            return null;
        }

        return new self($world, $x, $y, $z, Facing::fromLegacyDirection($tag->getByte("Direction", 0) ?? 0), $motive);
    }

    public function save() : ?CompoundTag{
        if($this->closed){
            return null;
        }

        $tag = new CompoundTag();
        $tag->setString("identifier", self::NETWORK_ID);
        $tag->setDouble("X", $this->position->x);
        $tag->setDouble("Y", $this->position->y);
        $tag->setDouble("Z", $this->position->z);
        $tag->setString("Motif", $this->motive->value);
        $tag->setByte("Direction", Facing::toLegacyDirection($this->facing));
        $tag->setInt("TileX", $this->blockX);
        $tag->setInt("TileY", $this->blockY);
        $tag->setInt("TileZ", $this->blockZ);

        return $tag;
    }

    public function getNetworkTypeId() : string{
        return self::NETWORK_ID;
    }

    public function getMotive() : PaintingMotive{
        return $this->motive;
    }

    public function getFacing() : int{
        return $this->facing;
    }

    public function tick(int $currentTick) : void{
        if($this->closed || !$this->world->isChunkLoaded($this->blockX >> 4, $this->blockZ >> 4)){
            return;
        }

        if($currentTick % 20 === 0 || $this->viewers === []){
            $this->updateViewers();
        }

        if(self::canFit($this->world, $this->blockX, $this->blockY, $this->blockZ, $this->facing, $this->motive, $this)){
            $this->unsupportedTicks = 0;
        }elseif(++$this->unsupportedTicks >= self::BREAK_DELAY){
            $this->destroy(true);
        }
    }
    
    public function destroy(bool $drop) : void{
        if($this->closed){
            return;
        }

        $this->world->broadcastPacket($this->blockX, $this->blockZ, LevelEventPacket::create(LevelEvent::SOUND_ITEM_FRAME_BREAK, $this->position->x, $this->position->y, $this->position->z));

        if($drop && $this->world->getGameRules()->getBool(GameRules::DO_ENTITY_DROPS)){
            $this->world->dropItem($this->position, new Item(ItemIds::PAINTING));
        }

        $this->close();
    }

    public function distanceFrom(Vector3 $point) : float{
        return max(0.0, $point->distance($this->position) - max($this->motive->getWidth(), $this->motive->getHeight()) / 2);
    }

    private static function canFit(World $world, int $x, int $y, int $z, int $facing, PaintingMotive $motive, ?self $self) : bool{
        [$dx, , $dz] = Facing::offset($facing);
        [$lx, , $lz] = Facing::offset(Facing::rotateLeft($facing));
        [$left, $down] = self::starts($motive);

        for($w = -$left; $w < $motive->getWidth() - $left; ++$w){
            for($h = -$down; $h < $motive->getHeight() - $down; ++$h){
                [$bx, $by, $bz] = [$x + $lx * $w, $y + $h, $z + $lz * $w];

                if(!$world->isChunkLoaded($bx >> 4, $bz >> 4) || BlockCollision::isSolid($world->getBlock($bx, $by, $bz))){
                    return false;
                }

                $behind = $world->getBlock($bx - $dx, $by, $bz - $dz);

                if(!BlockCollision::isSolid($behind) && preg_match(self::SUPPORT, $behind->getName()) !== 1){
                    return false;
                }
            }
        }

        $box = self::boxOf($x, $y, $z, $facing, $motive);

        foreach($world->getEntities() as $entity){
            if($entity instanceof self && $entity !== $self && !$entity->isClosed() && self::intersects($box, self::boxOf($entity->blockX, $entity->blockY, $entity->blockZ, $entity->facing, $entity->motive))){
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{int, int}
     */
    private static function starts(PaintingMotive $motive) : array{
        return [intdiv($motive->getWidth() + 1, 2) - 1, intdiv($motive->getHeight() + 1, 2) - 1];
    }

    private static function centerOf(int $x, int $y, int $z, int $facing, PaintingMotive $motive) : Vector3{
        $box = self::boxOf($x, $y, $z, $facing, $motive);

        return new Vector3(($box[0] + $box[3]) / 2, ($box[1] + $box[4]) / 2, ($box[2] + $box[5]) / 2);
    }

    /**
     * @return array{float, float, float, float, float, float}
     */
    private static function boxOf(int $x, int $y, int $z, int $facing, PaintingMotive $motive) : array{
        [$dx, , $dz] = Facing::offset($facing);
        [$lx, , $lz] = Facing::offset(Facing::rotateLeft($facing));
        [$left, $down] = self::starts($motive);

        $x1 = $x - $lx * $left;
        $z1 = $z - $lz * $left;
        $x2 = $x + $lx * ($motive->getWidth() - 1 - $left);
        $z2 = $z + $lz * ($motive->getWidth() - 1 - $left);

        $minX = min($x1, $x2);
        $maxX = max($x1, $x2) + 1.0;
        $minZ = min($z1, $z2);
        $maxZ = max($z1, $z2) + 1.0;

        if($dx > 0){
            $maxX = $minX + self::THICKNESS;
        }elseif($dx < 0){
            $minX = $maxX - self::THICKNESS;
        }elseif($dz > 0){
            $maxZ = $minZ + self::THICKNESS;
        }else{
            $minZ = $maxZ - self::THICKNESS;
        }

        return [$minX, (float) ($y - $down), $minZ, $maxX, (float) ($y - $down + $motive->getHeight()), $maxZ];
    }

    /**
     * @param array{float, float, float, float, float, float} $a
     * @param array{float, float, float, float, float, float} $b
     */
    private static function intersects(array $a, array $b) : bool{
        return $a[0] < $b[3] && $a[3] > $b[0] && $a[1] < $b[4] && $a[4] > $b[1] && $a[2] < $b[5] && $a[5] > $b[2];
    }
}