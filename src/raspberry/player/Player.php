<?php

declare(strict_types=1);

namespace raspberry\player;

use raspberry\entity\Human;
use raspberry\entity\Skin;
use raspberry\entity\damage\DamageSource;

use raspberry\nbt\tag\CompoundTag;
use raspberry\nbt\tag\DoubleTag;
use raspberry\nbt\tag\FloatTag;
use raspberry\nbt\tag\ListTag;

use raspberry\block\BlockIds;

use raspberry\command\CommandSender;

use raspberry\math\Vector3;

use raspberry\network\mcpe\auth\AuthenticatedLogin;
use raspberry\network\mcpe\NetworkSession;
use raspberry\network\mcpe\protocol\ActorEventPacket;
use raspberry\network\mcpe\protocol\SetTitlePacket;
use raspberry\network\mcpe\protocol\TextPacket;
use raspberry\network\mcpe\protocol\ToastRequestPacket;

use raspberry\permission\PermissionLevel;
use raspberry\permission\PermissibleTrait;
use raspberry\permission\PermissionManager;

use raspberry\utils\Uuid;

use raspberry\world\Chunk;
use raspberry\world\World;
use raspberry\world\WorldManager;

use function ceil;
use function floor;
use function is_int;
use function max;
use function min;
use function microtime;
use function sqrt;

class Player extends Human implements CommandSender{
    use PermissibleTrait;

    /** players this far below the bottom of the world (Y -104) are hurt by the void */
    public const VOID_DEPTH = 40;

    /** 4 health (2 hearts) every half second */
    public const VOID_DAMAGE = 4.0;

    private const VOID_DAMAGE_TICKS = 10;

    /** falls up to this many blocks do no damage */
    public const SAFE_FALL_DISTANCE = 3.0;

    private NetworkSession $session;

    private AuthenticatedLogin $identity;

    private GameMode $gameMode;

    private bool $spawned = false;

    private bool $flying = false;

    private bool $dead = false;

    private bool $swimming = false;

    private bool $sprinting = false;

    /** blocks fallen since the player last stood on something */
    private float $fallDistance = 0.0;

    private int $lastVoidDamageTick = 0;

    /** translation key of the death message for the next death, e.g. "death.attack.outOfWorld" */
    private ?string $deathCause = null;

    /** personal spawn point (/spawnpoint), null to use the world spawn */
    private ?Vector3 $spawnPosition = null;

    private ?string $spawnWorldName = null;

    /** when the player first joined */
    private int $firstPlayed;

    public function __construct(World $world, Vector3 $position, NetworkSession $session, AuthenticatedLogin $identity, GameMode $gameMode, Skin $skin){
        $this->session = $session;
        $this->identity = $identity;
        $this->gameMode = $gameMode;
        $this->flying = $gameMode->forcesFlying();
        $this->firstPlayed = (int) (microtime(true) * 1000);

        parent::__construct($world, $position, $skin, Uuid::fromXuid($identity->xuid));
    }

    /**
     * @return array{World, Vector3, float, float}|null
     */
    public static function getSavedLocation(CompoundTag $data, WorldManager $worldManager) : ?array{
        $position = $data->getListTag("Pos");
        $rotation = $data->getListTag("Rotation");
        $world = $worldManager->getWorld($data->getString("World", ""));

        if($world === null || $position === null || $position->count() !== 3 || $position->getTagType() !== DoubleTag::TAG_DOUBLE){
            return null;
        }

        [$x, $y, $z] = $position->getAllValues();
        [$yaw, $pitch] = $rotation !== null && $rotation->count() === 2 && $rotation->getTagType() === FloatTag::TAG_FLOAT ? $rotation->getAllValues() : [0.0, 0.0];

        return [$world, new Vector3($x, $y, $z), $yaw, $pitch];
    }

