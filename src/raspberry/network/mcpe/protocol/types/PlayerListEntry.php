<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types;

use raspberry\entity\Skin;

use raspberry\utils\Uuid;

class PlayerListEntry{

    public bool $add;

    public Uuid $uuid;

    public int $actorUniqueId = 0;

    public string $username = "";

    public string $xuid = "";

    /** DeviceOS from the player's client data, -1 when unknown */
    public int $buildPlatform = -1;

    public ?Skin $skin = null;

    private function __construct(bool $add, Uuid $uuid){
        $this->add = $add;
        $this->uuid = $uuid;
    }

    public static function add(Uuid $uuid, int $actorUniqueId, string $username, string $xuid, int $buildPlatform, Skin $skin) : self{
        $entry = new self(true, $uuid);
        $entry->actorUniqueId = $actorUniqueId;
        $entry->username = $username;
        $entry->xuid = $xuid;
        $entry->buildPlatform = $buildPlatform;
        $entry->skin = $skin;
        return $entry;
    }

    public static function remove(Uuid $uuid) : self{
        return new self(false, $uuid);
    }
}