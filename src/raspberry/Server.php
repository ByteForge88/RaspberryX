<?php

declare(strict_types=1);

namespace raspberry;

use raspberry\network\mcpe\auth\LoginAuthenticator;
use raspberry\network\mcpe\convert\BlockTranslator;
use raspberry\network\mcpe\convert\ItemTypeDictionary;
use raspberry\network\mcpe\NetworkSession;
use raspberry\network\mcpe\protocol\ChunkRadiusUpdatedPacket;
use raspberry\network\mcpe\protocol\CreativeContentPacket;
use raspberry\network\mcpe\protocol\DisconnectPacket;
use raspberry\network\mcpe\protocol\ItemRegistryPacket;
use raspberry\network\mcpe\protocol\JigsawStructureDataPacket;
use raspberry\network\mcpe\protocol\LevelChunkPacket;
use raspberry\network\mcpe\protocol\LoginPacket;
use raspberry\network\mcpe\protocol\NetworkChunkPublisherUpdatePacket;
use raspberry\network\mcpe\protocol\NetworkSettingsPacket;
use raspberry\network\mcpe\protocol\PacketBatch;
use raspberry\network\mcpe\protocol\PacketRegistry;
use raspberry\network\mcpe\protocol\PacketTranslator;
use raspberry\network\mcpe\protocol\PlayerAuthInputPacket;
use raspberry\network\mcpe\protocol\PlayStatusPacket;
use raspberry\network\mcpe\protocol\ProtocolInfo;
use raspberry\network\mcpe\protocol\RequestChunkRadiusPacket;
use raspberry\network\mcpe\protocol\RequestNetworkSettingsPacket;
use raspberry\network\mcpe\protocol\ResourcePackClientResponsePacket;
use raspberry\network\mcpe\protocol\ResourcePacksInfoPacket;
use raspberry\network\mcpe\protocol\ResourcePackStackPacket;
use raspberry\network\mcpe\protocol\SetLocalPlayerAsInitializedPacket;
use raspberry\network\mcpe\protocol\StartGamePacket;
use raspberry\network\mcpe\protocol\VoxelShapesPacket;
use raspberry\network\mcpe\protocol\AddPlayerPacket;
use raspberry\network\mcpe\protocol\MoveActorAbsolutePacket;
use raspberry\network\mcpe\protocol\PlayerListPacket;
use raspberry\network\mcpe\protocol\RemoveActorPacket;
use raspberry\network\mcpe\protocol\TextPacket;
use raspberry\network\mcpe\protocol\SetTitlePacket;
use raspberry\network\mcpe\protocol\ToastRequestPacket;
use raspberry\network\mcpe\protocol\AvailableCommandsPacket;
use raspberry\network\mcpe\protocol\CommandRequestPacket;
use raspberry\network\mcpe\protocol\UpdateSoftEnumPacket;
use raspberry\network\nethernet\NetherNetIdentity;
use raspberry\network\nethernet\NetherNetTransport;

use raspberry\command\CommandEnum;
use raspberry\command\CommandMap;
use raspberry\command\CommandSender;
use raspberry\command\ConsoleCommandSender;
use raspberry\command\defaults\DeopCommand;
use raspberry\command\defaults\HelpCommand;
use raspberry\command\defaults\KickCommand;
use raspberry\command\defaults\ListCommand;
use raspberry\command\defaults\OpCommand;
use raspberry\command\defaults\SaveAllCommand;
use raspberry\command\defaults\SayCommand;
use raspberry\command\defaults\StopCommand;
use raspberry\command\defaults\TellCommand;
use raspberry\command\defaults\TitleCommand;
use raspberry\command\defaults\VersionCommand;

use raspberry\console\ConsoleReader;

use raspberry\permission\OperatorList;
use raspberry\permission\PermissionManager;

use raspberry\player\GameMode;
use raspberry\player\Player;

use raspberry\world\WorldManager;
use raspberry\world\generator\FlatGenerator;
use raspberry\world\generator\GeneratorFactory;

use raspberry\lang\Language;

use raspberry\utils\Config;
use raspberry\utils\Logger;
use raspberry\utils\TextFormat;

use raspberry\block\BlockStateRegistry;

use React\EventLoop\Loop;

use Symfony\Component\Filesystem\Path;

