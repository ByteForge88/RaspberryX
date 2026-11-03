<?php

declare(strict_types=1);

namespace raspberry\network\mcpe;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\mcpe\protocol\ChunkRadiusUpdatedPacket;
use raspberry\network\mcpe\protocol\ContainerClosePacket;
use raspberry\network\mcpe\protocol\ContainerOpenPacket;
use raspberry\network\mcpe\protocol\InteractPacket;
use raspberry\network\mcpe\protocol\InventoryContentPacket;
use raspberry\network\mcpe\protocol\ItemStackRequestPacket;
use raspberry\network\mcpe\protocol\InventoryTransactionPacket;
use raspberry\network\mcpe\protocol\AddItemActorPacket;
use raspberry\network\mcpe\protocol\MobArmorEquipmentPacket;
use raspberry\network\mcpe\protocol\MobEquipmentPacket;
use raspberry\network\mcpe\protocol\types\ItemInstance;
use raspberry\network\mcpe\protocol\DisconnectPacket;
use raspberry\network\mcpe\protocol\ItemRegistryPacket;
use raspberry\network\mcpe\protocol\JigsawStructureDataPacket;
use raspberry\network\mcpe\protocol\LevelChunkPacket;
use raspberry\network\mcpe\protocol\LoginPacket;
use raspberry\network\mcpe\protocol\NetworkChunkPublisherUpdatePacket;
use raspberry\network\mcpe\protocol\NetworkSettingsPacket;
use raspberry\network\mcpe\protocol\Packet;
use raspberry\network\mcpe\protocol\PlayerAuthInputPacket;
use raspberry\network\mcpe\protocol\PlayStatusPacket;
use raspberry\network\mcpe\protocol\ProtocolInfo;
use raspberry\network\mcpe\protocol\ProtocolPacketIds;
use raspberry\network\mcpe\protocol\RequestChunkRadiusPacket;
use raspberry\network\mcpe\protocol\RequestNetworkSettingsPacket;
use raspberry\network\mcpe\protocol\ResourcePackClientResponsePacket;
use raspberry\network\mcpe\protocol\SetLocalPlayerAsInitializedPacket;
use raspberry\network\mcpe\protocol\StartGamePacket;
use raspberry\network\mcpe\protocol\VoxelShapesPacket;
use raspberry\network\mcpe\protocol\AddPlayerPacket;
use raspberry\network\mcpe\protocol\MoveActorAbsolutePacket;
use raspberry\network\mcpe\protocol\PlayerListPacket;
use raspberry\network\mcpe\protocol\RemoveActorPacket;
use raspberry\network\mcpe\protocol\TextPacket;
use raspberry\network\mcpe\protocol\CommandRequestPacket;
use raspberry\network\mcpe\protocol\SetPlayerGameTypePacket;
use raspberry\network\mcpe\protocol\SetActorDataPacket;
use raspberry\network\mcpe\protocol\ActorEventPacket;
use raspberry\network\mcpe\protocol\DeathInfoPacket;
use raspberry\network\mcpe\protocol\MovePlayerPacket;
use raspberry\network\mcpe\protocol\RespawnPacket;
use raspberry\network\mcpe\protocol\SetSpawnPositionPacket;
use raspberry\network\mcpe\protocol\UpdateAbilitiesPacket;
use raspberry\network\mcpe\protocol\UpdateAttributesPacket;
use raspberry\network\mcpe\protocol\types\AbilityData;
use raspberry\network\mcpe\protocol\types\AbilityLayer;
use raspberry\network\mcpe\protocol\types\Attribute;
use raspberry\network\mcpe\protocol\types\EntityMetadata;
use raspberry\network\mcpe\protocol\types\PlayerListEntry;
use raspberry\network\mcpe\protocol\types\inventory\ContainerIds;
use raspberry\network\mcpe\protocol\types\inventory\TransactionAction;
use raspberry\network\mcpe\auth\AuthenticatedLogin;
use raspberry\network\mcpe\compression\PacketCompression;
use raspberry\network\mcpe\resourcepack\ResourcePackNegotiation;
use raspberry\network\mcpe\serializer\NetworkChunkSerializer;
use raspberry\network\mcpe\convert\SkinAdapter;
use raspberry\network\mcpe\convert\CommandDataAdapter;

use raspberry\entity\Entity;
use raspberry\entity\Human;
use raspberry\entity\object\ItemEntity;

use raspberry\inventory\PlayerInventory;

use raspberry\item\Item;

use InvalidArgumentException;

use raspberry\math\Vector3;

use raspberry\player\Player;

use raspberry\Server;

use raspberry\nbt\NbtException;

use raspberry\world\generator\GeneratorFactory;

use stdClass;
use Throwable;
use UnexpectedValueException;

use function array_slice;
use function ceil;
use function count;
use function is_string;
use function max;
use function min;
use function sprintf;
use function strlen;
use function usort;
use function preg_match_all;
use function preg_replace;
use function round;
use function trim;

class NetworkSession{

    public const STATE_NETWORK_SETTINGS = 0;

    public const STATE_LOGIN = 1;

    public const STATE_RESOURCE_PACKS = 2;

    /** StartGame sent, waiting for RequestChunkRadius */
    public const STATE_SPAWNING = 3;

    /** PLAYER_SPAWN sent, waiting for the client to finish loading */
    public const STATE_LOADING = 4;

    public const STATE_IN_GAME = 5;

    public const STATE_DISCONNECTING = 6;

    public const STATE_CLOSED = 7;

    public const COMPRESSION_THRESHOLD = 256;

    /** longest chat message accepted, in characters (the vanilla client allows about this much) */
    public const CHAT_MAX_LENGTH = 512;

    /** how long to wait for a client to arrive after a teleport before sending it again (5 seconds) */
    private const TELEPORT_RESEND_TICKS = 100;

    /** chunks sent per tick, so a large view distance does not stall the server */
    private const CHUNKS_PER_TICK = 16;

    /** ticks to wait after a DisconnectPacket before closing, so the message reaches the client */
    private const DISCONNECT_DELAY_TICKS = 10;

    private Server $server;

    private int $id;

    private stdClass $signalingClaims;

    private int $state = self::STATE_NETWORK_SETTINGS;

    private PacketCompression $compression;

    /** name for log messages, the verified username once logged in */
    private ?string $displayName = null;

    private ?AuthenticatedLogin $identity = null;

    private ?ResourcePackNegotiation $resourcePacks = null;

