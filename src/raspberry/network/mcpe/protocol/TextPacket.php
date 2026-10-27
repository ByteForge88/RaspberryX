<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

use function count;

class TextPacket implements Packet{

    public const TYPE_RAW = 0;
    public const TYPE_CHAT = 1;
    public const TYPE_TRANSLATION = 2;
    public const TYPE_POPUP = 3;
    public const TYPE_JUKEBOX_POPUP = 4;
    public const TYPE_TIP = 5;
    public const TYPE_SYSTEM = 6;
    public const TYPE_WHISPER = 7;
    public const TYPE_ANNOUNCEMENT = 8;
    public const TYPE_OBJECT_WHISPER = 9;
    public const TYPE_OBJECT = 10;
    public const TYPE_OBJECT_ANNOUNCEMENT = 11;

    private const CATEGORY_MESSAGE_ONLY = 0;
    private const CATEGORY_AUTHORED_MESSAGE = 1;
    private const CATEGORY_MESSAGE_WITH_PARAMETERS = 2;

    private const MAX_PARAMETERS = 4;

    public int $type = self::TYPE_RAW;

    /** true when the client should translate $message (a "%key") */
    public bool $needsTranslation = false;

    public string $sourceName = "";

    public string $message = "";

    /** @var list<string> */
    public array $parameters = [];

    public string $xuid = "";

    public string $platformChatId = "";

    public ?string $filteredMessage = null;

    public static function raw(string $message) : self{
        return self::create(self::TYPE_RAW, $message);
    }

    /**
     * A chat line shown as "<sourceName> message".
     */
    public static function chat(string $sourceName, string $message, string $xuid = "") : self{
        $packet = self::create(self::TYPE_CHAT, $message, $sourceName);
        $packet->xuid = $xuid;
        return $packet;
    }

    /**
     * A message the client translates into its own language, e.g. ("%multiplayer.player.joined", ["Steve"]).
     *
     * @param list<string> $parameters
     */
    public static function translation(string $message, array $parameters = []) : self{
        $packet = self::create(self::TYPE_TRANSLATION, $message);
        $packet->needsTranslation = true;
        $packet->parameters = $parameters;
        return $packet;
    }

    /**
     * Text above the hotbar, shown for a few seconds.
     */
    public static function popup(string $message) : self{
        return self::create(self::TYPE_POPUP, $message);
    }

    /**
     * Text above the hotbar, like the "Now playing" message of a jukebox. Can be a "%key" with parameters.
     *
     * @param list<string> $parameters
     */
    public static function jukeboxPopup(string $message, array $parameters = []) : self{
        $packet = self::create(self::TYPE_JUKEBOX_POPUP, $message);
        $packet->needsTranslation = true;
        $packet->parameters = $parameters;
        return $packet;
    }

    /**
     * Text in the middle of the screen, above the popup.
     */
    public static function tip(string $message) : self{
        return self::create(self::TYPE_TIP, $message);
    }

    /**
     * A chat message that is not hidden when the player turns chat off.
     */
    public static function system(string $message) : self{
        return self::create(self::TYPE_SYSTEM, $message);
    }

    /**
     * A private message, shown as "sourceName whispers to you: message".
     */
    public static function whisper(string $sourceName, string $message) : self{
        return self::create(self::TYPE_WHISPER, $message, $sourceName);
    }

    /**
     * Shown as "[sourceName] message", like /say.
     */
    public static function announcement(string $sourceName, string $message) : self{
        return self::create(self::TYPE_ANNOUNCEMENT, $message, $sourceName);
    }

    private static function create(int $type, string $message, string $sourceName = "") : self{
        $packet = new self();
        $packet->type = $type;
        $packet->message = $message;
        $packet->sourceName = $sourceName;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::TEXT_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        if($this->message === ""){
            throw new UnexpectedValueException("A text message cannot be empty");
        }

        $category = self::categoryOf($this->type);

        $writer->writeBool($this->needsTranslation);
        $writer->writeByte($category);
        $writer->writeByte($this->type);

        if($category === self::CATEGORY_AUTHORED_MESSAGE){
            $writer->writeString($this->sourceName, 256);
        }

        $writer->writeString($this->message, 65536);

        if($category === self::CATEGORY_MESSAGE_WITH_PARAMETERS){
            if(count($this->parameters) > self::MAX_PARAMETERS){
                throw new UnexpectedValueException("A text message can have at most " . self::MAX_PARAMETERS . " parameters");
            }

            $writer->writeUnsignedVarInt(count($this->parameters));

            foreach($this->parameters as $parameter){
                $writer->writeString($parameter);
            }
        }

        $writer->writeString($this->xuid, 64);
        $writer->writeString($this->platformChatId, 256);
        $writer->writeBool($this->filteredMessage !== null);

        if($this->filteredMessage !== null){
            $writer->writeString($this->filteredMessage, 65536);
        }
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->needsTranslation = $reader->readBool();
        $category = $reader->readByte();
        $this->type = $reader->readByte();

        if($category !== self::categoryOf($this->type)){
            throw new UnexpectedValueException("Text type {$this->type} does not belong to category {$category}");
        }

        if($category === self::CATEGORY_AUTHORED_MESSAGE){
            $this->sourceName = $reader->readString(256);
        }

        $this->message = $reader->readString(65536);

        if($category === self::CATEGORY_MESSAGE_WITH_PARAMETERS){
            $count = $reader->readUnsignedVarInt();

            if($count > self::MAX_PARAMETERS){
                throw new UnexpectedValueException("Too many text parameters: {$count}");
            }

            for($i = 0; $i < $count; ++$i){
                $this->parameters[] = $reader->readString();
            }
        }

        $this->xuid = $reader->readString(64);
        $this->platformChatId = $reader->readString(256);
        $this->filteredMessage = $reader->readBool() ? $reader->readString(65536) : null;
    }

    private static function categoryOf(int $type) : int{
        return match($type){
            self::TYPE_CHAT, self::TYPE_WHISPER, self::TYPE_ANNOUNCEMENT => self::CATEGORY_AUTHORED_MESSAGE,
            self::TYPE_TRANSLATION, self::TYPE_POPUP, self::TYPE_JUKEBOX_POPUP => self::CATEGORY_MESSAGE_WITH_PARAMETERS,
            self::TYPE_RAW, self::TYPE_TIP, self::TYPE_SYSTEM, self::TYPE_OBJECT_WHISPER, self::TYPE_OBJECT, self::TYPE_OBJECT_ANNOUNCEMENT => self::CATEGORY_MESSAGE_ONLY,
            default => throw new UnexpectedValueException("Unknown text type {$type}")
        };
    }
}