use stdClass;
use Throwable;
use LogicException;
use UnexpectedValueException;
use InvalidArgumentException;

use function count;
use function filter_var;
use function function_exists;
use function hrtime;
use function is_dir;
use function pcntl_async_signals;
use function pcntl_signal;
use function sprintf;
use function str_contains;
use function array_keys;
use function implode;
use function sapi_windows_set_ctrl_handler;
use function str_starts_with;
use function strlen;
use function strtolower;

class Server{

    //TODO: create ServerInfo.php and move this
    public const NAME = "RaspberryX";
    public const VERSION = "1.0.0-ALPHA1";

    public const LICENSE = "GNU Lesser General Public License v3.0";

    public const DEFAULT_PROPERTIES = [
        "language" => Language::FALLBACK,
        "server-name" => self::NAME,
        "server-ip" => "0.0.0.0",
        "server-port" => 19132,
        "max-players" => 20,
        "gamemode" => "survival",
        "view-distance" => 8,
        "world-name" => "world",
        "world-generator" => GeneratorFactory::FLAT,
        "flat-layers" => FlatGenerator::DEFAULT_PRESET,
        "spawn-chunk-radius" => 4,
        "auto-save-interval" => 300,
        "debug-logging" => false
    ];

    private const TICKS_PER_SECOND = 20;

    private string $dataPath;

    private Config $properties;

    private Logger $logger;

    private Language $language;

    private int $startTime;

    /** ticks between auto-saves, 0 = off */
    private int $autoSaveTicks = 0;

    private bool $running = false;
    private int $currentTick = 0;

    private string $serverName = self::NAME;
    private int $maxPlayers = 20;
    private int $viewDistance = 8;
    private GameMode $defaultGameMode = GameMode::SURVIVAL;

    private PacketRegistry $packetRegistry;
    private PacketTranslator $packetTranslator;
    private PacketBatch $packetBatch;

    private ?LoginAuthenticator $loginAuthenticator = null;

    private WorldManager $worldManager;

    private BlockStateRegistry $blockStateRegistry;

    private BlockTranslator $blockTranslator;

    private ItemTypeDictionary $itemTypeDictionary;

    private ?NetherNetTransport $transport = null;

    /** @var array<int, NetworkSession> keyed by transport session ID */
    private array $sessions = [];

    private PermissionManager $permissionManager;

    private OperatorList $operatorList;

    private CommandMap $commandMap;

    private ConsoleCommandSender $consoleSender;

    private ?ConsoleReader $consoleReader = null;

    protected static ?self $instance = null;

    public static function getInstance() : self{
        return self::$instance ?? throw new LogicException("The server has not been created yet");
    }

    public function __construct(string $dataPath){
        $this->dataPath = Path::canonicalize($dataPath);

        if(!Path::isAbsolute($this->dataPath) || !is_dir($this->dataPath)){
            throw new InvalidArgumentException("Server data path must be an existing absolute directory");
        }

        self::$instance = $this;

        $this->startTime = hrtime(true);

        $this->logger = new Logger(Path::join($this->dataPath, "server.log"));

        $this->properties = new Config(Path::join($this->dataPath, "server.properties"), Config::PROPERTIES, self::DEFAULT_PROPERTIES);

        $this->logger->setDebug($this->properties->getBool("debug-logging"));

        $languageCode = $this->properties->getString("language");

        if(!Language::isAvailable($languageCode)){
            throw new UnexpectedValueException("language '{$languageCode}' is not available, use one of: " . implode(", ", array_keys(Language::getAvailable())));
        }

        $this->language = new Language($languageCode);

        $this->logger->info($this->language->translate("server.language", ["language" => $this->language->getName()]));

        $this->packetRegistry = new PacketRegistry();

        $this->packetTranslator = new PacketTranslator($this->packetRegistry);

        $this->packetBatch = new PacketBatch($this->packetTranslator);

        $this->blockStateRegistry = new BlockStateRegistry();

        $this->blockTranslator = new BlockTranslator();

        $this->itemTypeDictionary = new ItemTypeDictionary();

        $this->worldManager = new WorldManager(
            Path::join($this->dataPath, "worlds"),
            new GeneratorFactory($this->blockStateRegistry),
            $this->blockStateRegistry->getVersion(),
            $this->logger
        );

        $this->registerPackets();

        $this->permissionManager = new PermissionManager();
        $this->operatorList = new OperatorList(Path::join($this->dataPath, "ops.json"));
        $this->registerPermissions();

        $this->commandMap = new CommandMap($this);
        $this->consoleSender = new ConsoleCommandSender($this->logger, $this->permissionManager);
        $this->registerCommands();
    }

