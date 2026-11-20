<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\command;

class CommandData{

    public const string PERMISSION_ANY = "any";
    public const string PERMISSION_GAME_DIRECTORS = "gamedirectors";
    public const string PERMISSION_ADMIN = "admin";
    public const string PERMISSION_HOST = "host";
    public const string PERMISSION_OWNER = "owner";
    public const string PERMISSION_INTERNAL = "internal";

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