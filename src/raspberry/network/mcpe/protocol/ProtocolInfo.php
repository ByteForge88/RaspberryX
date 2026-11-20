<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

final class ProtocolInfo{

    public const int NEXT_PROTOCOL = 2259;

    public const int CURRENT_PROTOCOL = 2225;

    public const int LAST_PROTOCOL = 2193;

    public const string MINECRAFT_VERSION = "v" . self::MINECRAFT_VERSION_NETWORK;

    public const string MINECRAFT_VERSION_NETWORK = "1.26.60";

}