    public function getPermissionManager() : PermissionManager{
        return $this->permissionManager;
    }

    public function getOperatorList() : OperatorList{
        return $this->operatorList;
    }

    public function getCommandMap() : CommandMap{
        return $this->commandMap;
    }

    public function getConsoleSender() : ConsoleCommandSender{
        return $this->consoleSender;
    }

    /**
     * Runs a command line like "kick Steve griefing". Errors inside a command are logged, never fatal.
     *
     * @return bool false when the command does not exist
     */
    public function dispatchCommand(CommandSender $sender, string $commandLine) : bool{
        try{
            return $this->commandMap->dispatch($sender, $commandLine);
        }catch(Throwable $exception){
            $this->logger->error("Error while running \"{$commandLine}\": " . $exception::class . ": " . $exception->getMessage());
            $this->logger->debug($exception->getTraceAsString());

            $sender->sendMessage(TextFormat::RED . $this->language->translate("command.error"));

            return true;
        }
    }

    /**
     * Sends every player their command list again, e.g. after a command was registered or its
     * arguments changed while players were online.
     */
    public function updateCommands() : void{
        foreach($this->getInGamePlayers() as $player){
            $player->getNetworkSession()->sendAvailableCommands();
        }
    }

    /**
     * Sends the new values of a soft enum to every player, after CommandEnum::setValues().
     */
    public function updateSoftEnum(CommandEnum $enum) : void{
        if(!$enum->isSoft()){
            throw new InvalidArgumentException("Enum \"{$enum->getName()}\" is not a soft enum");
        }

        $packet = UpdateSoftEnumPacket::create($enum->getName(), $enum->getValues(), UpdateSoftEnumPacket::TYPE_SET);

        foreach($this->getInGamePlayers() as $player){
            $player->getNetworkSession()->sendDataPacket($packet);
        }
    }

    public function getLogger() : Logger{
        return $this->logger;
    }

    public function getLanguage() : Language{
        return $this->language;
    }

    public function getBlockStateRegistry() : BlockStateRegistry{
        return $this->blockStateRegistry;
    }

    public function getBlockTranslator() : BlockTranslator{
        return $this->blockTranslator;
    }

    public function getItemTypeDictionary() : ItemTypeDictionary{
        return $this->itemTypeDictionary;
    }

    public function getWorldManager() : WorldManager{
        return $this->worldManager;
    }

    public function getDataPath() : string{
        return $this->dataPath;
    }

    public function getPacketRegistry() : PacketRegistry{
        return $this->packetRegistry;
    }

    public function getPacketTranslator() : PacketTranslator{
        return $this->packetTranslator;
    }

    public function getPacketBatch() : PacketBatch{
        return $this->packetBatch;
    }

    public function getTransport() : NetherNetTransport{
        return $this->transport ?? throw new LogicException("The server is not running");
    }

    public function getLoginAuthenticator() : LoginAuthenticator{
        return $this->loginAuthenticator ?? throw new LogicException("The server is not running");
    }

    public function getServerName() : string{
        return $this->serverName;
    }

    public function getMaxPlayers() : int{
        return $this->maxPlayers;
    }

    /**
     * The largest chunk radius sent to players, whatever they set in their own settings.
     */
    public function getViewDistance() : int{
        return $this->viewDistance;
    }

    public function getDefaultGameMode() : GameMode{
        return $this->defaultGameMode;
    }

    /**
     * @return array<int, NetworkSession>
     */
    public function getSessions() : array{
        return $this->sessions;
    }

    /**
     * @return list<Player> players that are logged in (still joining or in game)
     */
    public function getOnlinePlayers() : array{
        $players = [];

        foreach($this->sessions as $session){
            $player = $session->getPlayer();

            if($player !== null && $session->isConnected()){
                $players[] = $player;
            }
        }

        return $players;
    }