    private ?Player $player = null;

    private ?InventoryManager $inventoryManager = null;

    private ?ItemStackRequestHandler $itemStackRequestHandler = null;

    /** whether the client has its inventory screen open */
    private bool $inventoryOpen = false;

    /** true once the player finished joining, stays true while disconnecting so leaving is still handled */
    private bool $joined = false;

    private int $chunkRadius = 0;

    /** @var array<string, true> chunks the client has, keyed "x:z" */
    private array $sentChunks = [];

    /** @var list<array{int, int}> chunks still to send, nearest first */
    private array $chunkQueue = [];

    /** where the client was teleported to, its movement is ignored until it arrives there */
    private ?Vector3 $pendingTeleport = null;

    private int $pendingTeleportTick = 0;

    private ?int $lastChunkX = null;
    private ?int $lastChunkZ = null;

    /** why the session is closing, used in the log message */
    private ?string $closeReason = null;

    private int $disconnectAtTick = 0;

    public function __construct(Server $server, int $id, stdClass $signalingClaims){
        $this->server = $server;
        $this->id = $id;
        $this->signalingClaims = $signalingClaims;
        $this->compression = new PacketCompression();
    }

    public function getServer() : Server{
        return $this->server;
    }

    public function getId() : int{
        return $this->id;
    }

    public function getState() : int{
        return $this->state;
    }

    public function getPlayer() : ?Player{
        return $this->player;
    }

    public function getIdentity() : ?AuthenticatedLogin{
        return $this->identity;
    }

    public function isConnected() : bool{
        return $this->state < self::STATE_DISCONNECTING;
    }

    public function getDisplayName() : string{
        return $this->displayName ?? "Session {$this->id}";
    }

    /**
     * Handles one raw payload from the transport. Errors disconnect this client only.
     */
    public function handlePayload(string $payload, bool $reliable) : void{
        if(!$this->isConnected()){
            return;
        }

        try{
            $this->handleBatch($payload, $reliable);
        }catch(Throwable $error){
            $this->server->getLogger()->debug("Session {$this->id}: " . $error::class . ": " . $error->getMessage());

            $this->disconnect($this->server->getLanguage()->translate("disconnect.error"), $error->getMessage());
        }
    }

    private function handleBatch(string $payload, bool $reliable) : void{
        $packets = $this->server->getPacketBatch()->decodeRaw($this->compression->decode($payload));

        if($this->state === self::STATE_NETWORK_SETTINGS){
            if(!$reliable || count($packets) !== 1){
                throw new UnexpectedValueException("The first batch must be one reliable network-settings request");
            }

            $decoded = $this->server->getPacketTranslator()->decode($packets[0]);

            if(!$decoded["packet"] instanceof RequestNetworkSettingsPacket || $decoded["senderSubClientId"] !== 0 || $decoded["targetSubClientId"] !== 0){
                throw new UnexpectedValueException("Expected RequestNetworkSettingsPacket from the primary client");
            }

            $this->handleNetworkSettings($decoded["packet"]);
            return;
        }

        foreach($packets as $buffer){
            if(!$this->isConnected()){
                return;
            }

            $this->handlePacketBuffer($buffer, $reliable);
        }
    }

    private function handlePacketBuffer(string $buffer, bool $reliable) : void{
        $header = (new BinaryReader($buffer))->readUnsignedVarInt();

        if(($header & ~0x3fff) !== 0){
            throw new UnexpectedValueException("Packet header contains unsupported bits");
        }

        $packetId = $header & 0x3ff;
        $fromPrimaryClient = (($header >> 10) & 0x03) === 0 && (($header >> 12) & 0x03) === 0;

        switch($packetId){
            case ProtocolPacketIds::REQUEST_NETWORK_SETTINGS_PACKET:
            case ProtocolPacketIds::NETWORK_SETTINGS_PACKET:
                throw new UnexpectedValueException("Unexpected network-settings packet after negotiation");

            case ProtocolPacketIds::LOGIN_PACKET:
                if($this->state !== self::STATE_LOGIN || !$reliable || !$fromPrimaryClient){
                    throw new UnexpectedValueException("Unexpected Login packet");
                }

                $this->handleLogin($this->decode($buffer, LoginPacket::class));
                return;
        }

        if($this->state === self::STATE_LOGIN){
            throw new UnexpectedValueException("Expected a Login packet");
        }

        $this->server->getLogger()->debug(sprintf("Session %d received packet 0x%03X: %d bytes", $this->id, $packetId, strlen($buffer)));

        switch($packetId){
            case ProtocolPacketIds::RESOURCE_PACK_CLIENT_RESPONSE_PACKET:
                if($this->state !== self::STATE_RESOURCE_PACKS || !$reliable || !$fromPrimaryClient){
                    throw new UnexpectedValueException("Unexpected resource-pack response");
                }

                $this->handleResourcePackResponse($this->decode($buffer, ResourcePackClientResponsePacket::class));
                return;

            case ProtocolPacketIds::REQUEST_CHUNK_RADIUS_PACKET:
                if($this->state < self::STATE_SPAWNING){
                    throw new UnexpectedValueException("Chunk radius requested before StartGame");
                }

                $this->handleRequestChunkRadius($this->decode($buffer, RequestChunkRadiusPacket::class));
                return;

            case ProtocolPacketIds::SET_LOCAL_PLAYER_AS_INITIALIZED_PACKET:
                $this->handleLocalPlayerInitialized($this->decode($buffer, SetLocalPlayerAsInitializedPacket::class));
                return;

            case ProtocolPacketIds::PLAYER_AUTH_INPUT_PACKET:
                if($this->player !== null && $this->state >= self::STATE_LOADING){
                    $this->handlePlayerAuthInput($this->decode($buffer, PlayerAuthInputPacket::class));
                }
                return;

            case ProtocolPacketIds::RESPAWN_PACKET:
                $this->handleRespawn($this->decode($buffer, RespawnPacket::class));
                return;

            case ProtocolPacketIds::MOB_EQUIPMENT_PACKET:
                $this->handleMobEquipment($this->decode($buffer, MobEquipmentPacket::class));
                return;

            case ProtocolPacketIds::INTERACT_PACKET:
                $this->handleInteract($this->decode($buffer, InteractPacket::class));
                return;

            case ProtocolPacketIds::ITEM_STACK_REQUEST_PACKET:
                if($this->state === self::STATE_IN_GAME){
                    $this->itemStackRequestHandler?->handle($this->decode($buffer, ItemStackRequestPacket::class));
                }
                return;

            case ProtocolPacketIds::INVENTORY_TRANSACTION_PACKET:
                if($this->state === self::STATE_IN_GAME){
                    $this->handleInventoryTransaction($this->decode($buffer, InventoryTransactionPacket::class));
                }
                return;

            case ProtocolPacketIds::CONTAINER_CLOSE_PACKET:
                $this->handleContainerClose($this->decode($buffer, ContainerClosePacket::class));
                return;

            case ProtocolPacketIds::COMMAND_REQUEST_PACKET:
                $this->handleCommandRequest($this->decode($buffer, CommandRequestPacket::class));
                return;

            case ProtocolPacketIds::TEXT_PACKET:
                $this->handleText($this->decode($buffer, TextPacket::class));
                return;

            case ProtocolPacketIds::RESOURCE_PACKS_INFO_PACKET:
            case ProtocolPacketIds::RESOURCE_PACK_STACK_PACKET:
                throw new UnexpectedValueException("Client sent a server-only resource-pack packet");
        }

        //everything else is not handled yet
    }

