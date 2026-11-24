<?php

declare(strict_types=1);

namespace raspberry\entity\monster;

use raspberry\entity\EntityIds;
use raspberry\entity\Human;
use raspberry\entity\ai\Behavior;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\math\Vector3;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\types\EntityMetadata;

use raspberry\player\GameMode;

use raspberry\world\World;
use raspberry\world\biome\BiomeTags;

use function abs;
use function count;
use function in_array;
use function intdiv;
use function mt_getrandmax;
use function mt_rand;

class Slime extends Monster{

    public const int SMALL = 1;
    public const int MEDIUM = 2;
    public const int LARGE = 4;

    public const float FOLLOW_RANGE = 16.0;

    private const array SIZES = [self::SMALL, self::MEDIUM, self::LARGE];

    private const array HEALTH = [self::SMALL => 1.0, self::MEDIUM => 4.0, self::LARGE => 16.0];
    private const array SPEED = [self::SMALL => 0.3, self::MEDIUM => 0.4, self::LARGE => 0.6];
    private const array DAMAGE = [self::SMALL => 0.0, self::MEDIUM => 2.0, self::LARGE => 4.0];

    private const float BASE_SIZE = 0.52;

    private const float ATTACK_RANGE = 0.15;
    private const int ATTACK_COOLDOWN = 10;

    private const int SLIME_CHUNK_MAX_Y = 40;
    private const int SWAMP_MIN_Y = 50;
    private const int SWAMP_MAX_Y = 70;
    private const array MOON_BRIGHTNESS = [1.0, 0.75, 0.5, 0.25, 0.0, 0.25, 0.5, 0.75];

    private const int SLIME_CHUNK_CACHE = 4096;

    /** @var array<string, bool> */
    private static array $slimeChunks = [];

    private int $size;

    private int $attackCooldown = 0;

    public function __construct(World $world, Vector3 $position, float $yaw = 0.0, ?int $size = null){
        $this->size = $size ?? self::SIZES[mt_rand(0, count(self::SIZES) - 1)];
        parent::__construct($world, $position, $yaw);
    }

    public function getNetworkTypeId() : string{
        return EntityIds::SLIME;
    }

    public function getSize() : int{
        return $this->size;
    }

    public function setSize(int $size) : void{
        if(!in_array($size, self::SIZES, true)){
            return;
        }

        $this->size = $size;
        $this->updateEffectAttributes();
        $this->setHealth($this->getMaxHealth());
        $this->world->getMobEngine()->update($this);
        $this->sendMetadata();
    }

    protected function getBaseWidth() : float{
        return self::BASE_SIZE * $this->size;
    }

    protected function getBaseHeight() : float{
        return self::BASE_SIZE * $this->size;
    }

    protected function getDefaultMaxHealth() : float{
        return self::HEALTH[$this->size];
    }

    protected function getDefaultMovementSpeed() : float{
        return self::SPEED[$this->size];
    }

    protected function getAttackDamage() : float{
        return self::DAMAGE[$this->size];
    }

    protected function getAiFlags() : int{
        return self::AI_JUMPER;
    }

    public function getBehaviors() : array{
        return [
            Behavior::float(1),
            Behavior::nearestAttackableTarget(2, self::FOLLOW_RANGE),
            Behavior::slimeAttack(3),
            Behavior::slimeRandomDirection(4)
        ];
    }

    public function createMetadata() : EntityMetadata{
        $metadata = parent::createMetadata();
        $metadata->setInt(EntityMetadata::KEY_VARIANT, $this->size);
        return $metadata;
    }

    public function getXpReward() : int{
        return $this->size;
    }

    protected function getDrops(int $looting) : array{
        if($this->size !== self::SMALL){
            return [];
        }

        $count = self::lootCount(0, 2, $looting);
        return $count > 0 ? [new Item(ItemIds::SLIME_BALL, $count)] : [];
    }

