<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types;

use raspberry\entity\Skin;

use raspberry\utils\UUID;

class PlayerListEntry{

    public bool $add;

    public UUID $uuid;

    public int $actorUniqueId = 0;

    public string $username = "";

    public string $xuid = "";

    /** DeviceOS from the player's client data, -1 when unknown */
    public int $buildPlatform = -1;

    public ?Skin $skin = null;

    private function __construct(bool $add, UUID $uuid){
        $this->add = $add;
        $this->uuid = $uuid;
    }

    public static function add(UUID $uuid, int $actorUniqueId, string $username, string $xuid, int $buildPlatform, Skin $skin) : self{
        $entry = new self(true, $uuid);
        $entry->actorUniqueId = $actorUniqueId;
        $entry->username = $username;
        $entry->xuid = $xuid;
        $entry->buildPlatform = $buildPlatform;
        $entry->skin = $skin;
        return $entry;
    }

    public static function remove(UUID $uuid) : self{
        return new self(false, $uuid);
    }
}