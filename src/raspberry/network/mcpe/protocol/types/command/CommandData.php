<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\command;

class CommandData{

    public const PERMISSION_ANY = "any";
    public const PERMISSION_GAME_DIRECTORS = "gamedirectors";
    public const PERMISSION_ADMIN = "admin";
    public const PERMISSION_HOST = "host";
    public const PERMISSION_OWNER = "owner";
    public const PERMISSION_INTERNAL = "internal";

    public string $name;

    public string $description;

    public int $flags = 0;

    /** the server checks permissions itself, so commands are sent as usable by anyone */
    public string $permission = self::PERMISSION_ANY;

    /** index of the enum holding the aliases in AvailableCommandsPacket::$enums, -1 for none */
    public int $aliasesEnumIndex = -1;

    /** @var list<int> */
    public array $chainedSubCommandIndices = [];

    /** @var list<CommandOverloadData> */
    public array $overloads = [];

    public function __construct(string $name, string $description){
        $this->name = $name;
        $this->description = $description;
    }
}