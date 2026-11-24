<?php

declare(strict_types=1);

namespace raspberry\entity\monster;

use raspberry\Server;

use raspberry\block\BlockCollision;
use raspberry\block\BlockNBT;
use raspberry\block\BlockState;
use raspberry\block\BlockSupport;

use raspberry\entity\EntityIds;
use raspberry\entity\Living;
use raspberry\entity\ai\Behavior;
use raspberry\entity\damage\DamageSource;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\math\Vector3;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\EntityMetadata;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\player\GameMode;
use raspberry\player\Player;

use raspberry\world\GameRules;

use function cos;
use function deg2rad;
use function floor;
use function in_array;
use function mt_getrandmax;
use function mt_rand;
use function preg_match;
use function sin;
use function sqrt;
use function substr;

class Enderman extends Monster{

    public const float FOLLOW_RANGE = 64.0;

    private const float CALM_SPEED = 0.3;
    private const float ANGRY_SPEED = 0.45;

    private const int ANGER_TICKS = 500;

    private const float STARE_RANGE = 64.0;
    private const int STARE_TICKS = 5;
    private const float STARE_CLOSE = 4.0;

    private const int RANDOM_TELEPORT_TICKS = 600;
    private const float TELEPORT_CUBE = 32.0;
    private const int TELEPORT_TRIES = 64;
    private const float TARGET_DISTANCE = 16.0;
    private const float TARGET_TELEPORT_CHANCE = 0.05;
    private const float LIGHT_TELEPORT_CHANCE = 0.05;
    private const int HURT_TELEPORT_CHANCE = 10;

    private const float TAKE_CHANCE = 0.05;
    private const float PLACE_CHANCE = 0.0005;

    private const array HOLDABLE = [
        "dirt", "grass_block", "podzol", "coarse_dirt", "mycelium", "dirt_with_roots", "moss_block", "pale_moss_block", "muddy_mangrove_roots", "mud",
        "sand", "red_sand", "gravel", "brown_mushroom", "red_mushroom", "tnt", "cactus", "cactus_flower", "clay", "pumpkin", "carved_pumpkin",
        "melon_block", "crimson_fungus", "crimson_nylium", "crimson_roots", "warped_fungus", "warped_nylium", "warped_roots", "dandelion",
        "open_eyeblossom", "closed_eyeblossom", "poppy", "blue_orchid", "allium", "azure_bluet", "red_tulip", "orange_tulip", "white_tulip",
        "pink_tulip", "oxeye_daisy", "cornflower", "lily_of_the_valley", "wither_rose", "torchflower", "golden_dandelion"
    ];

    private const string LIQUID = '/^minecraft:(flowing_)?(water|lava)$|^minecraft:bubble_column$/D';

    private bool $angry = false;

    private int $angerTicks = 0;

    private int $teleportTicks = 0;

    /** @var array<int, int> */
    private array $stares = [];

    private ?BlockState $carried = null;

    public function getNetworkTypeId() : string{
        return EntityIds::ENDERMAN;
    }

    protected function getBaseWidth() : float{
        return 0.6;
    }

    protected function getBaseHeight() : float{
        return 2.9;
    }

    public function getEyeHeight() : float{
        return 2.55;
    }

    protected function getDefaultMaxHealth() : float{
        return 40.0;
    }

    protected function getDefaultMovementSpeed() : float{
        return $this->angry ? self::ANGRY_SPEED : self::CALM_SPEED;
    }

    protected function getAttackDamage() : float{
        return 7.0;
    }

    protected function getAiFlags() : int{
        return self::AI_AVOID_WATER | ($this->angry ? 0 : self::AI_CALM);
    }

    public function getBehaviors() : array{
        return [
            Behavior::float(0),
            Behavior::meleeAttack(2, 1.0),
            Behavior::hurtByTarget(3, self::FOLLOW_RANGE),
            Behavior::randomStroll(7, 1.0),
            Behavior::lookAtPlayer(8, 8.0),
            Behavior::randomLookAround(8)
        ];
    }

    public function isAngry() : bool{
        return $this->angry;
    }

    public function getCarriedBlock() : ?BlockState{
        return $this->carried;
    }

    public function setCarriedBlock(?BlockState $block) : void{
        $this->carried = $block;
        $this->sendMetadata();
    }