    public function loadData(CompoundTag $data) : void{
        $this->gameMode = GameMode::tryFrom($data->getInt("PlayerGameType", $this->gameMode->value)) ?? $this->gameMode;
        $this->flying = $this->gameMode->forcesFlying() || ($this->gameMode->allowsFlying() && $data->getByte("Flying", 0) === 1);

        $this->health = max(0.0, min($data->getFloat("Health", $this->maxHealth), $this->maxHealth));
        $this->absorption = max(0.0, $data->getFloat("AbsorptionAmount", 0.0));
        $this->food = max(0, min($data->getInt("FoodLevel", self::MAX_FOOD), self::MAX_FOOD));
        $this->saturation = max(0.0, min($data->getFloat("FoodSaturationLevel", self::DEFAULT_SATURATION), $this->food));
        $this->exhaustion = max(0.0, min($data->getFloat("FoodExhaustionLevel", 0.0), self::MAX_EXHAUSTION));

        if($this->health <= 0){
            $this->resetVitals();
        }

        $spawnWorld = $data->getString("SpawnWorld");

        if($spawnWorld !== null){
            $this->spawnWorldName = $spawnWorld;
            $this->spawnPosition = new Vector3($data->getInt("SpawnX", 0) + 0.5, (float) $data->getInt("SpawnY", 0), $data->getInt("SpawnZ", 0) + 0.5);
        }

        $this->firstPlayed = $data->getLong("FirstPlayed", $this->firstPlayed);
        $this->foodTickTimer = max(1, min($data->getInt("FoodTickTimer", 1), 80));
        $this->fallDistance = max(0.0, $data->getFloat("FallDistance", 0.0));
    }

    public function saveData() : CompoundTag{
        [$world, $position] = $this->dead ? $this->getSpawnLocation() : [$this->world, $this->position];

        $data = (new CompoundTag())
            ->setInt("DataVersion", 1)
            ->setString("Name", $this->getName())
            ->setLong("FirstPlayed", $this->firstPlayed)
            ->setLong("LastPlayed", (int) (microtime(true) * 1000))
            ->setString("World", $world->getName())
            ->setTag("Pos", new ListTag([new DoubleTag($position->x), new DoubleTag($position->y), new DoubleTag($position->z)]))
            ->setTag("Rotation", new ListTag([new FloatTag($this->yaw), new FloatTag($this->pitch)]))
            ->setInt("PlayerGameType", $this->gameMode->value)
            ->setByte("Flying", $this->flying ? 1 : 0)
            ->setFloat("Health", $this->dead ? $this->maxHealth : $this->health)
            ->setFloat("AbsorptionAmount", $this->dead ? 0.0 : $this->absorption)
            ->setInt("FoodLevel", $this->dead ? self::MAX_FOOD : $this->food)
            ->setFloat("FoodSaturationLevel", $this->dead ? self::DEFAULT_SATURATION : $this->saturation)
            ->setFloat("FoodExhaustionLevel", $this->dead ? 0.0 : $this->exhaustion)
            ->setInt("FoodTickTimer", $this->dead ? 1 : $this->foodTickTimer)
            ->setFloat("FallDistance", $this->dead ? 0.0 : $this->fallDistance)
            //filled in by the inventory (step 11c) and effects once they exist
            ->setInt("SelectedInventorySlot", 0)
            ->setTag("Inventory", new ListTag([], CompoundTag::TAG_COMPOUND))
            ->setTag("EnderChestInventory", new ListTag([], CompoundTag::TAG_COMPOUND))
            ->setTag("ActiveEffects", new ListTag([], CompoundTag::TAG_COMPOUND));

        if($this->spawnPosition !== null && $this->spawnWorldName !== null){
            $data->setString("SpawnWorld", $this->spawnWorldName)
                ->setInt("SpawnX", $this->spawnPosition->getFloorX())
                ->setInt("SpawnY", $this->spawnPosition->getFloorY())
                ->setInt("SpawnZ", $this->spawnPosition->getFloorZ());
        }

        return $data;
    }

    public function getName() : string{
        return $this->identity->username;
    }

    public function getXuid() : string{
        return $this->identity->xuid;
    }

    public function getNameTag() : string{
        return $this->getName();
    }

    public function getDeviceOs() : int{
        $deviceOs = $this->identity->clientData->DeviceOS ?? -1;

        return is_int($deviceOs) ? $deviceOs : -1;
    }

    public function getIdentity() : AuthenticatedLogin{
        return $this->identity;
    }

    public function getNetworkSession() : NetworkSession{
        return $this->session;
    }

    public function getGameMode() : GameMode{
        return $this->gameMode;
    }

    /**
     * Changes the game mode, tells the client and shows or hides the player for others (spectators
     * are only seen by other spectators).
     */
    public function setGameMode(GameMode $gameMode) : void{
        if($gameMode === $this->gameMode){
            return;
        }

        $this->gameMode = $gameMode;
        $this->flying = $gameMode->forcesFlying() || ($this->flying && $gameMode->allowsFlying());

        if($this->spawned){
            $this->session->sendGameMode();
            $this->updateVisibility();
        }
    }

    public function isFlying() : bool{
        return $this->flying;
    }

