<?php

declare(strict_types=1);

namespace raspberry\player;

use raspberry\entity\Human;
use raspberry\entity\Skin;

use raspberry\math\Vector3;

use raspberry\network\mcpe\auth\AuthenticatedLogin;
use raspberry\network\mcpe\NetworkSession;
use raspberry\network\mcpe\protocol\SetTitlePacket;
use raspberry\network\mcpe\protocol\TextPacket;
use raspberry\network\mcpe\protocol\ToastRequestPacket;

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

        //the subtitle only shows once the title is sent, so the title goes last
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