    /**
     * @template T of Packet
     * @param class-string<T> $class
     * @return T
     */
    private function decode(string $buffer, string $class) : Packet{
        $packet = $this->server->getPacketTranslator()->decode($buffer)["packet"];

        if(!$packet instanceof $class){
            throw new UnexpectedValueException("Packet registered for " . $class::getPacketId() . " is not {$class}");
        }

        return $packet;
    }

    public function sendDataPacket(Packet $packet) : void{
        $this->sendDataPackets([$packet]);
    }

    /**
     * @param list<Packet> $packets sent together in one compressed batch
     */
    public function sendDataPackets(array $packets) : void{
        if($packets === [] || !$this->isConnected()){
            return;
        }

        $batch = $this->server->getPacketBatch()->encode($packets);

        $this->server->getTransport()->sendReliable($this->id, $this->compression->encode($batch));
    }

    /**
     * Shows $message on the client's disconnect screen, then closes the connection shortly after.
     *
     * @param string|null $logReason what to write in the server log, if different from the message
     */
    public function disconnect(string $message, ?string $logReason = null) : void{
        if(!$this->isConnected()){
            return;
        }

        if($this->compression->isEnabled()){
            $this->sendDataPacket(DisconnectPacket::create($message));
        }

        $this->closeReason = $logReason ?? $message;
        $this->state = self::STATE_DISCONNECTING;
        $this->disconnectAtTick = $this->server->getCurrentTick() + self::DISCONNECT_DELAY_TICKS;
    }

    /**
     * Called every server tick.
     */
    public function tick(int $currentTick, int $now) : void{
        if($this->state === self::STATE_DISCONNECTING){
            if($currentTick >= $this->disconnectAtTick){
                $this->server->getTransport()->disconnect($this->id, $this->closeReason ?? "Disconnected");
            }

            return;
        }

        if($this->resourcePacks !== null && $this->resourcePacks->isExpired($now)){
            $this->disconnect($this->server->getLanguage()->translate("disconnect.timeout"), "Resource-pack negotiation timed out");
            return;
        }

        if($this->pendingTeleport !== null && $currentTick - $this->pendingTeleportTick > self::TELEPORT_RESEND_TICKS){
            $this->sendTeleport();
        }

        if($this->joined && $this->player !== null){
            $this->player->tick($currentTick);
        }

        $this->sendQueuedChunks();
    }

    /**
     * Called once when the transport has closed the connection.
     */
    public function onClose(string $transportReason) : void{
        if($this->state === self::STATE_CLOSED){
            return;
        }

        $wasInGame = $this->joined;
        $reason = $this->closeReason ?? $transportReason;

        $this->state = self::STATE_CLOSED;

        $logger = $this->server->getLogger();
        $language = $this->server->getLanguage();

        if($this->displayName === null){
            $logger->debug("Session {$this->id} closed before login: {$reason}");
        }elseif($wasInGame && $this->closeReason === null && $transportReason === "Connection closed"){
            $logger->info($language->translate("player.left", ["player" => $this->displayName]));
        }else{
            $logger->info($language->translate("player.lost", ["player" => $this->displayName, "reason" => $reason]));
        }

        if($wasInGame && $this->player !== null){
            $this->server->onPlayerQuit($this->player);
        }

        $this->player?->close();
        $this->resourcePacks = null;
        $this->chunkQueue = [];
        $this->sentChunks = [];
    }

    public function sendGameMode() : void{
        if($this->player !== null){
            $this->sendDataPacket(SetPlayerGameTypePacket::create($this->player->getGameMode()->value));
            $this->sendAbilities();
        }
    }

    public function sendAbilities() : void{
        $player = $this->player;

        if($player === null){
            return;
        }

        $mode = $player->getGameMode();
        $values = AbilityLayer::WALK_SPEED | AbilityLayer::FLY_SPEED | AbilityLayer::VERTICAL_FLY_SPEED;

        if($mode->allowsFlying()){
            $values |= AbilityLayer::MAY_FLY;
        }

        if($player->isFlying()){
            $values |= AbilityLayer::FLYING;
        }

        if(!$mode->hasCollision()){
            $values |= AbilityLayer::NO_CLIP;
        }

        if($mode->isInvulnerable()){
            $values |= AbilityLayer::INVULNERABLE;
        }

        if($mode->hasInstantBuild()){
            $values |= AbilityLayer::INSTANT_BUILD;
        }

        if($mode->allowsBuilding()){
            $values |= AbilityLayer::BUILD | AbilityLayer::MINE;
        }

        if($mode->allowsInteraction()){
            $values |= AbilityLayer::DOORS_AND_SWITCHES | AbilityLayer::OPEN_CONTAINERS | AbilityLayer::ATTACK_PLAYERS | AbilityLayer::ATTACK_MOBS;
        }

        $op = $player->isOp();

        if($op){
            $values |= AbilityLayer::OPERATOR_COMMANDS | AbilityLayer::TELEPORT;
        }

        $this->sendDataPacket(UpdateAbilitiesPacket::create(new AbilityData(
            $player->getId(),
            $op ? AbilityData::PLAYER_PERMISSION_OPERATOR : AbilityData::PLAYER_PERMISSION_MEMBER,
            $op ? AbilityData::COMMAND_PERMISSION_OPERATOR : AbilityData::COMMAND_PERMISSION_NORMAL,
            [new AbilityLayer(AbilityLayer::TYPE_BASE, AbilityLayer::ALL, $values, AbilityLayer::DEFAULT_FLY_SPEED, AbilityLayer::DEFAULT_VERTICAL_FLY_SPEED, AbilityLayer::DEFAULT_WALK_SPEED)]
        )));
    }