    /**
     * @return list<Player> players that have finished joining and are in a world
     */
    public function getInGamePlayers() : array{
        $players = [];

        foreach($this->sessions as $session){
            $player = $session->getPlayer();

            if($player !== null && $session->getState() === NetworkSession::STATE_IN_GAME){
                $players[] = $player;
            }
        }

        return $players;
    }

    /**
     * Finds an in-game player by name, ignoring case. If nobody has that exact name, the player whose
     * name starts with it and is closest in length is returned, so "kick ste" finds Steve.
     */
    public function getPlayerByPrefix(string $name) : ?Player{
        $name = strtolower($name);
        $found = null;
        $closest = PHP_INT_MAX;

        foreach($this->getInGamePlayers() as $player){
            $playerName = strtolower($player->getName());

            if($playerName === $name){
                return $player;
            }

            if(str_starts_with($playerName, $name) && strlen($playerName) - strlen($name) < $closest){
                $found = $player;
                $closest = strlen($playerName) - strlen($name);
            }
        }

        return $found;
    }

    /**
     * Called by NetworkSession once a player has loaded the world: everyone gets the new player's
     * player-list entry (with skin), the new player gets everyone else's, then they see each other.
     */
    public function onPlayerJoin(Player $player) : void{
        $operators = $this->operatorList->getAll();

        if(isset($operators[$player->getXuid()]) && $operators[$player->getXuid()] !== $player->getName()){
            $this->operatorList->add($player->getXuid(), $player->getName());
        }

        $player->getNetworkSession()->sendAvailableCommands();
        $others = [];

        foreach($this->getInGamePlayers() as $other){
            if($other !== $player){
                $others[] = $other;
            }
        }

        $player->getNetworkSession()->sendPlayerListAdd([...$others, $player]);

        foreach($others as $other){
            $other->getNetworkSession()->sendPlayerListAdd([$player]);

            if($other->getWorld() === $player->getWorld()){
                $other->spawnTo($player);
                $player->spawnTo($other);
            }
        }
        $this->broadcastTranslation(TextFormat::YELLOW . "%multiplayer.player.joined", [$player->getName()]);
    }

    /**
     * Called by NetworkSession when an in-game player disconnects, before the player is closed.
     */
    public function onPlayerQuit(Player $player) : void{
        $player->despawnFromAll();
        $this->broadcastTranslation(TextFormat::YELLOW . "%multiplayer.player.left", [$player->getName()], $player);

        foreach($this->getInGamePlayers() as $other){
            if($other !== $player){
                $other->getNetworkSession()->sendPlayerListRemove([$player]);
            }
        }
    }

    /**
     * Called by NetworkSession with an already cleaned-up chat message.
     */
    public function onPlayerChat(Player $player, string $message) : void{
        $line = "<" . $player->getName() . "> " . $message;

        $this->logger->info($line);
        $this->broadcastMessage($line);
    }

    /**
     * Sends a plain message to every player in the game and returns how many got it.
     */
    public function broadcastMessage(string $message) : int{
        $players = $this->getInGamePlayers();

        foreach($players as $player){
            $player->sendMessage($message);
        }

        return count($players);
    }

    /**
     * Sends a message every client translates into its own language.
     *
     * @param list<string> $parameters
     * @param Player|null  $except     a player who should not get it (e.g. the one leaving)
     */
    public function broadcastTranslation(string $message, array $parameters = [], ?Player $except = null) : void{
        foreach($this->getInGamePlayers() as $player){
            if($player !== $except){
                $player->sendTranslation($message, $parameters);
            }
        }
    }

    public function broadcastAnnouncement(string $source, string $message) : void{
        $this->logger->info("[{$source}] {$message}");

        foreach($this->getInGamePlayers() as $player){
            $player->sendAnnouncement($source, $message);
        }
    }

    public function broadcastPopup(string $message) : void{
        foreach($this->getInGamePlayers() as $player){
            $player->sendPopup($message);
        }
    }

    public function broadcastTip(string $message) : void{
        foreach($this->getInGamePlayers() as $player){
            $player->sendTip($message);
        }
    }

    /**
     * @see Player::sendTitle()
     */
    public function broadcastTitle(string $title, string $subtitle = "", int $fadeIn = -1, int $stay = -1, int $fadeOut = -1) : void{
        foreach($this->getInGamePlayers() as $player){
            $player->sendTitle($title, $subtitle, $fadeIn, $stay, $fadeOut);
        }
    }