    /**
     * Starts or stops flying. Only works in a game mode that allows flying, and spectators cannot stop.
     *
     * @return bool false when the game mode does not allow it
     */
    public function setFlying(bool $flying) : bool{
        if(($flying && !$this->gameMode->allowsFlying()) || (!$flying && $this->gameMode->forcesFlying())){
            return false;
        }

        $this->flying = $flying;

        if($this->spawned){
            $this->session->sendAbilities();
        }

        return true;
    }

    /**
     * @internal
     */
    public function onClientFlightToggle(bool $flying) : void{
        if(($flying && !$this->gameMode->allowsFlying()) || (!$flying && $this->gameMode->forcesFlying())){
            $this->session->sendAbilities();
            return;
        }

        $this->flying = $flying;
    }

    public function isVisibleTo(Player $player) : bool{
        return !$this->dead && ($this->gameMode->isVisible() || !$player->getGameMode()->isVisible());
    }

    public function teleport(Vector3 $position, ?float $yaw = null, ?float $pitch = null, ?World $world = null) : void{
        $changesWorld = $world !== null && $world !== $this->world;

        if($changesWorld){
            foreach($this->world->getEntities() as $entity){
                $entity->despawnFrom($this);
            }
        }

        parent::teleport($position, $yaw, $pitch, $world);
        $this->fallDistance = 0.0;

        if($this->spawned){
            $this->session->onTeleport($changesWorld);
        }

        if($changesWorld){
            $this->updateVisibility();
        }
    }

    /**
     * The player's own spawn point, null when they use the world spawn.
     */
    public function getSpawnPosition() : ?Vector3{
        return $this->spawnPosition;
    }

    /**
     * @return array{World, Vector3}
     */
    public function getSpawnLocation() : array{
        $worldManager = $this->session->getServer()->getWorldManager();

        if($this->spawnPosition !== null && $this->spawnWorldName !== null){
            $world = $worldManager->getWorld($this->spawnWorldName);

            if($world !== null){
                return [$world, $this->spawnPosition];
            }
        }

        $world = $worldManager->getDefaultWorld();

        return [$world, $world->getSpawnPosition()];
    }

    public function setSpawnPosition(?Vector3 $position, ?World $world = null) : void{
        if($position === null){
            $this->spawnPosition = null;
            $this->spawnWorldName = null;
        }else{
            $this->spawnPosition = new Vector3($position->getFloorX() + 0.5, (float) $position->getFloorY(), $position->getFloorZ() + 0.5);
            $this->spawnWorldName = ($world ?? $this->world)->getName();
        }

        if($this->spawned){
            $this->session->sendSpawnPositions();
        }
    }

    public function isDead() : bool{
        return $this->dead;
    }

    public function killWithCause(string $cause) : void{
        if(!$this->dead && $this->isAlive()){
            $this->deathCause = $cause;
            $this->kill();
        }
    }

    protected function canBeDamagedBy(DamageSource $source) : bool{
        return !$this->dead && (!$this->gameMode->isInvulnerable() || $source->bypassesGameMode());
    }

    protected function onDamaged(DamageSource $source) : void{
        $this->session->sendActorEvent($this, ActorEventPacket::HURT);

        foreach($this->viewers as $viewer){
            $viewer->getNetworkSession()->sendActorEvent($this, ActorEventPacket::HURT);
        }

        if($source->isReducedByArmor()){
            $this->exhaust(0.1);
        }
    }

    /**
     * Creative and spectator players never get hungry.
     */
    public function exhaust(float $amount) : void{
        if(!$this->gameMode->isInvulnerable()){
            parent::exhaust($amount);
        }
    }

    protected function onDeath() : void{
        $source = $this->lastDamageSource;
        $cause = $this->deathCause ?? $source?->getDeathMessageKey() ?? "death.attack.generic";
        $parameters = [$this->getName()];

        if($this->deathCause === null && ($attacker = $source?->getAttackerName()) !== null){
            $parameters[] = $attacker;
        }

        $this->deathCause = null;
        $this->dead = true;

        foreach($this->viewers as $viewer){
            $viewer->getNetworkSession()->sendActorEvent($this, ActorEventPacket::DEATH);
        }

        $this->session->onDeath($cause, $parameters);
        $this->session->getServer()->onPlayerDeath($this, $cause, $parameters);
    }

    public function tick(int $currentTick) : void{
        if(!$this->spawned || $this->dead){
            return;
        }

        if(!$this->gameMode->isInvulnerable()){
            $this->tickHunger($this->session->getServer()->getDifficulty());
        }

        if($this->position->y < Chunk::MIN_Y - self::VOID_DEPTH && $currentTick - $this->lastVoidDamageTick >= self::VOID_DAMAGE_TICKS){
            $this->lastVoidDamageTick = $currentTick;
            $this->damage(new DamageSource(DamageSource::CAUSE_VOID, self::VOID_DAMAGE));
        }
    }