    public function sendOwnMetadata() : void{
        if($this->player !== null){
            $this->sendDataPacket(SetActorDataPacket::create($this->player->getId(), self::createHumanMetadata($this->player), $this->server->getCurrentTick()));
        }
    }

    public function sendAttributes() : void{
        $player = $this->player;

        if($player === null){
            return;
        }

        $this->sendDataPacket(UpdateAttributesPacket::create($player->getId(), [
            new Attribute(Attribute::HEALTH, 0.0, $player->getMaxHealth(), (float) ceil($player->getHealth()), 20.0),
            new Attribute(Attribute::ABSORPTION, 0.0, PHP_FLOAT_MAX, (float) ceil($player->getAbsorption()), 0.0),
            new Attribute(Attribute::MOVEMENT_SPEED, 0.0, PHP_FLOAT_MAX, $player->getMovementSpeed(), Player::DEFAULT_MOVEMENT_SPEED),
            new Attribute(Attribute::HUNGER, 0.0, Player::MAX_FOOD, $player->getFood(), Player::MAX_FOOD),
            new Attribute(Attribute::SATURATION, 0.0, Player::MAX_FOOD, $player->getSaturation(), Player::DEFAULT_SATURATION),
            new Attribute(Attribute::EXHAUSTION, 0.0, 5.0, $player->getExhaustion(), 0.0)
        ], $this->server->getCurrentTick()));
    }

    public function sendAvailableCommands() : void{
        if($this->player !== null){
            $this->sendDataPacket(CommandDataAdapter::createAvailableCommands($this->server->getCommandMap(), $this->player));
        }
    }

    public function sendMessage(string $message) : void{
        if($message !== ""){
            $this->sendDataPacket(TextPacket::raw($message));
        }
    }

    /**
     * @param list<string> $parameters
     */
    public function sendTranslation(string $message, array $parameters = []) : void{
        $this->sendDataPacket(TextPacket::translation($message, $parameters));
    }

    /**
     * Adds players to this client's player list. A player's entry must arrive before it is spawned.
     *
     * @param list<Player> $players
     */
    public function sendPlayerListAdd(array $players) : void{
        $entries = [];

        foreach($players as $player){
            $entries[] = PlayerListEntry::add($player->getUuid(), $player->getId(), $player->getName(), $player->getXuid(), $player->getDeviceOs(), $player->getSkin());
        }

        if($entries !== []){
            $this->sendDataPacket(PlayerListPacket::create($entries));
        }
    }

    /**
     * @param list<Player> $players
     */
    public function sendPlayerListRemove(array $players) : void{
        $entries = [];

        foreach($players as $player){
            $entries[] = PlayerListEntry::remove($player->getUuid());
        }

        if($entries !== []){
            $this->sendDataPacket(PlayerListPacket::create($entries));
        }
    }

    /**
     * Makes an entity appear for this client. Called by Entity::spawnTo().
     */
    public function spawnEntity(Entity $entity) : void{
        if($entity instanceof ItemEntity){
            $this->spawnItemEntity($entity);
            return;
        }

        if(!$entity instanceof Human){
            return; //TODO: AddActorPacket for other entities
        }

        $eyes = $entity->getEyePosition();

        $packet = new AddPlayerPacket();
        $packet->uuid = $entity->getUuid();
        $packet->username = $entity->getNameTag();
        $packet->actorRuntimeId = $entity->getId();
        $packet->x = $eyes->x;
        $packet->y = $eyes->y;
        $packet->z = $eyes->z;
        $packet->pitch = $entity->getPitch();
        $packet->yaw = $entity->getYaw();
        $packet->headYaw = $entity->getYaw();
        $packet->metadata = self::createHumanMetadata($entity);

        if($entity instanceof Player){
            $packet->gameMode = $entity->getGameMode()->value;
            $packet->buildPlatform = $entity->getDeviceOs();
            $packet->heldItem = $this->toItemInstance($entity->getInventory()->getItemInHand());
        }

        $this->sendDataPacket($packet);

        if($entity instanceof Player){
            $this->sendArmor($entity);

            if(!$entity->getOffHandInventory()->isSlotEmpty(0)){
                $this->sendOffHandItem($entity);
            }
        }
    }

    private function spawnItemEntity(ItemEntity $entity) : void{
        $position = $entity->getPosition();
        $motion = $entity->getMotion();

        $metadata = new EntityMetadata();
        $metadata->setFlag(EntityMetadata::FLAG_HAS_GRAVITY);
        $metadata->setFlag(EntityMetadata::FLAG_HAS_COLLISION);
        $metadata->setLong(EntityMetadata::KEY_FLAGS_TWO, 0);
        $metadata->setFloat(EntityMetadata::KEY_WIDTH, ItemEntity::SIZE);
        $metadata->setFloat(EntityMetadata::KEY_HEIGHT, ItemEntity::SIZE);
        $metadata->setFloat(EntityMetadata::KEY_SCALE, 1.0);

        $packet = new AddItemActorPacket();
        $packet->actorRuntimeId = $entity->getId();
        $packet->item = $this->toItemInstance($entity->getItem());
        $packet->x = $position->x;
        $packet->y = $position->y;
        $packet->z = $position->z;
        $packet->motionX = $motion->x;
        $packet->motionY = $motion->y;
        $packet->motionZ = $motion->z;
        $packet->metadata = $metadata;

        $this->sendDataPacket($packet);
    }

    public function despawnEntity(Entity $entity) : void{
        $this->sendDataPacket(RemoveActorPacket::create($entity->getId()));
    }

    public function moveEntity(Entity $entity, bool $teleport = false) : void{
        $position = $entity->getPosition();
        $flags = $teleport ? MoveActorAbsolutePacket::FLAG_TELEPORT : 0;

        if($entity instanceof ItemEntity && $entity->isOnGround()){
            $flags |= MoveActorAbsolutePacket::FLAG_ON_GROUND;
        }

        $this->sendDataPacket(MoveActorAbsolutePacket::create($entity->getId(), $position->x, $position->y + $entity->getNetworkOffset(), $position->z, $entity->getPitch(), $entity->getYaw(), $entity->getYaw(), $flags));
    }