    public function broadcastToast(string $title, string $body) : void{
        foreach($this->getInGamePlayers() as $player){
            $player->sendToast($title, $body);
        }
    }

    /**
     * Sessions that passed login, including players still loading. Used for the max-players check.
     */
    public function getOnlinePlayerCount() : int{
        $count = 0;

        foreach($this->sessions as $session){
            if($session->getIdentity() !== null && $session->isConnected()){
                ++$count;
            }
        }

        return $count;
    }

    public function getCurrentTick() : int{
        return $this->currentTick;
    }

    public function isRunning() : bool{
        return $this->running;
    }

    private function registerPackets() : void{
        foreach([RequestNetworkSettingsPacket::class,
            NetworkSettingsPacket::class,
            LoginPacket::class,
            PlayStatusPacket::class,
            DisconnectPacket::class,
            ResourcePacksInfoPacket::class,
            ResourcePackStackPacket::class,
            ResourcePackClientResponsePacket::class,
            JigsawStructureDataPacket::class,
            VoxelShapesPacket::class,
            StartGamePacket::class,
            ItemRegistryPacket::class,
            RequestChunkRadiusPacket::class,
            ChunkRadiusUpdatedPacket::class,
            CreativeContentPacket::class,
            NetworkChunkPublisherUpdatePacket::class,
            LevelChunkPacket::class,
            SetLocalPlayerAsInitializedPacket::class,
            PlayerAuthInputPacket::class,
            PlayerListPacket::class,
            AddPlayerPacket::class,
            RemoveActorPacket::class,
            MoveActorAbsolutePacket::class,
            TextPacket::class,
            SetTitlePacket::class,
            ToastRequestPacket::class,
            AvailableCommandsPacket::class,
            CommandRequestPacket::class,
            UpdateSoftEnumPacket::class
        ] as $packet){
            $this->packetRegistry->register($packet);
        }
    }

    private function registerPermissions() : void{
        $permissions = [];

        foreach([
            "help" => true,
            "list" => true,
            "tell" => true,
            "version" => true,
            "say" => "op",
            "kick" => "op",
            "title" => "op",
            "op" => "op",
            "deop" => "op",
            "save-all" => "op",
            "stop" => "op"
        ] as $command => $default){
            $permissions["raspberry.command.{$command}"] = [
                "description" => $this->language->translate("permission.command", ["command" => $command]),
                "default" => $default
            ];
        }

        $this->permissionManager->registerAll($permissions);
    }

    private function registerCommands() : void{
        $this->commandMap->registerAllCommands([
            new HelpCommand(),
            new ListCommand(),
            new SayCommand(),
            new TellCommand(),
            new KickCommand(),
            new TitleCommand(),
            new OpCommand(),
            new DeopCommand(),
            new SaveAllCommand(),
            new StopCommand(),
            new VersionCommand()
        ]);
    }