    public function tick(int $currentTick) : void{
        parent::tick($currentTick);

        if($this->closed || !$this->isAlive() || $this->getAttackDamage() <= 0.0){
            return;
        }

        if($this->attackCooldown > 0){
            --$this->attackCooldown;
            return;
        }

        $reach = $this->getWidth() / 2 + self::ATTACK_RANGE + Human::WIDTH / 2;

        foreach($this->world->getPlayers() as $player){
            if(!$player->isAlive() || $player->getGameMode() === GameMode::CREATIVE || $player->getGameMode() === GameMode::SPECTATOR){
                continue;
            }

            $at = $player->getPosition();

            if(abs($at->x - $this->position->x) > $reach || abs($at->z - $this->position->z) > $reach || $at->y > $this->position->y + $this->getHeight() + self::ATTACK_RANGE || $at->y + Human::HEIGHT < $this->position->y - self::ATTACK_RANGE){
                continue;
            }

            $this->onAttack($player);
            $this->attackCooldown = self::ATTACK_COOLDOWN;
            return;
        }
    }

    public function close() : void{
        if(!$this->closed && !$this->isAlive() && $this->size > self::SMALL){
            $this->split();
        }

        parent::close();
    }

    private function split() : void{
        $size = intdiv($this->size, 2);
        $count = mt_rand(2, 4);
        $spread = $this->size / 4;

        for($i = 0; $i < $count; ++$i){
            $offsetX = ($i % 2 - 0.5) * $spread;
            $offsetZ = (intdiv($i, 2) - 0.5) * $spread;

            $child = new self($this->world, $this->position->add($offsetX, 0.5, $offsetZ), mt_rand() / mt_getrandmax() * 360.0, $size);
            $child->setPersistent($this->isPersistent());
        }
    }

    public static function canSpawnNaturally(World $world, int $x, int $y, int $z, bool $roll) : bool{
        $biome = $world->getBiome($x, $y, $z);

        if(($biome->hasTag(BiomeTags::SWAMP) || $biome->hasTag(BiomeTags::MANGROVE_SWAMP)) && $y > self::SWAMP_MIN_Y && $y < self::SWAMP_MAX_Y){
            if(!$roll || (mt_rand(0, 1) === 0 && mt_rand() / mt_getrandmax() < self::MOON_BRIGHTNESS[$world->getMoonPhase()] && $world->getLight($x, $y, $z) <= mt_rand(0, 7))){
                return true;
            }
        }

        return $y < self::SLIME_CHUNK_MAX_Y && self::isSlimeChunk($x >> 4, $z >> 4);
    }

    public static function isSlimeChunk(int $chunkX, int $chunkZ) : bool{
        $key = $chunkX . ":" . $chunkZ;

        if(isset(self::$slimeChunks[$key])){
            return self::$slimeChunks[$key];
        }

        if(count(self::$slimeChunks) >= self::SLIME_CHUNK_CACHE){
            self::$slimeChunks = [];
        }

        return self::$slimeChunks[$key] = self::firstTwister((($chunkX * 0x1f1f1f1f) & 0xffffffff) ^ ($chunkZ & 0xffffffff)) % 10 === 0;
    }

    private static function firstTwister(int $seed) : int{
        $state = [$seed];

        for($i = 1; $i <= 397; ++$i){
            $previous = $state[$i - 1];
            $state[$i] = (1812433253 * ($previous ^ ($previous >> 30)) + $i) & 0xffffffff;
        }

        $mixed = ($state[0] & 0x80000000) | ($state[1] & 0x7fffffff);
        $value = $state[397] ^ ($mixed >> 1) ^ (($mixed & 1) !== 0 ? 0x9908b0df : 0);

        $value ^= $value >> 11;
        $value ^= ($value << 7) & 0x9d2c5680;
        $value ^= ($value << 15) & 0xefc60000;
        $value ^= $value >> 18;
        return $value & 0xffffffff;
    }

    public function save() : ?CompoundTag{
        $tag = parent::save();
        $tag?->setInt("Variant", $this->size);
        return $tag;
    }

    public function load(CompoundTag $tag) : void{
        $size = $tag->getInt("Variant", $this->size);

        if(in_array($size, self::SIZES, true) && $size !== $this->size){
            $this->size = $size;
            $this->updateEffectAttributes();
        }

        parent::load($tag);
    }
}