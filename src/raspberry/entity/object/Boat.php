<?php

declare(strict_types=1);

namespace raspberry\entity\object;

use raspberry\block\BlockCollision;
use raspberry\block\BlockIds;
use raspberry\block\BlockProperties;

use raspberry\entity\Entity;
use raspberry\entity\EntityIds;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\math\Vector3;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\SetActorDataPacket;
use raspberry\network\mcpe\protocol\SetActorLinkPacket;
use raspberry\network\mcpe\protocol\types\EntityMetadata;

use raspberry\player\Player;

use raspberry\world\Chunk;
use raspberry\world\GameRules;
use raspberry\world\World;

use function abs;
use function array_search;
use function array_values;
use function ceil;
use function count;
use function cos;
use function deg2rad;
use function floor;
use function fmod;
use function max;
use function sin;

use const M_PI;

class Boat extends Entity{

    protected const array ITEMS = [
        ItemIds::OAK_BOAT,
        ItemIds::SPRUCE_BOAT,
        ItemIds::BIRCH_BOAT,
        ItemIds::JUNGLE_BOAT,
        ItemIds::ACACIA_BOAT,
        ItemIds::DARK_OAK_BOAT,
        ItemIds::MANGROVE_BOAT,
        ItemIds::BAMBOO_RAFT,
        ItemIds::CHERRY_BOAT,
        ItemIds::PALE_OAK_BOAT,
        ItemIds::POPLAR_BOAT
    ];

    public const int BAMBOO = 7;

    public const float WIDTH = 1.4;
    public const float HEIGHT = 0.455;

    public const float NETWORK_OFFSET = 0.375;

    public const int MAX_PASSENGERS = 2;

    private const string BUOYANCY = '{"apply_gravity":true,"base_buoyancy":1.0,"big_wave_probability":0.03,"big_wave_speed":10.0,"drag_down_on_buoyancy_removed":0.0,"liquid_blocks":["minecraft:water","minecraft:flowing_water"],"movement_type":"waves","simulate_waves":true}';

    public const float RIDER_ROTATION_LOCK = 90.0;
    public const float RIDER_ROTATION_OFFSET = -90.0;

    public const float PLAYER_SEAT_LIFT = 1.22001;

    private const float BREAK_DAMAGE = 40.0;

    private const int HURT_TICKS = 10;

    private const float LAVA_DAMAGE = 4.0;

    private const int UNDERWATER_EJECT_TICKS = 60;

    private const float MAX_DRIVER_STEP = 4.0;

    private const float GRAVITY = 0.04;

    private const float PADDLE_STEP = M_PI / 8;

    private const float STEP = 0.25;

    private int $variant;

    private Vector3 $motion;

    private bool $onGround = false;

    private float $damage = 0.0;

    private int $hurtTime = 0;

    private int $hurtDirection = 1;

    /** @var list<Player> front seat first */
    private array $passengers = [];

    private float $paddleLeft = 0.0;
    private float $paddleRight = 0.0;

    private int $underwaterTicks = 0;

    private bool $viewersSent = false;

    public function __construct(World $world, Vector3 $position, float $yaw, int $variant){
        $this->variant = $variant;
        $this->motion = Vector3::zero();
        $this->yaw = $yaw;

        parent::__construct($world, $position);
    }

    public static function variantOf(string $itemId) : ?int{
        $variant = array_search($itemId, static::ITEMS, true);
        return $variant === false ? null : $variant;
    }

    public static function place(World $world, Vector3 $position, float $playerYaw, int $variant) : ?static{
        if(self::boxBlocked($world, $position->x, $position->y, $position->z)){
            return null;
        }

        return new static($world, $position, fmod($playerYaw + 90.0, 360.0), $variant);
    }

    public static function fromSave(World $world, CompoundTag $tag) : ?static{
        [$x, $y, $z] = [$tag->getDouble("X"), $tag->getDouble("Y"), $tag->getDouble("Z")];
        $variant = $tag->getInt("Variant", 0) ?? 0;

        if($x === null || $y === null || $z === null || !isset(static::ITEMS[$variant])){
            return null;
        }

        return new static($world, new Vector3($x, $y, $z), $tag->getFloat("Yaw", 0.0) ?? 0.0, $variant);
    }