    /**
     * Whether the client has been sent this chunk (and so can see entities in it).
     */
    public function hasChunk(int $chunkX, int $chunkZ) : bool{
        return isset($this->sentChunks[$chunkX . ":" . $chunkZ]);
    }

    public function sendActorEvent(Entity $entity, int $event, int $data = 0) : void{
        $this->sendDataPacket(ActorEventPacket::create($entity->getId(), $event, $data));
    }

    public function onTeleport(bool $worldChanged) : void{
        if($worldChanged){
            $this->sentChunks = [];
            $this->chunkQueue = [];
            $this->sendSpawnPositions();
        }

        $this->sendTeleport();
        $this->updateChunkQueue(true);
    }

    private function sendTeleport() : void{
        $player = $this->player;

        if($player === null){
            return;
        }

        $eyes = $player->getEyePosition();

        $this->pendingTeleport = $player->getPosition();
        $this->pendingTeleportTick = $this->server->getCurrentTick();

        $this->sendDataPacket(MovePlayerPacket::teleport($player->getId(), $eyes->x, $eyes->y, $eyes->z, $player->getPitch(), $player->getYaw(), MovePlayerPacket::TELEPORT_CAUSE_UNKNOWN, $this->server->getCurrentTick()));
    }

    public function sendSpawnPositions() : void{
        $player = $this->player;

        if($player === null){
            return;
        }

        $worldSpawn = $player->getWorld()->getSpawnPosition();
        [, $playerSpawn] = $player->getSpawnLocation();

        $this->sendDataPackets([
            SetSpawnPositionPacket::create(SetSpawnPositionPacket::TYPE_WORLD_SPAWN, $worldSpawn->getFloorX(), $worldSpawn->getFloorY(), $worldSpawn->getFloorZ()),
            SetSpawnPositionPacket::create(SetSpawnPositionPacket::TYPE_PLAYER_SPAWN, $playerSpawn->getFloorX(), $playerSpawn->getFloorY(), $playerSpawn->getFloorZ())
        ]);
    }

    /**
     * @param list<string> $parameters
     */
    public function onDeath(string $cause, array $parameters) : void{
        $player = $this->player;

        if($player === null){
            return;
        }

        [, $spawn] = $player->getSpawnLocation();

        $this->sendDataPackets([
            DeathInfoPacket::create($cause, $parameters),
            RespawnPacket::create($spawn->x, $spawn->y + $player->getEyeHeight(), $spawn->z, RespawnPacket::STATE_SEARCHING_FOR_SPAWN, $player->getId())
        ]);
    }

    public function onRespawn() : void{
        $player = $this->player;

        if($player === null){
            return;
        }

        $eyes = $player->getEyePosition();

        $this->sendDataPacket(RespawnPacket::create($eyes->x, $eyes->y, $eyes->z, RespawnPacket::STATE_READY_TO_SPAWN, $player->getId()));
        $this->sendAttributes();
        $this->sendAbilities();
        $this->sendOwnMetadata();
    }

    private static function createHumanMetadata(Human $human) : EntityMetadata{
        $metadata = new EntityMetadata();

        foreach([EntityMetadata::FLAG_HAS_GRAVITY,
            EntityMetadata::FLAG_CAN_CLIMB,
            EntityMetadata::FLAG_BREATHING,
            EntityMetadata::FLAG_HAS_COLLISION,
            EntityMetadata::FLAG_SHOW_NAME,
            EntityMetadata::FLAG_ALWAYS_SHOW_NAME
        ] as $flag){
            $metadata->setFlag($flag);
        }

        $metadata->setLong(EntityMetadata::KEY_FLAGS_TWO, 0);
        $metadata->setByte(EntityMetadata::KEY_PLAYER_FLAGS, 0);
        $metadata->setString(EntityMetadata::KEY_NAME, $human->getNameTag());
        $metadata->setByte(EntityMetadata::KEY_ALWAYS_SHOW_NAME_TAG, 1);
        $metadata->setFloat(EntityMetadata::KEY_WIDTH, Human::WIDTH);
        $metadata->setFloat(EntityMetadata::KEY_HEIGHT, Human::HEIGHT);
        $metadata->setFloat(EntityMetadata::KEY_SCALE, 1.0);
        $metadata->setShort(EntityMetadata::KEY_AIR_SUPPLY, 300);
        $metadata->setShort(EntityMetadata::KEY_AIR_SUPPLY_MAX, 300);
        $metadata->setInt(EntityMetadata::KEY_EFFECT_COLOR, 0);
        $metadata->setByte(EntityMetadata::KEY_EFFECT_AMBIENCE, 0);
        $metadata->setByte(EntityMetadata::KEY_COLOR_INDEX, 0);
        return $metadata;
    }

    private function handleMobEquipment(MobEquipmentPacket $packet) : void{
        $player = $this->player;

        if($player === null || $this->state !== self::STATE_IN_GAME || $packet->actorRuntimeId !== $player->getId()){
            return;
        }

        if($packet->windowId !== InventoryContentPacket::WINDOW_INVENTORY || $packet->hotbarSlot >= PlayerInventory::HOTBAR_SIZE){
            return;
        }

        $player->getInventory()->setHeldSlot($packet->hotbarSlot);
    }

    private function handleInteract(InteractPacket $packet) : void{
        $player = $this->player;

        if($player === null || $this->state !== self::STATE_IN_GAME || $packet->action !== InteractPacket::ACTION_OPEN_INVENTORY || $packet->targetActorRuntimeId !== $player->getId()){
            return;
        }

        if($this->inventoryOpen){
            return;
        }

        $this->inventoryOpen = true;
        $position = $player->getPosition();

        $this->sendDataPacket(ContainerOpenPacket::create(InventoryContentPacket::WINDOW_INVENTORY, ContainerOpenPacket::TYPE_INVENTORY, $position->getFloorX(), $position->getFloorY(), $position->getFloorZ(), -1));
    }

    private function handleContainerClose(ContainerClosePacket $packet) : void{
        if($this->player === null || $this->state !== self::STATE_IN_GAME){
            return;
        }

        if($packet->windowId === InventoryContentPacket::WINDOW_INVENTORY){
            $this->inventoryOpen = false;
        }

        $this->player->moveUIItemsToInventory();

        $this->sendDataPacket(ContainerClosePacket::create($packet->windowId, $packet->windowId === InventoryContentPacket::WINDOW_INVENTORY ? 0 : $packet->type, false));
    }