    /**
     * @internal
     *
     * @param bool $onGround the client stopped against something below it
     * @param bool $jumped the client started a jump this tick
     */
    public function onClientMove(Vector3 $from, bool $onGround, bool $jumped) : void{
        $dx = $this->position->x - $from->x;
        $dz = $this->position->z - $from->z;
        $horizontal = sqrt($dx * $dx + $dz * $dz);

        if($this->swimming){
            $this->exhaust(0.01 * $horizontal);
        }elseif($this->sprinting){
            $this->exhaust(0.1 * $horizontal);
        }

        if($jumped){
            $this->exhaust($this->sprinting ? 0.2 : 0.05);
        }

        $this->updateFallDistance($this->position->y - $from->y, $onGround);
    }

    private function updateFallDistance(float $dy, bool $onGround) : void{
        if($this->flying || $this->swimming || $this->isInWater()){
            $this->fallDistance = 0.0;
            return;
        }

        if($onGround){
            $this->fallDistance -= min($dy, 0.0);
            $damage = $this->fallDistance - self::SAFE_FALL_DISTANCE;
            $this->fallDistance = 0.0;

            if($damage >= 0.5){
                $this->damage(new DamageSource(DamageSource::CAUSE_FALL, ceil($damage)));
            }
        }elseif($dy < 0){
            $this->fallDistance -= $dy;
        }else{
            $this->fallDistance = 0.0;
        }
    }

    /**
     * Water at the feet or the head breaks a fall.
     */
    private function isInWater() : bool{
        $x = $this->position->getFloorX();
        $z = $this->position->getFloorZ();

        foreach([$this->position->getFloorY(), (int) floor($this->position->y + $this->getEyeHeight())] as $y){
            if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y){
                continue;
            }

            $name = $this->world->getBlock($x, $y, $z)->getName();

            if($name === BlockIds::WATER || $name === BlockIds::FLOWING_WATER){
                return true;
            }
        }