    public function save() : ?CompoundTag{
        if($this->closed){
            return null;
        }

        $tag = new CompoundTag();
        $tag->setString("identifier", $this->getNetworkTypeId());
        $tag->setDouble("X", $this->position->x);
        $tag->setDouble("Y", $this->position->y);
        $tag->setDouble("Z", $this->position->z);
        $tag->setFloat("Yaw", $this->yaw);
        $tag->setInt("Variant", $this->variant);
        return $tag;
    }

    public function getNetworkTypeId() : string{
        return EntityIds::BOAT;
    }

    public function getNetworkOffset() : float{
        return self::NETWORK_OFFSET;
    }

    public function getWidth() : float{
        return self::WIDTH;
    }

    public function getHeight() : float{
        return self::HEIGHT;
    }

    public function getVariant() : int{
        return $this->variant;
    }

    public function getMotion() : Vector3{
        return $this->motion;
    }

    public function setMotion(Vector3 $motion) : void{
        $this->motion = $motion;
    }

    public function isOnGround() : bool{
        return $this->onGround;
    }

    /**
     * @return list<Player>
     */
    public function getPassengers() : array{
        return $this->passengers;
    }

    public function getDriver() : ?Player{
        return $this->passengers[0] ?? null;
    }

    public function createMetadata() : EntityMetadata{
        $metadata = new EntityMetadata();
        $metadata->setFlag(EntityMetadata::FLAG_HAS_GRAVITY);
        $metadata->setFlag(EntityMetadata::FLAG_HAS_COLLISION);
        $metadata->setFlag(EntityMetadata::FLAG_STACKABLE);
        $metadata->setFlag(EntityMetadata::FLAG_COLLIDABLE);
        $metadata->setInt(EntityMetadata::KEY_HEALTH, (int) $this->damage);
        $metadata->setInt(EntityMetadata::KEY_VARIANT, $this->variant);
        $metadata->setInt(EntityMetadata::KEY_HURT_TIME, $this->hurtTime);
        $metadata->setInt(EntityMetadata::KEY_HURT_DIRECTION, $this->hurtDirection);
        $metadata->setFloat(EntityMetadata::KEY_PADDLE_TIME_LEFT, $this->paddleLeft);
        $metadata->setFloat(EntityMetadata::KEY_PADDLE_TIME_RIGHT, $this->paddleRight);
        $metadata->setFloat(EntityMetadata::KEY_SCALE, 1.0);
        $metadata->setFloat(EntityMetadata::KEY_WIDTH, self::WIDTH);
        $metadata->setFloat(EntityMetadata::KEY_HEIGHT, self::HEIGHT);
        $metadata->setByte(EntityMetadata::KEY_CONTROLLING_RIDER_SEAT, 0);
        $metadata->setByte(EntityMetadata::KEY_IS_BUOYANT, 1);
        $metadata->setString(EntityMetadata::KEY_BUOYANCY_DATA, self::BUOYANCY);

        return $metadata;
    }

    public function getSeat(Player $rider) : Vector3{
        $y = $this->variant === self::BAMBOO ? 0.1 : -0.2;

        if(count($this->passengers) < 2){
            return new Vector3(0.0, $y, 0.0);
        }

        return new Vector3($this->passengers[0] === $rider ? 0.2 : -0.6, $y, 0.0);
    }

    public function canBoard(Player $player) : bool{
        return !$this->closed && count($this->passengers) < static::MAX_PASSENGERS && $player->getVehicle() === null && !$this->isUnderwater() && $player->getWorld() === $this->world;
    }

    public function addPassenger(Player $player) : bool{
        if(!$this->canBoard($player)){
            return false;
        }

        $this->passengers[] = $player;
        $player->setVehicle($this);
        $this->sendLink($player, count($this->passengers) === 1 ? SetActorLinkPacket::TYPE_RIDER : SetActorLinkPacket::TYPE_PASSENGER);
        $this->updatePassengers();
        return true;
    }

    public function removePassenger(Player $player, ?Vector3 $at = null, bool $immediate = false) : void{
        $index = array_search($player, $this->passengers, true);

        if($index === false){
            return;
        }

        unset($this->passengers[$index]);
        $this->passengers = array_values($this->passengers);
        $player->setVehicle(null);
        $this->sendLink($player, SetActorLinkPacket::TYPE_REMOVE, $immediate);

        foreach($this->passengers as $seat => $rider){
            $this->sendLink($rider, $seat === 0 ? SetActorLinkPacket::TYPE_RIDER : SetActorLinkPacket::TYPE_PASSENGER);
        }

        $this->updatePassengers();

        if(!$player->isClosed() && $player->isAlive()){
            $player->teleport($this->findDismountSpot($at));
        }
    }