    public function start() : void{
        if($this->running){
            throw new LogicException("RaspberryX is already running");
        }

        $serverName = $this->properties->getString("server-name");
        $serverIp = $this->properties->getString("server-ip");
        $serverPort = $this->properties->getInt("server-port");
        $maxPlayers = $this->properties->getInt("max-players");
        $gameMode = GameMode::fromString($this->properties->getString("gamemode"));
        $viewDistance = $this->properties->getInt("view-distance");
        $worldName = $this->properties->getString("world-name");
        $worldGenerator = $this->properties->getString("world-generator");
        $flatLayers = $this->properties->getString("flat-layers");
        $spawnChunkRadius = $this->properties->getInt("spawn-chunk-radius");
        $autoSaveInterval = $this->properties->getInt("auto-save-interval");

        if($serverName === ""){
            throw new UnexpectedValueException("server-name cannot be empty");
        }

        if(filter_var($serverIp, FILTER_VALIDATE_IP) === false){
            throw new UnexpectedValueException("server-ip must be a valid IPv4 or IPv6 address");
        }

        if($serverPort < 1 || $serverPort > 65535){
            throw new UnexpectedValueException("server-port must be between 1 and 65535");
        }

        if($maxPlayers < 1){
            throw new UnexpectedValueException("max-players must be at least 1");
        }

        if($gameMode === null){
            throw new UnexpectedValueException("gamemode must be survival, creative, adventure or spectator");
        }

        if($viewDistance < 2 || $viewDistance > 32){
            throw new UnexpectedValueException("view-distance must be between 2 and 32 chunks");
        }

        if(!WorldManager::isValidName($worldName)){
            throw new UnexpectedValueException("world-name must be 1-64 letters, digits, spaces, '_' or '-', starting with a letter or digit");
        }

        if($spawnChunkRadius < 0 || $spawnChunkRadius > 32){
            throw new UnexpectedValueException("spawn-chunk-radius must be between 0 and 32");
        }

        if($autoSaveInterval < 0){
            throw new UnexpectedValueException("auto-save-interval must be 0 (off) or a number of seconds");
        }

        $this->serverName = $serverName;
        $this->maxPlayers = $maxPlayers;
        $this->defaultGameMode = $gameMode;
        $this->viewDistance = $viewDistance;
        $this->autoSaveTicks = $autoSaveInterval * self::TICKS_PER_SECOND;

        $this->logger->info($this->language->translate("server.starting", [
            "version" => ProtocolInfo::MINECRAFT_VERSION,
            "protocol" => ProtocolInfo::CURRENT_PROTOCOL
        ]));

        $this->logger->info($this->language->translate("server.blocks", [
            "count" => $this->blockStateRegistry->getBlockCount(),
            "version" => $this->blockStateRegistry->getVersion()
        ]));

        $this->loadDefaultWorld($worldName, $worldGenerator, $flatLayers, $spawnChunkRadius);

        $listenAddress = str_contains($serverIp, ":") ? "[{$serverIp}]:{$serverPort}" : "{$serverIp}:{$serverPort}";

        $this->registerSignalHandlers();

        $identity = new NetherNetIdentity(Path::join($this->dataPath, "data", "server-identity.pem"));

        $this->loginAuthenticator = new LoginAuthenticator($identity->getTrustedKeys());

        $this->transport = new NetherNetTransport(
            identity: $identity,

            serverInfo: [
                "name" => $serverName,
                "protocol" => ProtocolInfo::CURRENT_PROTOCOL,
                "version" => ProtocolInfo::MINECRAFT_VERSION_NETWORK,
                "level" => $worldName,
                "players" => 0,
                "maxPlayers" => $maxPlayers,
                "gameType" => $gameMode->value
            ],

            onPayload: function(int $sessionId, string $payload, bool $reliable, stdClass $claims) : void{
                $session = $this->sessions[$sessionId] ??= new NetworkSession($this, $sessionId, $claims);
                $session->handlePayload($payload, $reliable);
            },

            logger: $this->logger,

            onClose: function(int $sessionId, string $reason) : void{
                $session = $this->sessions[$sessionId] ?? null;

                if($session === null){
                    return;
                }

                unset($this->sessions[$sessionId]);
                $session->onClose($reason);
            }
        );

        $this->running = true;

        try{
            $this->logger->info($this->language->translate("server.opening", ["address" => $listenAddress]));

            $this->transport->start($listenAddress);

            $this->logger->info($this->language->translate("server.software", ["name" => self::NAME, "version" => self::VERSION]));
            $this->logger->info($this->language->translate("server.license", ["name" => self::NAME, "license" => self::LICENSE]));
            $this->logger->info($this->language->translate("server.done", ["time" => sprintf("%.3f", (hrtime(true) - $this->startTime) / 1e9)]));

            $this->consoleReader = new ConsoleReader($this->logger, function(string $line) : void{
                $this->dispatchCommand($this->consoleSender, $line);
            });

            $this->consoleReader->start();

            $this->runLoop();
        }finally{
            $this->running = false;

            $this->consoleReader?->close();
            $this->disconnectAll();
            $this->transport->shutdown();
            $this->shutdown();
        }
    }