        return false;
    }

    public function isSprinting() : bool{
        return $this->sprinting;
    }

    /**
     * @internal
     */
    public function setSprinting(bool $sprinting) : void{
        $this->sprinting = $sprinting;
    }

    public function isSwimming() : bool{
        return $this->swimming;
    }

    /**
     * @internal
     */
    public function setSwimming(bool $swimming) : void{
        $this->swimming = $swimming;
    }

    public function getFallDistance() : float{
        return $this->fallDistance;
    }

    public function respawn() : void{
        if(!$this->dead){
            return;
        }

        $this->dead = false;
        $this->resetVitals();
        $this->flying = $this->gameMode->forcesFlying();
        $this->swimming = false;
        $this->sprinting = false;
        $this->lastDamageSource = null;

        [$world, $position] = $this->getSpawnLocation();

        $this->despawnFromAll();
        $this->teleport($position, null, null, $world);

        $this->session->onRespawn();
        $this->updateVisibility();
    }

    private function resetVitals() : void{
        $this->health = $this->maxHealth;
        $this->absorption = 0.0;
        $this->food = self::MAX_FOOD;
        $this->saturation = self::DEFAULT_SATURATION;
        $this->exhaustion = 0.0;
        $this->foodTickTimer = 1;
        $this->fallDistance = 0.0;
    }

    public function updateVisibility() : void{
        foreach($this->session->getServer()->getInGamePlayers() as $other){
            if($other === $this || $other->getWorld() !== $this->world){
                continue;
            }

            if($this->isVisibleTo($other)){
                $this->spawnTo($other);
            }else{
                $this->despawnFrom($other);
            }

            if($other->isVisibleTo($this)){
                $other->spawnTo($this);
            }else{
                $other->despawnFrom($this);
            }
        }
    }

    protected function onAttributesChanged() : void{
        if($this->spawned){
            $this->session->sendAttributes();
        }
    }

    /**
     * True once the client has finished loading and is in the world.
     */
    public function isSpawned() : bool{
        return $this->spawned;
    }

    /**
     * @internal called by NetworkSession when the client reports it has loaded
     */
    public function onSpawned() : void{
        $this->spawned = true;
    }

    public function isOnline() : bool{
        return !$this->closed && $this->session->isConnected();
    }

    /**
     * Disconnects the player and shows $reason on their screen.
     */
    public function kick(string $reason) : void{
        $this->session->disconnect($reason);
    }

    /**
     * Shows a plain message in the player's chat.
     */
    public function sendMessage(string $message) : void{
        $this->session->sendMessage($message);
    }

    /**
     * @param list<string> $parameters
     */
    public function sendTranslation(string $message, array $parameters = []) : void{
        $this->session->sendTranslation($message, $parameters);
    }

    /**
     * Text above the hotbar, shown for a few seconds.
     */
    public function sendPopup(string $message) : void{
        $this->sendText(TextPacket::popup($message));
    }

    /**
     * Text in the middle of the screen, above the popup.
     */
    public function sendTip(string $message) : void{
        $this->sendText(TextPacket::tip($message));
    }

    /**
     * @param list<string> $parameters
     */
    public function sendJukeboxPopup(string $message, array $parameters = []) : void{
        $this->sendText(TextPacket::jukeboxPopup($message, $parameters));
    }

    /**
     * A chat message that still shows when the player has chat turned off.
     */
    public function sendSystemMessage(string $message) : void{
        $this->sendText(TextPacket::system($message));
    }

    /**
     * Shown as "<from> whispers to you: message".
     */
    public function sendWhisper(string $from, string $message) : void{
        $this->sendText(TextPacket::whisper($from, $message));
    }

    /**
     * Shown as "[from] message", like /say.
     */
    public function sendAnnouncement(string $from, string $message) : void{
        $this->sendText(TextPacket::announcement($from, $message));
    }

    /**
     * Shows a big title, with an optional subtitle under it. Times are in ticks (20 = 1 second);
     * leave them at -1 to keep the current ones (the client default is 10, 70, 20).
     */
    public function sendTitle(string $title, string $subtitle = "", int $fadeIn = -1, int $stay = -1, int $fadeOut = -1) : void{
        if($fadeIn >= 0 && $stay >= 0 && $fadeOut >= 0){
            $this->setTitleDuration($fadeIn, $stay, $fadeOut);
        }

        if($subtitle !== ""){
            $this->sendSubTitle($subtitle);
        }

        $this->session->sendDataPacket(SetTitlePacket::title($title));
    }

    /**
     * Sets the subtitle for the next title, or changes it while a title is showing.
     */
    public function sendSubTitle(string $subtitle) : void{
        $this->session->sendDataPacket(SetTitlePacket::subtitle($subtitle));
    }

    /**
     * Text above the hotbar, in the same place as a popup.
     */
    public function sendActionBarMessage(string $message) : void{
        $this->session->sendDataPacket(SetTitlePacket::actionBar($message));
    }

    /**
     * Times are in ticks (20 = 1 second).
     */
    public function setTitleDuration(int $fadeIn, int $stay, int $fadeOut) : void{
        $this->session->sendDataPacket(SetTitlePacket::durations($fadeIn, $stay, $fadeOut));
    }

    /**
     * Removes the title and subtitle from the screen.
     */
    public function removeTitles() : void{
        $this->session->sendDataPacket(SetTitlePacket::clear());
    }

    /**
     * Removes the title and puts the durations back to the client's defaults.
     */
    public function resetTitles() : void{
        $this->session->sendDataPacket(SetTitlePacket::reset());
    }

    /**
     * Slides a notification in at the top of the screen, like an achievement.
     */
    public function sendToast(string $title, string $body) : void{
        $this->session->sendDataPacket(ToastRequestPacket::create($title, $body));
    }

    private function sendText(TextPacket $packet) : void{
        if($packet->message !== ""){
            $this->session->sendDataPacket($packet);
        }
    }

    public function isOp() : bool{
        return $this->session->getServer()->getOperatorList()->isOperator($this->getXuid());
    }

    public function setOp(bool $op) : void{
        $operators = $this->session->getServer()->getOperatorList();

        if($op){
            $operators->add($this->getXuid(), $this->getName());
        }else{
            $operators->remove($this->getXuid());
        }

        $this->session->sendAvailableCommands();
        $this->session->sendAbilities();
    }

    public function getPermissionLevel() : PermissionLevel{
        return $this->isOp() ? PermissionLevel::OPERATOR : PermissionLevel::PUBLIC;
    }

    protected function getPermissionManager() : PermissionManager{
        return $this->session->getServer()->getPermissionManager();
    }

    public function close() : void{
        if($this->closed){
            return;
        }

        foreach($this->world->getEntities() as $entity){
            $entity->despawnFrom($this, false);
        }

        parent::close();
    }
}