    public function removePassengers(bool $immediate = false) : void{
        foreach($this->passengers as $passenger){
            $this->removePassenger($passenger, null, $immediate);
        }
    }

    public function sendLinksTo(Player $viewer) : void{
        foreach($this->passengers as $seat => $rider){
            if($rider === $viewer || isset($rider->getViewers()[$viewer->getId()])){
                $viewer->getNetworkSession()->sendDataPacket(
                    SetActorLinkPacket::create(
                        $this->id,
                        $rider->getId(),
                        $seat === 0 ? SetActorLinkPacket::TYPE_RIDER : SetActorLinkPacket::TYPE_PASSENGER,
                        false,
                        true
                    )
                );
            }
        }
    }

    public function hurt(float $amount, ?Player $attacker = null) : void{
        if($this->closed){
            return;
        }

        $this->hurtDirection = -$this->hurtDirection;
        $this->hurtTime = self::HURT_TICKS;
        $this->damage += $amount * 10;
        $creative = $attacker !== null && $attacker->getGameMode()->hasInstantBuild();

        if($creative || $this->damage > self::BREAK_DAMAGE){
            $this->destroy(!$creative);
            return;
        }

        $this->broadcastMetadata();
    }

    public function destroy(bool $drop) : void{
        if($this->closed){
            return;
        }

        $this->removePassengers(true);

        if($drop && $this->world->getGameRules()->getBool(GameRules::DO_ENTITY_DROPS)){
            $this->world->dropItem($this->position->add(0, 0.5, 0), new Item(static::ITEMS[$this->variant]));
        }

        $this->close();
    }

    public function close() : void{
        if(!$this->closed){
            $this->removePassengers(true);
        }

        parent::close();
    }

    public function moveByDriver(Player $driver, Vector3 $position, float $yaw, bool $paddleLeft, bool $paddleRight) : bool{
        if($this->closed || $this->getDriver() !== $driver){
            return false;
        }

        if($position->distanceSquared($this->position) > self::MAX_DRIVER_STEP * self::MAX_DRIVER_STEP || $position->y < Chunk::MIN_Y - 64){
            $driver->getNetworkSession()->moveEntity($this, true);
            return false;
        }

        $this->motion = new Vector3($position->x - $this->position->x, $position->y - $this->position->y, $position->z - $this->position->z);
        $this->setPosition($position);
        $this->setRotation($yaw, 0.0);

        foreach($this->viewers as $viewer){
            if($viewer !== $driver){
                $viewer->getNetworkSession()->moveEntity($this);
            }
        }

        $left = $paddleLeft ? $this->paddleLeft + self::PADDLE_STEP : 0.0;
        $right = $paddleRight ? $this->paddleRight + self::PADDLE_STEP : 0.0;

        if($left !== $this->paddleLeft || $right !== $this->paddleRight){
            $this->paddleLeft = $left;
            $this->paddleRight = $right;
            $this->broadcastMetadata($driver);
        }

        $this->updatePassengers();
        return true;
    }

    public function tick(int $currentTick) : void{
        if($this->closed){
            return;
        }

        if($this->position->y < Chunk::MIN_Y - 64){
            $this->close();
            return;
        }

        if(!$this->world->isChunkLoaded($this->position->getChunkX(), $this->position->getChunkZ())){
            return;
        }

        if(!$this->viewersSent || $currentTick % 20 === 0){
            $this->viewersSent = true;
            $this->updateViewers();
        }

        foreach($this->passengers as $passenger){
            if($passenger->isClosed() || !$passenger->isAlive() || $passenger->getWorld() !== $this->world){
                $this->removePassenger($passenger, null, true);
            }
        }

        $changed = false;

        if($this->hurtTime > 0){
            --$this->hurtTime;
            $changed = true;
        }

        if($this->damage > 0){
            $this->damage = max(0.0, $this->damage - 1);
            $changed = true;
        }

        if($this->isInLava()){
            $this->hurt(self::LAVA_DAMAGE);

            if($this->closed){
                return;
            }
        }

        if($this->isUnderwater()){
            if(++$this->underwaterTicks >= self::UNDERWATER_EJECT_TICKS && $this->passengers !== []){
                $this->removePassengers();
            }
        }else{
            $this->underwaterTicks = 0;
        }

        if($changed){
            $this->broadcastMetadata();
        }

        if($this->getDriver() === null){
            $this->float();
            $this->updatePassengers();
        }
    }

