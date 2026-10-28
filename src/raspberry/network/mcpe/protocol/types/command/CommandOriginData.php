<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\command;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\utils\Uuid;

class CommandOriginData{

    public const TYPE_PLAYER = "player";
    public const TYPE_BLOCK = "commandblock";
    public const TYPE_MINECART_BLOCK = "minecartcommandblock";
    public const TYPE_DEV_CONSOLE = "devconsole";
    public const TYPE_TEST = "test";
    public const TYPE_AUTOMATION_PLAYER = "automationplayer";
    public const TYPE_CLIENT_AUTOMATION = "clientautomation";
    public const TYPE_DEDICATED_SERVER = "dedicatedserver";
    public const TYPE_ENTITY = "entity";
    public const TYPE_VIRTUAL = "virtual";
    public const TYPE_GAME_ARGUMENT = "gameargument";
    public const TYPE_ENTITY_SERVER = "entityserver";
    public const TYPE_PRECOMPILED = "precompiled";
    public const TYPE_GAME_DIRECTOR_ENTITY_SERVER = "gamedirectorentityserver";
    public const TYPE_SCRIPTING = "scripting";
    public const TYPE_EXECUTE_CONTEXT = "executecontext";

    public string $type = self::TYPE_PLAYER;

    public Uuid $uuid;

    public string $requestId = "";

    public int $playerActorUniqueId = 0;

    public static function read(BinaryReader $reader) : self{
        $origin = new self();
        $origin->type = $reader->readString(64);
        $origin->uuid = $reader->readUuid();
        $origin->requestId = $reader->readString(256);
        $origin->playerActorUniqueId = $reader->readLong();

        return $origin;
    }

    public function write(BinaryWriter $writer) : void{
        $writer->writeString($this->type, 64);
        $writer->writeUuid($this->uuid);
        $writer->writeString($this->requestId, 256);
        $writer->writeLong($this->playerActorUniqueId);
    }
}