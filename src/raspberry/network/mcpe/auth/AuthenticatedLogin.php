<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\auth;

use stdClass;

class AuthenticatedLogin{

    public string $username;
    public string $xuid;
    public string $minecraftId;
    public stdClass $clientData;

    public function __construct(string $username, string $xuid, string $minecraftId, stdClass $clientData){
        $this->username = $username;
        $this->xuid = $xuid;
        $this->minecraftId = $minecraftId;
        $this->clientData = $clientData;
    }
}