    public function isUnderwater() : bool{
        $top = $this->position->y + self::HEIGHT + 0.001;
        $half = self::WIDTH / 2;

        for($x = (int) floor($this->position->x - $half); $x <= (int) floor($this->position->x + $half); ++$x){
            for($z = (int) floor($this->position->z - $half); $z <= (int) floor($this->position->z + $half); ++$z){
                $water = $this->waterTop($x, (int) floor($top), $z);

                if($water !== null && $water > $top){
                    return true;
                }
            }
        }

        return false;
    }

    private function float() : void{
        $waterLevel = $this->getWaterLevel();
        $motion = $this->motion;

        if($waterLevel !== null && $this->isUnderwater()){
            $motion = new Vector3($motion->x * 0.45, $motion->y * 0.9 + 0.01, $motion->z * 0.45);
        }else{
            $friction = $waterLevel === null && $this->onGround ? $this->getGroundFriction() : 0.9;
            $motion = new Vector3($motion->x * $friction, $motion->y - self::GRAVITY, $motion->z * $friction);

            if($waterLevel !== null && $waterLevel > $this->position->y){
                $lift = ($waterLevel - $this->position->y) / self::HEIGHT;
                $motion = new Vector3($motion->x, ($motion->y + $lift * 0.06153846) * 0.75, $motion->z);
            }
        }

        $this->motion = new Vector3(abs($motion->x) < 0.003 ? 0.0 : $motion->x, $motion->y, abs($motion->z) < 0.003 ? 0.0 : $motion->z);
        $this->step();
    }

    private function step() : void{
        $steps = (int) max(1, ceil($this->motion->length() / self::STEP));
        [$x, $y, $z] = [$this->position->x, $this->position->y, $this->position->z];
        [$dx, $dy, $dz] = [$this->motion->x / $steps, $this->motion->y / $steps, $this->motion->z / $steps];
        $ground = false;

        for($i = 0; $i < $steps; ++$i){
            if($dy !== 0.0){
                if(self::boxBlocked($this->world, $x, $y + $dy, $z)){
                    $ground = $ground || $dy < 0;
                    $y = $dy < 0 ? floor($y + $dy) + 1.0 : floor($y + $dy + self::HEIGHT) - self::HEIGHT;

                    if(self::boxBlocked($this->world, $x, $y, $z)){
                        $y = $this->position->y;
                    }

                    $dy = 0.0;
                }else{
                    $y += $dy;
                }
            }

            if($dx !== 0.0){
                if(self::boxBlocked($this->world, $x + $dx, $y, $z)){
                    $dx = 0.0;
                }else{
                    $x += $dx;
                }
            }

            if($dz !== 0.0){
                if(self::boxBlocked($this->world, $x, $y, $z + $dz)){
                    $dz = 0.0;
                }else{
                    $z += $dz;
                }
            }
        }

        $this->onGround = $ground;
        $this->motion = new Vector3($dx === 0.0 ? 0.0 : $this->motion->x, $dy === 0.0 ? 0.0 : $this->motion->y, $dz === 0.0 ? 0.0 : $this->motion->z);
        $this->move(new Vector3($x, $y, $z), $this->yaw, 0.0);
    }

    private function updatePassengers() : void{
        $yaw = deg2rad($this->yaw);

        foreach($this->passengers as $passenger){
            $seat = $this->getSeat($passenger);
            $passenger->setPosition(new Vector3(
                $this->position->x + $seat->x * cos($yaw),
                $this->position->y + $seat->y + self::NETWORK_OFFSET,
                $this->position->z + $seat->x * sin($yaw)
            ));
            $passenger->onVehicleMoved();
        }
    }

    private function findDismountSpot(?Vector3 $at) : Vector3{
        if($at !== null && $at->distanceSquared($this->position) <= 9.0 && !self::spaceBlocked($this->world, $at)){
            return $at;
        }

        $yaw = deg2rad($this->yaw);

        foreach([1, -1] as $side){
            $spot = new Vector3($this->position->x - sin($yaw) * $side * 1.2, $this->position->y, $this->position->z + cos($yaw) * $side * 1.2);

            if(!self::spaceBlocked($this->world, $spot) && self::spaceBlocked($this->world, $spot->add(0, -1, 0))){
                return $spot;
            }
        }

        return $this->position->add(0, self::HEIGHT, 0);
    }