    public function createMetadata() : EntityMetadata{
        $metadata = parent::createMetadata();
        $metadata->setFlag(EntityMetadata::FLAG_ANGRY, $this->angry);
        $metadata->setInt(EntityMetadata::KEY_CARRY_BLOCK_STATE, $this->carried === null ? 0 : Server::getInstance()->getBlockTranslator()->getNetworkId($this->carried));
        return $metadata;
    }

    public function provoke(Player $player) : void{
        $this->angerTicks = self::ANGER_TICKS;

        if(!$this->angry){
            $this->setAngry(true);
        }

        $this->world->getMobEngine()->hurt($this, $player);
    }

    private function setAngry(bool $angry) : void{
        $this->angry = $angry;
        $this->updateEffectAttributes();
        $this->world->getMobEngine()->update($this);
        $this->sendMetadata();
    }

    public function onEngineEvent(int $kind, float $value, int $x, int $y, int $z, int $other) : void{
        parent::onEngineEvent($kind, $value, $x, $y, $z, $other);

        if($kind === self::EVENT_TARGET && $this->target !== null){
            $this->angerTicks = self::ANGER_TICKS;

            if(!$this->angry){
                $this->setAngry(true);
            }
        }
    }

    public function damage(DamageSource $source) : bool{
        if($source->getCause() === DamageSource::CAUSE_PROJECTILE && $this->isAlive()){
            for($i = 0; $i < self::TELEPORT_TRIES; ++$i){
                if($this->teleportRandomly()){
                    break;
                }
            }

            return false;
        }

        $attacker = $source->getAttacker();

        if($attacker instanceof Player){
            $this->angerTicks = self::ANGER_TICKS;

            if(!$this->angry){
                $this->setAngry(true);
            }
        }

        $damaged = parent::damage($source);

        if($damaged && $this->isAlive() && !$attacker instanceof Living && mt_rand(0, self::HURT_TELEPORT_CHANCE - 1) !== 0){
            $this->teleportRandomly();
        }

        return $damaged;
    }

    public function tick(int $currentTick) : void{
        parent::tick($currentTick);

        if($this->closed || !$this->isAlive()){
            return;
        }

        if($this->isWet()){
            $this->damage(new DamageSource(DamageSource::CAUSE_DROWNING, 1.0));

            if($this->closed || !$this->isAlive()){
                return;
            }
        }

        $this->tickAnger();
        $this->tickTeleport();

        if(!$this->closed && $this->world->getGameRules()->getBool(GameRules::MOB_GRIEFING)){
            $this->carried === null ? $this->tryTake() : $this->tryPlace();
        }
    }

    private function isWet() : bool{
        $x = $this->position->getFloorX();
        $y = $this->position->getFloorY();
        $z = $this->position->getFloorZ();

        return $this->isInWater() || $this->world->isRainingAt($x, (int) floor($this->position->y + $this->getHeight()), $z) || preg_match('/^minecraft:bubble_column$/D', $this->world->getBlock($x, $y, $z)->getName()) === 1;
    }

    private function tickAnger() : void{
        $target = $this->getTarget();

        if($this->angry){
            if($target !== null){
                $this->angerTicks = self::ANGER_TICKS;
            }elseif(--$this->angerTicks <= 0){
                $this->setAngry(false);
                $this->stares = [];
            }

            return;
        }

        foreach($this->world->getPlayers() as $player){
            $id = $player->getId();

            if(!$this->isStaredAtBy($player)){
                unset($this->stares[$id]);
                continue;
            }

            $this->stares[$id] = ($this->stares[$id] ?? 0) + 1;

            if($this->stares[$id] < self::STARE_TICKS){
                continue;
            }

            unset($this->stares[$id]);
            $this->playSound(LevelSoundEvent::STARE);
            $this->provoke($player);

            if($player->getPosition()->distanceSquared($this->position) < self::STARE_CLOSE ** 2){
                $this->teleportRandomly();
            }

            return;
        }
    }

    private function isStaredAtBy(Player $player) : bool{
        if(!$player->isSpawned() || !$player->isAlive() || $player->getGameMode() === GameMode::CREATIVE || $player->getGameMode() === GameMode::SPECTATOR){
            return false;
        }

        if($player->getArmorInventory()->getHelmet()->getId() === ItemIds::CARVED_PUMPKIN){
            return false;
        }

        $eye = $player->getEyePosition();
        $dx = $this->position->x - $eye->x;
        $dy = $this->position->y + $this->getEyeHeight() - $eye->y;
        $dz = $this->position->z - $eye->z;
        $distance = sqrt($dx * $dx + $dy * $dy + $dz * $dz);

        if($distance > self::STARE_RANGE || $distance < 0.001){
            return false;
        }

        $yaw = deg2rad($player->getYaw());
        $pitch = deg2rad($player->getPitch());
        $dot = (-sin($yaw) * cos($pitch) * $dx - sin($pitch) * $dy + cos($yaw) * cos($pitch) * $dz) / $distance;

        return $dot > 1.0 - 0.025 / $distance && $this->hasClearView($eye, $distance, $dx, $dy, $dz);
    }

