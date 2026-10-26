<?php

declare(strict_types=1);

namespace raspberry\player;

use raspberry\entity\Human;
use raspberry\entity\Skin;

use raspberry\math\Vector3;

use raspberry\network\mcpe\auth\AuthenticatedLogin;
use raspberry\network\mcpe\NetworkSession;

use raspberry\utils\Uuid;

use raspberry\world\World;

use function is_int;

class Player extends Human{

    private NetworkSession $session;

    private AuthenticatedLogin $identity;

    private GameMode $gameMode;

    private bool $spawned = false;

    public function __construct(World $world, Vector3 $position, NetworkSession $session, AuthenticatedLogin $identity, GameMode $gameMode, Skin $skin){
        $this->session = $session;
        $this->identity = $identity;
        $this->gameMode = $gameMode;

        parent::__construct($world, $position, $skin, Uuid::fromXuid($identity->xuid));
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

    /**
     * The player's device type from the login data (Bedrock's DeviceOS number), -1 when unknown.
     */
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
     * TODO: tell the client (SetPlayerGameTypePacket) once gamemode changes at runtime are supported
     */
    public function setGameMode(GameMode $gameMode) : void{
        $this->gameMode = $gameMode;
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