    private function sendLink(Player $rider, int $type, bool $immediate = false) : void{
        $packet = SetActorLinkPacket::create($this->id, $rider->getId(), $type, $immediate, true);
        $rider->getNetworkSession()->sendDataPacket($packet);

        foreach($this->viewers as $viewer){
            if($viewer !== $rider && isset($rider->getViewers()[$viewer->getId()])){
                $viewer->getNetworkSession()->sendDataPacket($packet);
            }
        }
    }

    private function broadcastMetadata(?Player $except = null) : void{
        $packet = SetActorDataPacket::create($this->id, $this->createMetadata(), 0);

        foreach($this->viewers as $viewer){
            if($viewer !== $except){
                $viewer->getNetworkSession()->sendDataPacket($packet);
            }
        }
    }

    private function getWaterLevel() : ?float{
        $level = null;
        $half = self::WIDTH / 2;
        $bottom = $this->position->y;
        $y = (int) floor($bottom);

        for($x = (int) floor($this->position->x - $half); $x <= (int) floor($this->position->x + $half); ++$x){
            for($z = (int) floor($this->position->z - $half); $z <= (int) floor($this->position->z + $half); ++$z){
                for($by = $y; $by <= (int) floor($bottom + self::HEIGHT); ++$by){
                    $top = $this->waterTop($x, $by, $z);

                    if($top !== null && $top > $bottom){
                        $level = max($level ?? $top, $top);
                    }
                }
            }
        }

        return $level;
    }

    private function isInLava() : bool{
        $half = self::WIDTH / 2;

        for($x = (int) floor($this->position->x - $half); $x <= (int) floor($this->position->x + $half); ++$x){
            for($z = (int) floor($this->position->z - $half); $z <= (int) floor($this->position->z + $half); ++$z){
                $name = $this->world->getBlock($x, (int) floor($this->position->y + 0.1), $z)->getName();

                if($name === BlockIds::LAVA || $name === BlockIds::FLOWING_LAVA){
                    return true;
                }
            }
        }

        return false;
    }

    private function waterTop(int $x, int $y, int $z) : ?float{
        if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y || !$this->world->isChunkLoaded($x >> 4, $z >> 4)){
            return null;
        }

        $block = $this->world->getBlock($x, $y, $z);

        if($block->getName() !== BlockIds::WATER && $block->getName() !== BlockIds::FLOWING_WATER){
            return null;
        }

        $above = $this->world->getBlock($x, $y + 1, $z)->getName();

        if($above === BlockIds::WATER || $above === BlockIds::FLOWING_WATER){
            return $y + 1.0;
        }

        $depth = $block->hasProperty(BlockProperties::LIQUID_DEPTH) ? (int) $block->getProperty(BlockProperties::LIQUID_DEPTH) : 0;
        return $y + ($depth >= 8 ? 8 : 8 - $depth) / 9;
    }

    private function getGroundFriction() : float{
        $name = $this->world->getBlock($this->position->getFloorX(), (int) floor($this->position->y - 0.01), $this->position->getFloorZ())->getName();

        return match($name){
            BlockIds::ICE, BlockIds::PACKED_ICE, BlockIds::FROSTED_ICE => 0.98,
            BlockIds::BLUE_ICE => 0.989,
            BlockIds::SLIME => 0.8,
            default => 0.6
        };
    }

    private static function boxBlocked(World $world, float $x, float $y, float $z) : bool{
        $half = self::WIDTH / 2;

        for($bx = (int) floor($x - $half); $bx <= (int) floor($x + $half - 0.0001); ++$bx){
            for($by = (int) floor($y); $by <= (int) floor($y + self::HEIGHT - 0.0001); ++$by){
                for($bz = (int) floor($z - $half); $bz <= (int) floor($z + $half - 0.0001); ++$bz){
                    if($by < Chunk::MIN_Y || !$world->isChunkLoaded($bx >> 4, $bz >> 4) || BlockCollision::isSolid($world->getBlock($bx, $by, $bz))){
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function spaceBlocked(World $world, Vector3 $at) : bool{
        $x = $at->getFloorX();
        $z = $at->getFloorZ();

        for($y = (int) floor($at->y); $y <= (int) floor($at->y + 1.8); ++$y){
            if(!$world->isChunkLoaded($x >> 4, $z >> 4) || BlockCollision::isSolid($world->getBlock($x, $y, $z))){
                return true;
            }
        }

        return false;
    }
}