    private function hasClearView(Vector3 $from, float $distance, float $dx, float $dy, float $dz) : bool{
        $steps = (int) ($distance * 4);

        for($i = 1; $i < $steps; ++$i){
            $t = $i / $steps;
            $x = (int) floor($from->x + $dx * $t);
            $y = (int) floor($from->y + $dy * $t);
            $z = (int) floor($from->z + $dz * $t);

            if(!$this->world->isChunkLoaded($x >> 4, $z >> 4) || BlockCollision::isSolid($this->world->getBlock($x, $y, $z))){
                return false;
            }
        }

        return true;
    }

    private function tickTeleport() : void{
        $target = $this->getTarget();

        if($target !== null && $target->getPosition()->distanceSquared($this->position) > self::TARGET_DISTANCE ** 2 && mt_rand() / mt_getrandmax() < self::TARGET_TELEPORT_CHANCE){
            if($this->teleportTowards($target->getPosition())){
                return;
            }
        }

        if($this->world->isDaytime() && $this->getBrightness() > 0.5 && mt_rand() / mt_getrandmax() < self::LIGHT_TELEPORT_CHANCE){
            $x = $this->position->getFloorX();
            $z = $this->position->getFloorZ();

            if($this->world->canSeeSky($x, (int) floor($this->position->y + $this->getEyeHeight()), $z) && $this->teleportRandomly()){
                return;
            }
        }

        if(--$this->teleportTicks <= 0){
            $this->teleportTicks = mt_rand(0, self::RANDOM_TELEPORT_TICKS);

            if($this->ticksLived > 1){
                $this->teleportRandomly();
            }
        }
    }

    public function teleportRandomly() : bool{
        $half = self::TELEPORT_CUBE / 2;

        return $this->teleportTo(
            $this->position->x + (mt_rand() / mt_getrandmax() - 0.5) * self::TELEPORT_CUBE,
            $this->position->y + mt_rand((int) -$half, (int) $half),
            $this->position->z + (mt_rand() / mt_getrandmax() - 0.5) * self::TELEPORT_CUBE
        );
    }

    private function teleportTowards(Vector3 $target) : bool{
        $dx = $this->position->x - $target->x;
        $dy = $this->position->y + $this->getHeight() / 2 - $target->y;
        $dz = $this->position->z - $target->z;
        $length = sqrt($dx * $dx + $dy * $dy + $dz * $dz);

        if($length < 0.001){
            return false;
        }

        return $this->teleportTo(
            $this->position->x + (mt_rand() / mt_getrandmax() - 0.5) * 8.0 - $dx / $length * self::TARGET_DISTANCE,
            $this->position->y + mt_rand(-8, 7) - $dy / $length * self::TARGET_DISTANCE,
            $this->position->z + (mt_rand() / mt_getrandmax() - 0.5) * 8.0 - $dz / $length * self::TARGET_DISTANCE
        );
    }

    private function teleportTo(float $x, float $y, float $z) : bool{
        $bx = (int) floor($x);
        $by = (int) floor($y);
        $bz = (int) floor($z);

        if(!$this->world->isChunkLoaded($bx >> 4, $bz >> 4)){
            return false;
        }

        while($by > -64 && !BlockCollision::isSolid($this->world->getBlock($bx, $by - 1, $bz))){
            --$by;
        }

        if($by <= -64 || preg_match(self::LIQUID, $this->world->getBlock($bx, $by - 1, $bz)->getName()) === 1 || !$this->hasRoomAt($x, $by, $z)){
            return false;
        }

        $from = $this->position;
        $this->teleport(new Vector3($x, $by, $z));
        $this->world->broadcastPacket($from->getFloorX(), $from->getFloorZ(), $this->createSound($from));
        $this->world->broadcastPacket($bx, $bz, $this->createSound($this->position));
        return true;
    }

