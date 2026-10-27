<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class SetTitlePacket implements Packet{

    public const TYPE_CLEAR = 0;
    public const TYPE_RESET = 1;
    public const TYPE_SET_TITLE = 2;
    public const TYPE_SET_SUBTITLE = 3;
    public const TYPE_SET_ACTIONBAR = 4;
    public const TYPE_SET_DURATIONS = 5;
    public const TYPE_SET_TITLE_JSON = 6;
    public const TYPE_SET_SUBTITLE_JSON = 7;
    public const TYPE_SET_ACTIONBAR_JSON = 8;

    public int $type = self::TYPE_SET_TITLE;

    public string $text = "";

    /** durations are in ticks (20 per second), only used by TYPE_SET_DURATIONS */
    public int $fadeInTime = 0;

    public int $stayTime = 0;

    public int $fadeOutTime = 0;

    public string $xuid = "";

    public string $platformOnlineId = "";

    public string $filteredText = "";

    public static function title(string $text) : self{
        return self::create(self::TYPE_SET_TITLE, $text);
    }

    public static function subtitle(string $text) : self{
        return self::create(self::TYPE_SET_SUBTITLE, $text);
    }

    public static function actionBar(string $text) : self{
        return self::create(self::TYPE_SET_ACTIONBAR, $text);
    }

    /**
     * Removes the title and subtitle from the screen.
     */
    public static function clear() : self{
        return self::create(self::TYPE_CLEAR);
    }

    /**
     * Clears the title and puts the durations back to the client's defaults.
     */
    public static function reset() : self{
        return self::create(self::TYPE_RESET);
    }

    public static function durations(int $fadeIn, int $stay, int $fadeOut) : self{
        $packet = self::create(self::TYPE_SET_DURATIONS);
        $packet->fadeInTime = $fadeIn;
        $packet->stayTime = $stay;
        $packet->fadeOutTime = $fadeOut;
        return $packet;
    }

    private static function create(int $type, string $text = "") : self{
        $packet = new self();
        $packet->type = $type;
        $packet->text = $text;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::SET_TITLE_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarInt($this->type);
        $writer->writeString($this->text);
        $writer->writeVarInt($this->fadeInTime);
        $writer->writeVarInt($this->stayTime);
        $writer->writeVarInt($this->fadeOutTime);
        $writer->writeString($this->xuid);
        $writer->writeString($this->platformOnlineId);
        $writer->writeString($this->filteredText);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->type = $reader->readVarInt();
        $this->text = $reader->readString();
        $this->fadeInTime = $reader->readVarInt();
        $this->stayTime = $reader->readVarInt();
        $this->fadeOutTime = $reader->readVarInt();
        $this->xuid = $reader->readString();
        $this->platformOnlineId = $reader->readString();
        $this->filteredText = $reader->readString();
    }
}