    private function handleInventoryTransaction(InventoryTransactionPacket $packet) : void{
        $player = $this->player;
        $manager = $this->inventoryManager;

        if($player === null || $manager === null){
            return;
        }

        if($packet->type === InventoryTransactionPacket::TYPE_NORMAL){
            try{
                $this->handleHotbarDrop($player, $packet->actions);
            }catch(UnexpectedValueException $exception){
                $this->server->getLogger()->debug("Rejected item drop of {$player->getName()}: " . $exception->getMessage());
                $manager->sendAll();
            }
        }elseif($packet->type === InventoryTransactionPacket::TYPE_MISMATCH){
            $manager->sendAll();
        }

        foreach($packet->legacySlots as $containerId => $slots){
            foreach($slots as $slot){
                if($containerId === ContainerIds::OFFHAND){
                    $manager->sendSlot($player->getOffHandInventory(), 0);
                }elseif($slot < PlayerInventory::SIZE && ($containerId === ContainerIds::INVENTORY || $containerId === ContainerIds::HOTBAR || $containerId === ContainerIds::COMBINED_HOTBAR_AND_INVENTORY)){
                    $manager->sendSlot($player->getInventory(), $slot);
                }
            }
        }
    }

    /**
     * @param list<TransactionAction> $actions
     *
     * @throws UnexpectedValueException
     */
    private function handleHotbarDrop(Player $player, array $actions) : void{
        if(count($actions) !== 2){
            throw new UnexpectedValueException("Expected 2 actions, got " . count($actions));
        }

        $slot = null;
        $count = 0;
        $expected = null;

        foreach($actions as $action){
            if($action->sourceType === TransactionAction::SOURCE_WORLD && $action->slot === 0 && $action->oldItem->isEmpty()){
                $count = $action->newItem->count;
            }elseif($action->sourceType === TransactionAction::SOURCE_CONTAINER && $action->windowId === InventoryContentPacket::WINDOW_INVENTORY && !$action->oldItem->isEmpty()){
                $slot = $action->slot;
                $expected = $action->oldItem;
            }else{
                throw new UnexpectedValueException("Unexpected action (source {$action->sourceType}, slot {$action->slot})");
            }
        }

        if($slot === null || $expected === null || $slot >= PlayerInventory::SIZE){
            throw new UnexpectedValueException("No inventory slot to drop from");
        }

        $item = $player->getInventory()->getItem($slot);
        $actual = $this->server->getItemTranslator()->toNetwork($item);

        if($count < 1 || $count > $item->getCount()){
            throw new UnexpectedValueException("Cannot drop {$count} items from a slot holding " . $item->getCount());
        }

        if($actual->networkId !== $expected->networkId || $actual->meta !== $expected->meta){
            throw new UnexpectedValueException("The slot holds a different item");
        }

        $player->getInventory()->setItem($slot, (clone $item)->setCount($item->getCount() - $count));
        $player->dropItem((clone $item)->setCount($count));
    }

    public function sendHeldItem(Player $holder) : void{
        $inventory = $holder->getInventory();

        $this->sendDataPacket(MobEquipmentPacket::create($holder->getId(), $this->toItemInstance($inventory->getItemInHand()), $inventory->getHeldSlot(), InventoryContentPacket::WINDOW_INVENTORY));
    }

    public function sendOffHandItem(Player $holder) : void{
        $this->sendDataPacket(MobEquipmentPacket::create($holder->getId(), $this->toItemInstance($holder->getOffHandInventory()->getItem(0)), 0, InventoryContentPacket::WINDOW_OFFHAND));
    }

    public function sendArmor(Player $wearer) : void{
        $armor = $wearer->getArmorInventory();

        $this->sendDataPacket(MobArmorEquipmentPacket::create(
            $wearer->getId(),
            $this->toItemInstance($armor->getHelmet()),
            $this->toItemInstance($armor->getChestplate()),
            $this->toItemInstance($armor->getLeggings()),
            $this->toItemInstance($armor->getBoots())
        ));
    }

    public function toItemInstance(Item $item) : ItemInstance{
        return new ItemInstance(0, $this->server->getItemTranslator()->toNetwork($item));
    }

    private function handleRespawn(RespawnPacket $packet) : void{
        $player = $this->player;

        if($player === null || $this->state !== self::STATE_IN_GAME || $packet->state !== RespawnPacket::STATE_CLIENT_READY_TO_SPAWN){
            return;
        }

        if($packet->actorRuntimeId !== $player->getId()){
            throw new UnexpectedValueException("Respawn packet names entity {$packet->actorRuntimeId}, expected {$player->getId()}");
        }

        $player->respawn();
    }

    private function handleCommandRequest(CommandRequestPacket $packet) : void{
        $player = $this->player;

        if($player === null || $this->state !== self::STATE_IN_GAME){
            return;
        }

        $this->server->getLogger()->info($this->server->getLanguage()->translate("player.command", [
            "player" => $player->getName(),
            "command" => $packet->command
        ]));

        $this->server->dispatchCommand($player, $packet->command);
    }

    private function handleText(TextPacket $packet) : void{
        $player = $this->player;

        if($player === null || $this->state !== self::STATE_IN_GAME){
            return;
        }

        if($packet->type !== TextPacket::TYPE_CHAT){
            throw new UnexpectedValueException("Clients may only send chat messages, got text type {$packet->type}");
        }

        if($packet->sourceName !== $player->getName() || $packet->xuid !== $player->getXuid()){
            throw new UnexpectedValueException("Chat message sender does not match the player");
        }

        $message = self::cleanChatMessage($packet->message);

        if($message === null){
            $this->server->getLogger()->debug("Session {$this->id}: dropped an invalid or too long chat message");
            return;
        }

        if($message !== ""){
            $this->server->onPlayerChat($player, $message);
        }
    }

    /**
     * Removes color codes and control characters and trims the message.
     *
     * @return string|null null when the message is not valid UTF-8 or longer than CHAT_MAX_LENGTH characters
     */
    private static function cleanChatMessage(string $message) : ?string{
        $message = preg_replace(['/§./su', '/\p{Cc}/u'], '', $message);

        if($message === null){
            return null;
        }

        $message = trim($message);

        return preg_match_all('/./su', $message) > self::CHAT_MAX_LENGTH ? null : $message;
    }