    private function createSound(Vector3 $at) : LevelSoundEventPacket{
        $packet = LevelSoundEventPacket::create(LevelSoundEvent::TELEPORT, $at->x, $at->y, $at->z);
        $packet->entityType = $this->getNetworkTypeId();
        return $packet;
    }

    private function hasRoomAt(float $x, int $y, float $z) : bool{
        $half = $this->getWidth() / 2;

        for($bx = (int) floor($x - $half); $bx <= (int) floor($x + $half); ++$bx){
            for($bz = (int) floor($z - $half); $bz <= (int) floor($z + $half); ++$bz){
                for($by = $y; $by < $y + (int) floor($this->getHeight() + 1); ++$by){
                    if(!$this->world->isChunkLoaded($bx >> 4, $bz >> 4)){
                        return false;
                    }

                    $block = $this->world->getBlock($bx, $by, $bz);

                    if(BlockCollision::isSolid($block) || preg_match(self::LIQUID, $block->getName()) === 1){
                        return false;
                    }
                }
            }
        }

        return true;
    }

    private function tryTake() : void{
        if(mt_rand() / mt_getrandmax() >= self::TAKE_CHANCE){
            return;
        }

        $x = $this->position->getFloorX() + mt_rand(-2, 2);
        $y = $this->position->getFloorY() + mt_rand(0, 3);
        $z = $this->position->getFloorZ() + mt_rand(-2, 2);

        if(!$this->world->isChunkLoaded($x >> 4, $z >> 4)){
            return;
        }

        $block = $this->world->getBlock($x, $y, $z);

        if(!in_array(substr($block->getName(), 10), self::HOLDABLE, true)){
            return;
        }

        $eye = $this->getEyePosition();
        $dx = $x + 0.5 - $eye->x;
        $dy = $y + 0.5 - $eye->y;
        $dz = $z + 0.5 - $eye->z;
        $distance = sqrt($dx * $dx + $dy * $dy + $dz * $dz);

        if(!$this->reaches($eye, $distance, $dx, $dy, $dz, $x, $y, $z)){
            return;
        }

        $this->world->setBlock($x, $y, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState("minecraft:air"));
        $this->setCarriedBlock($block);
    }

    private function reaches(Vector3 $from, float $distance, float $dx, float $dy, float $dz, int $x, int $y, int $z) : bool{
        $steps = (int) ($distance * 4);

        for($i = 1; $i < $steps; ++$i){
            $t = $i / $steps;
            $bx = (int) floor($from->x + $dx * $t);
            $by = (int) floor($from->y + $dy * $t);
            $bz = (int) floor($from->z + $dz * $t);

            if(($bx !== $x || $by !== $y || $bz !== $z) && BlockCollision::isSolid($this->world->getBlock($bx, $by, $bz))){
                return false;
            }
        }

        return true;
    }

    private function tryPlace() : void{
        if($this->carried === null || mt_rand() / mt_getrandmax() >= self::PLACE_CHANCE){
            return;
        }

        $x = $this->position->getFloorX() + mt_rand(-1, 1);
        $y = $this->position->getFloorY() + mt_rand(0, 2);
        $z = $this->position->getFloorZ() + mt_rand(-1, 1);

        if(!$this->world->isChunkLoaded($x >> 4, $z >> 4) || $this->world->getBlock($x, $y, $z)->getName() !== "minecraft:air"){
            return;
        }

        $below = $this->world->getBlock($x, $y - 1, $z);

        if(!BlockCollision::isFullCube($below) || $below->getName() === "minecraft:bedrock" || !BlockSupport::canStay($this->world, $x, $y, $z, $this->carried, true)){
            return;
        }

        $this->world->setBlock($x, $y, $z, $this->carried);
        $this->setCarriedBlock(null);
    }

    protected function getDrops(int $looting) : array{
        $drops = [];
        $pearls = self::lootCount(0, 1, $looting);

        if($pearls > 0){
            $drops[] = new Item(ItemIds::ENDER_PEARL, $pearls);
        }

        if($this->carried !== null){
            $drops[] = new Item($this->carried->getName());
        }

        return $drops;
    }

    public function save() : ?CompoundTag{
        $tag = parent::save();

        if($this->carried !== null){
            $tag?->setTag("carriedBlock", BlockNBT::write($this->carried));
        }

        return $tag;
    }

    public function load(CompoundTag $tag) : void{
        parent::load($tag);
        $this->carried = BlockNBT::read($tag->getCompoundTag("carriedBlock"));
    }
}