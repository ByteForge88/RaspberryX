<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\command;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\utils\Uuid;

class CommandOriginData{

    public const string TYPE_PLAYER = "player";
    public const string TYPE_BLOCK = "commandblock";
    public const string TYPE_MINECART_BLOCK = "minecartcommandblock";
    public const string TYPE_DEV_CONSOLE = "devconsole";
    public const string TYPE_TEST = "test";
    public const string TYPE_AUTOMATION_PLAYER = "automationplayer";
    public const string TYPE_CLIENT_AUTOMATION = "clientautomation";
    public const string TYPE_DEDICATED_SERVER = "dedicatedserver";
    public const string TYPE_ENTITY = "entity";
    public const string TYPE_VIRTUAL = "virtual";
    public const string TYPE_GAME_ARGUMENT = "gameargument";
    public const string TYPE_ENTITY_SERVER = "entityserver";
    public const string TYPE_PRECOMPILED = "precompiled";
    public const string TYPE_GAME_DIRECTOR_ENTITY_SERVER = "gamedirectorentityserver";
    public const string TYPE_SCRIPTING = "scripting";
    public const string TYPE_EXECUTE_CONTEXT = "executecontext";

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