    private function handleNetworkSettings(RequestNetworkSettingsPacket $request) : void{
        $this->server->getLogger()->debug("Session {$this->id} requested network settings: client protocol={$request->protocolVersion}, server protocol=" . ProtocolInfo::CURRENT_PROTOCOL);

        if($request->protocolVersion !== ProtocolInfo::CURRENT_PROTOCOL){
            $status = new PlayStatusPacket();
            $status->status = $request->protocolVersion < ProtocolInfo::CURRENT_PROTOCOL ? PlayStatusPacket::LOGIN_FAILED_CLIENT_OLD : PlayStatusPacket::LOGIN_FAILED_SERVER_OLD;

            $this->sendDataPacket($status); //the client shows "outdated client/server" itself

            $this->closeReason = "Protocol mismatch: client={$request->protocolVersion}, server=" . ProtocolInfo::CURRENT_PROTOCOL;
            $this->state = self::STATE_DISCONNECTING;
            $this->disconnectAtTick = $this->server->getCurrentTick() + self::DISCONNECT_DELAY_TICKS;
            return;
        }

        $response = new NetworkSettingsPacket();
        $response->compressionThreshold = self::COMPRESSION_THRESHOLD;
        $response->compressionAlgorithm = NetworkSettingsPacket::ALGORITHM_ZLIB;

        $this->sendDataPacket($response);

        $this->compression->enable(self::COMPRESSION_THRESHOLD);
        $this->state = self::STATE_LOGIN;
    }

    private function handleLogin(LoginPacket $packet) : void{
        //the signaling token was already verified with Microsoft's keys, so its name is trustworthy
        $this->displayName = is_string($this->signalingClaims->xname ?? null) ? $this->signalingClaims->xname : null;

        $logger = $this->server->getLogger();
        $language = $this->server->getLanguage();

        $logger->info($language->translate("player.authenticating", ["player" => $this->getDisplayName()]));

        $identity = $this->server->getLoginAuthenticator()->authenticate($packet, $this->signalingClaims);

        $this->displayName = $identity->username;

        foreach($this->server->getSessions() as $other){
            $otherIdentity = $other->getIdentity();

            if($other !== $this && $otherIdentity !== null && $other->isConnected() && ($otherIdentity->xuid === $identity->xuid || $otherIdentity->minecraftId === $identity->minecraftId)){
                $this->disconnect($language->translate("disconnect.alreadyOnline"), "Already logged in from another session");
                return;
            }
        }

        if($this->server->getOnlinePlayerCount() >= $this->server->getMaxPlayers()){
            $this->disconnect($language->translate("disconnect.serverFull"), "Server is full");
            return;
        }

        $this->identity = $identity;

        $status = new PlayStatusPacket();
        $status->status = PlayStatusPacket::LOGIN_SUCCESS;
        $this->sendDataPacket($status);

        $logger->info($language->translate("player.authenticated", ["player" => $identity->username]));

        $this->state = self::STATE_RESOURCE_PACKS;
        $this->resourcePacks = new ResourcePackNegotiation();

        $this->sendDataPacket($this->resourcePacks->begin());
    }

    private function handleResourcePackResponse(ResourcePackClientResponsePacket $packet) : void{
        $negotiation = $this->resourcePacks ?? throw new UnexpectedValueException("No resource-pack negotiation exists for this session");

        $this->server->getLogger()->debug("Session {$this->id}: resource-pack response " . $packet->getStatusName());

        $stack = $negotiation->handle($packet);

        if($stack !== null){
            $this->sendDataPacket($stack);
        }

        if($negotiation->isComplete()){
            $this->resourcePacks = null;
            $this->startGame();
        }
    }

    /**
     * Creates the Player and sends everything the client needs before it asks for chunks.
     */
    private function startGame() : void{
        $identity = $this->identity ?? throw new UnexpectedValueException("Cannot start the game before login");

        $worldManager = $this->server->getWorldManager();
        $world = $worldManager->getDefaultWorld();
        $position = $world->getSpawnPosition();
        $yaw = 0.0;
        $pitch = 0.0;
        $data = null;

        try{
            $data = $this->server->getPlayerDataStorage()->load($identity->xuid);
        }catch(NbtException $error){
            $this->server->getLogger()->warning($this->server->getLanguage()->translate("player.data.broken", ["player" => $identity->username, "reason" => $error->getMessage()]));
        }

        $location = $data === null ? null : Player::getSavedLocation($data, $worldManager);

        if($location !== null){
            [$world, $position, $yaw, $pitch] = $location;
        }

        try{
            $skin = SkinAdapter::fromClientData($identity->clientData);
        }catch(InvalidArgumentException $error){
            $this->server->getLogger()->debug("Session {$this->id}: unusable skin, using the default one: " . $error->getMessage());
            $skin = SkinAdapter::defaultSkin();
        }

        $this->player = new Player($world, $position, $this, $identity, $this->server->getDefaultGameMode(), $skin);
        $this->player->setRotation($yaw, $pitch);

        if($data !== null){
            $this->player->loadData($data);
        }

        $this->inventoryManager = new InventoryManager($this, $this->player);
        $this->itemStackRequestHandler = new ItemStackRequestHandler($this, $this->player, $this->inventoryManager);

        $eyes = $this->player->getEyePosition();
        $spawn = $world->getSpawnPosition();

        $startGame = new StartGamePacket();
        $startGame->actorUniqueId = $this->player->getId();
        $startGame->actorRuntimeId = $this->player->getId();
        $startGame->playerGameMode = $this->player->getGameMode()->value;
        $startGame->worldGameMode = $this->server->getDefaultGameMode()->value;
        $startGame->x = $eyes->x;
        $startGame->y = $eyes->y;
        $startGame->z = $eyes->z;
        $startGame->difficulty = $this->server->getDifficulty()->value;
        $startGame->serverAuthoritativeInventory = true;
        $startGame->gameRules = ["recipesunlock" => true];
        $startGame->yaw = $yaw;
        $startGame->pitch = $pitch;
        $startGame->spawnX = $spawn->getFloorX();
        $startGame->spawnY = $spawn->getFloorY();
        $startGame->spawnZ = $spawn->getFloorZ();
        $startGame->generator = $world->getGenerator()->getName() === GeneratorFactory::FLAT ? StartGamePacket::GENERATOR_FLAT : StartGamePacket::GENERATOR_INFINITE;
        $startGame->worldName = $this->server->getServerName();
        $startGame->levelId = $world->getName();
        $startGame->time = 6000; //noon; TODO: world time
        $startGame->playerPermissions = $this->player->isOp() ? StartGamePacket::PERMISSION_OPERATOR : StartGamePacket::PERMISSION_MEMBER;

        $this->sendDataPackets([
            new JigsawStructureDataPacket(),
            new VoxelShapesPacket(),
            $startGame,
            ItemRegistryPacket::create($this->server->getItemTypeDictionary()->getEntries())
        ]);

        $this->state = self::STATE_SPAWNING;
    }

