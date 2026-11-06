<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\block\BlockCollision;
use raspberry\block\BlockIds;

use raspberry\entity\effect\Effect;
use raspberry\entity\effect\EffectInstance;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\player\GameMode;

use raspberry\world\Chunk;
use raspberry\world\World;

use function abs;
use function in_array;

class Beacon extends BlockEntity implements TickableBlockEntity{

    public const MAX_LEVEL = 4;

    /** the beacon looks at its pyramid and gives its effects every 4 seconds */
    public const UPDATE_TICKS = 80;

    /** blocks a pyramid can be built from */
    private const PYRAMID_BLOCKS = [BlockIds::IRON_BLOCK, BlockIds::GOLD_BLOCK, BlockIds::EMERALD_BLOCK, BlockIds::DIAMOND_BLOCK, BlockIds::NETHERITE_BLOCK];

    private const PAYMENT_ITEMS = [ItemIds::IRON_INGOT, ItemIds::GOLD_INGOT, ItemIds::EMERALD, ItemIds::DIAMOND, ItemIds::NETHERITE_INGOT];

    /** the pyramid level each effect needs */
    private const PRIMARY_EFFECTS = [
        Effect::SPEED->value => 1,
        Effect::HASTE->value => 1,
        Effect::RESISTANCE->value => 2,
        Effect::JUMP_BOOST->value => 2,
        Effect::STRENGTH->value => 3
    ];

    private ?Effect $primary = null;

    private ?Effect $secondary = null;

    private int $level = 0;

    /** whether the level was worked out since the beacon was loaded */
    private bool $levelKnown = false;

    public function getSaveId() : string{
        return "Beacon";
    }

    public static function isPayment(Item $item) : bool{
        return in_array($item->getId(), self::PAYMENT_ITEMS, true);
    }

    public function getPrimary() : ?Effect{
        return $this->primary;
    }

    public function getSecondary() : ?Effect{
        return $this->secondary;
    }

    public function getLevel() : int{
        return $this->level;
    }

    /**
     * Whether a beacon of this level can give these effects. The secondary needs a full pyramid and
     * is either regeneration or the primary again, which makes the primary stronger.
     */
    public function canSelect(int $primary, int $secondary) : bool{
        if($primary === 0){
            return $secondary === 0;
        }

        if(!isset(self::PRIMARY_EFFECTS[$primary]) || self::PRIMARY_EFFECTS[$primary] > $this->level){
            return false;
        }

        return $secondary === 0 || ($this->level >= self::MAX_LEVEL && ($secondary === Effect::REGENERATION->value || $secondary === $primary));
    }

    public function setEffects(World $world, int $primary, int $secondary) : void{
        $this->primary = Effect::tryFrom($primary);
        $this->secondary = Effect::tryFrom($secondary);
        $this->setChanged();

        [$x, $y, $z] = [$this->getX(), $this->getY(), $this->getZ()];
        $world->sendBlockEntity($this);
        $world->broadcastPacket($x, $z, LevelSoundEventPacket::create(LevelSoundEvent::BEACON_POWER, $x + 0.5, $y + 0.5, $z + 0.5));
        $this->applyEffects($world);
    }

    /**
     * Works the pyramid level out again, playing the sound when the beacon turns on or off.
     */
    public function updateLevel(World $world) : void{
        $level = $this->isBeamBlocked($world) ? 0 : $this->countLevels($world);
        $old = $this->levelKnown ? $this->level : $level;
        $this->level = $level;
        $this->levelKnown = true;

        if(($old === 0) !== ($level === 0)){
            [$x, $y, $z] = [$this->getX(), $this->getY(), $this->getZ()];
            $world->broadcastPacket($x, $z, LevelSoundEventPacket::create($level > 0 ? LevelSoundEvent::BEACON_ACTIVATE : LevelSoundEvent::BEACON_DEACTIVATE, $x + 0.5, $y + 0.5, $z + 0.5));
        }
    }

    public function tick(World $world, int $currentTick) : void{
        if($currentTick % self::UPDATE_TICKS !== 0 || $world->getBlock($this->getX(), $this->getY(), $this->getZ())->getName() !== BlockIds::BEACON){
            return;
        }

        $this->updateLevel($world);
        $this->applyEffects($world);
    }

    public function readData(CompoundTag $nbt) : void{
        $this->primary = Effect::tryFrom($nbt->getInt("primary", 0) ?? 0);
        $this->secondary = Effect::tryFrom($nbt->getInt("secondary", 0) ?? 0);
    }

    public function writeData(CompoundTag $nbt) : void{
        $nbt->setInt("primary", $this->primary?->value ?? 0);
        $nbt->setInt("secondary", $this->secondary?->value ?? 0);
    }

    /**
     * Gives the effects to every player in range: 20 blocks out for a one layer pyramid, 10 more
     * for each layer, the whole height of the world.
     */
    private function applyEffects(World $world) : void{
        if($this->level === 0 || $this->primary === null || !$this->canSelect($this->primary->value, $this->secondary?->value ?? 0)){
            return;
        }

        $range = 10 + 10 * $this->level;
        $ticks = (9 + 2 * $this->level - ($this->level === self::MAX_LEVEL ? 1 : 0)) * 20;
        $strong = $this->secondary === $this->primary;
        $regeneration = $this->secondary === Effect::REGENERATION;

        foreach($world->getPlayers() as $player){
            $position = $player->getPosition();

            if($player->isDead() || !$player->isSpawned() || $player->getGameMode() === GameMode::SPECTATOR || abs($position->x - ($this->getX() + 0.5)) > $range || abs($position->z - ($this->getZ() + 0.5)) > $range){
                continue;
            }

            $player->getEffects()->add(new EffectInstance($this->primary, $ticks, $strong ? 1 : 0, true));

            if($regeneration){
                $player->getEffects()->add(new EffectInstance(Effect::REGENERATION, $ticks, 0, true));
            }
        }
    }

    /**
     * How many full layers of the pyramid are under the beacon.
     */
    private function countLevels(World $world) : int{
        [$x, $y, $z] = [$this->getX(), $this->getY(), $this->getZ()];

        for($level = 1; $level <= self::MAX_LEVEL; ++$level){
            $layerY = $y - $level;

            if($layerY < Chunk::MIN_Y){
                return $level - 1;
            }

            for($bx = $x - $level; $bx <= $x + $level; ++$bx){
                for($bz = $z - $level; $bz <= $z + $level; ++$bz){
                    if(!$world->isChunkLoaded($bx >> 4, $bz >> 4) || !in_array($world->getBlock($bx, $layerY, $bz)->getName(), self::PYRAMID_BLOCKS, true)){
                        return $level - 1;
                    }
                }
            }
        }

        return self::MAX_LEVEL;
    }

    /**
     * The beam needs a clear way up, see-through blocks like glass let it pass and so does bedrock.
     */
    private function isBeamBlocked(World $world) : bool{
        [$x, $z] = [$this->getX(), $this->getZ()];

        for($y = $this->getY() + 1; $y <= Chunk::MAX_Y; ++$y){
            $block = $world->getBlock($x, $y, $z);

            if($block->getName() !== BlockIds::BEDROCK && BlockCollision::isFullCube($block)){
                return true;
            }
        }

        return false;
    }
}