    /**
     * Loads the default world from disk, or creates it with the generator from server.properties.
     * An existing world always keeps the generator it was created with.
     */
    private function loadDefaultWorld(string $worldName, string $generatorName, string $generatorSettings, int $spawnChunkRadius) : void{
        if($this->worldManager->hasDefaultWorld()){
            return;
        }

        if($this->worldManager->worldExists($worldName)){
            $world = $this->worldManager->loadWorld($worldName);

            $this->logger->info($this->language->translate("server.world.loading", ["world" => $world->getName(), "generator" => $world->getGenerator()->getName()]));
        }else{
            try{
                $world = $this->worldManager->createWorld($worldName, $generatorName, $generatorSettings);
            }catch(InvalidArgumentException $exception){
                throw new UnexpectedValueException("Invalid world settings in server.properties: " . $exception->getMessage(), 0, $exception);
            }

            $this->logger->info($this->language->translate("server.world.creating", ["world" => $world->getName(), "generator" => $world->getGenerator()->getName()]));
        }

        $this->worldManager->setDefaultWorld($world);

        $start = hrtime(true);
        $result = $world->loadChunksAround(0, 0, $spawnChunkRadius);

        if($result["generated"] > 0){
            $world->save();//keep newly generated spawn chunks even if startup fails later
        }

        $this->logger->info($this->language->translate("server.world.spawn", [
            "loaded" => $result["loaded"],
            "generated" => $result["generated"],
            "time" => sprintf("%.1f", (hrtime(true) - $start) / 1e6)
        ]));
    }

    /**
     * @return int number of chunks written
     */
    private function saveWorlds(bool $close) : int{
        $start = hrtime(true);
        $saved = $close ? $this->worldManager->close() : $this->worldManager->saveAll();

        $message = $this->language->translate("server.saved", ["count" => $saved, "time" => sprintf("%.1f", (hrtime(true) - $start) / 1e6)]);

        if($close){
            $this->logger->info($message);
        }else{
            $this->logger->debug($message);//auto-save: only visible with debug-logging
        }

        return $saved;
    }

    public function stop() : void{
        $this->running = false;

        Loop::stop();
    }

    private function runLoop() : void{
        $loop = Loop::get();
        $failure = null;

        $networkTimer = $loop->addPeriodicTimer(
            0.01,
            function() use (&$failure, $loop) : void{
                if(!$this->running){
                    $loop->stop();
                    return;
                }

                try{
                    $this->transport?->poll();
                }catch(Throwable $exception){
                    $failure = $exception;
                    $this->stop();
                }
            }
        );

        $tickTimer = $loop->addPeriodicTimer(1 / self::TICKS_PER_SECOND, function() use (&$failure) : void{
            if(!$this->running){
                return;
            }

            try{
                $this->tick();
            }catch(Throwable $exception){
                $failure = $exception;
                $this->stop();
            }
        }
        );

        try{
            $loop->run();
        }finally{
            $loop->cancelTimer($networkTimer);
            $loop->cancelTimer($tickTimer);
        }

        if($failure !== null){
            throw $failure;
        }
    }

    private function tick() : void{
        ++$this->currentTick;

        $now = hrtime(true);

        foreach($this->sessions as $session){
            $session->tick($this->currentTick, $now);
        }

        if($this->autoSaveTicks > 0 && $this->currentTick % $this->autoSaveTicks === 0){
            $this->saveWorlds(false);
        }
    }

    /**
     * Tells every client the server is stopping and gives the transport one poll to send it.
     */
    private function disconnectAll() : void{
        if($this->sessions === []){
            return;
        }

        $message = $this->language->translate("disconnect.serverStopped");

        foreach($this->sessions as $session){
            $session->disconnect($message);
        }

        try{
            $this->transport?->poll();
        }catch(Throwable){
            //shutting down anyway
        }
    }

    private function registerSignalHandlers() : void{
        //Windows has no pcntl: without this, Ctrl+C kills PHP before the server can shut down
        if(function_exists("sapi_windows_set_ctrl_handler")){
            sapi_windows_set_ctrl_handler(function(int $event) : void{
                $this->stop();
            });

            return;
        }

        if(!function_exists("pcntl_async_signals") || !function_exists("pcntl_signal")){
            return;
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGINT, function(int $signal) : void{
            $this->stop();
        });

        pcntl_signal(SIGTERM, function(int $signal) : void{
            $this->stop();
        });
    }

    private function shutdown() : void{
        $this->logger->info($this->language->translate("server.stopping"));
        $this->logger->info($this->language->translate("server.saving"));
        $this->saveWorlds(true);
        $this->logger->info($this->language->translate("server.stopped"));
        $this->logger->close();
    }
}