    private function handleRequestChunkRadius(RequestChunkRadiusPacket $packet) : void{
        $this->chunkRadius = max(1, min($packet->radius, $this->server->getViewDistance()));

        $this->sendDataPacket(ChunkRadiusUpdatedPacket::create($this->chunkRadius));

        if($this->state === self::STATE_SPAWNING){
            $status = new PlayStatusPacket();
            $status->status = PlayStatusPacket::PLAYER_SPAWN;

            $this->sendAbilities();
            $this->sendAttributes();
            $this->sendOwnMetadata();
            $this->sendSpawnPositions();
            $this->inventoryManager?->sendAll();
            $this->sendDataPackets([$status, $this->server->getCreativeContent(), $this->server->getCraftingData()]);
            $this->player?->getRecipeBook()->sendAll();

            $this->state = self::STATE_LOADING;
        }

        $this->updateChunkQueue(true);
    }

    private function handleLocalPlayerInitialized(SetLocalPlayerAsInitializedPacket $packet) : void{
        if($this->state !== self::STATE_LOADING || $this->player === null){
            return; //the client sometimes repeats this packet; only the first one matters
        }

        if($packet->actorRuntimeId !== $this->player->getId()){
            throw new UnexpectedValueException("Initialized packet names entity {$packet->actorRuntimeId}, expected {$this->player->getId()}");
        }

        $this->state = self::STATE_IN_GAME;
        $this->joined = true;
        $this->player->onSpawned();

        $this->server->getLogger()->info($this->server->getLanguage()->translate("player.joined", ["player" => $this->getDisplayName()]));
        $this->server->onPlayerJoin($this->player);
    }

    private function handlePlayerAuthInput(PlayerAuthInputPacket $packet) : void{
        $player = $this->player;

        if($player === null || $player->isClosed() || $player->isDead()){
            return;
        }

        $position = new Vector3($packet->x, round($packet->y - $player->getEyeHeight(), 4), $packet->z);

        if($this->pendingTeleport !== null){
            if($position->distance($this->pendingTeleport) > 1.0){
                return;
            }

            $this->pendingTeleport = null;
        }

        if($packet->hasFlag(PlayerAuthInputPacket::FLAG_START_FLYING)){
            $player->onClientFlightToggle(true);
        }elseif($packet->hasFlag(PlayerAuthInputPacket::FLAG_STOP_FLYING)){
            $player->onClientFlightToggle(false);
        }

        if($packet->hasFlag(PlayerAuthInputPacket::FLAG_START_SPRINTING)){
            $player->setSprinting(true);
        }elseif($packet->hasFlag(PlayerAuthInputPacket::FLAG_STOP_SPRINTING)){
            $player->setSprinting(false);
        }

        if($packet->hasFlag(PlayerAuthInputPacket::FLAG_START_SWIMMING)){
            $player->setSwimming(true);
        }elseif($packet->hasFlag(PlayerAuthInputPacket::FLAG_STOP_SWIMMING)){
            $player->setSwimming(false);
        }

        $from = $player->getPosition();
        $player->move($position, $packet->yaw, $packet->pitch);

        $onGround = $packet->hasFlag(PlayerAuthInputPacket::FLAG_VERTICAL_COLLISION) && $position->y <= $from->y;
        $player->onClientMove($from, $onGround, $packet->hasFlag(PlayerAuthInputPacket::FLAG_START_JUMPING));

        if($player->isDead()){
            return;
        }

        $this->updateChunkQueue(false);
    }

    /**
     * Recomputes which chunks the client needs when it moved to another chunk (or always when $force).
     */
    private function updateChunkQueue(bool $force) : void{
        $player = $this->player;

        if($player === null || $this->chunkRadius === 0){
            return;
        }

        $position = $player->getPosition();
        $centerX = $position->getChunkX();
        $centerZ = $position->getChunkZ();

        if(!$force && $centerX === $this->lastChunkX && $centerZ === $this->lastChunkZ){
            return;
        }

        $this->lastChunkX = $centerX;
        $this->lastChunkZ = $centerZ;

        $radius = $this->chunkRadius;
        $wanted = [];
        $queue = [];

        for($x = -$radius; $x <= $radius; ++$x){
            for($z = -$radius; $z <= $radius; ++$z){
                if($x * $x + $z * $z > $radius * $radius){
                    continue;
                }

                $key = ($centerX + $x) . ":" . ($centerZ + $z);
                $wanted[$key] = true;

                if(!isset($this->sentChunks[$key])){
                    $queue[] = [$centerX + $x, $centerZ + $z, $x * $x + $z * $z];
                }
            }
        }

        //the client drops chunks outside the publisher radius by itself
        foreach($this->sentChunks as $key => $_){
            if(!isset($wanted[$key])){
                unset($this->sentChunks[$key]);
            }
        }

        usort($queue, static fn(array $a, array $b) : int => $a[2] <=> $b[2]);

        $this->chunkQueue = [];

        foreach($queue as [$x, $z]){
            $this->chunkQueue[] = [$x, $z];
        }

        $this->sendDataPacket(NetworkChunkPublisherUpdatePacket::create($position->getFloorX(), $position->getFloorY(), $position->getFloorZ(), $radius << 4));
    }

    private function sendQueuedChunks() : void{
        if($this->chunkQueue === [] || $this->player === null){
            return;
        }

        $world = $this->player->getWorld();
        $translator = $this->server->getBlockTranslator();
        $packets = [];

        foreach(array_slice($this->chunkQueue, 0, self::CHUNKS_PER_TICK) as [$x, $z]){
            $payload = NetworkChunkSerializer::serialize($world->loadChunk($x, $z), $translator);

            $packets[] = LevelChunkPacket::create($x, $z, NetworkChunkSerializer::getSubChunkCount(), $payload);
            $this->sentChunks[$x . ":" . $z] = true;
        }

        $this->chunkQueue = array_slice($this->chunkQueue, self::CHUNKS_PER_TICK);

        $this->sendDataPackets($packets);
    }
}