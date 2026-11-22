<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandSender;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

use function is_array;
use function json_decode;
use function str_starts_with;

/**
 * A JSON object or array, like {"rawtext":[{"text":"hi"}]}. The command gets it decoded.
 */
class JsonArgument extends Argument{

    public function getNetworkType() : int{
        return AvailableCommandsPacket::ARG_TYPE_JSON;
    }

    public function getTypeName() : string{
        return "json";
    }

    public function accepts(string $text, CommandSender $sender) : bool{
        return (str_starts_with($text, "{") || str_starts_with($text, "[")) && is_array(json_decode($text, true, 64));
    }

    /**
     * @return array<mixed>
     */
    public function read(string $text, CommandSender $sender) : array{
        return json_decode($text, true, 64);
    }
}