<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandSender;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

use function preg_match;
use function str_starts_with;
use function substr;

/**
 * A number, or a number relative to where the sender is: 90, ~ or ~-15.
 */
class RelativeValueArgument extends Argument{

    public const string PATTERN = '/^(~|~?[+-]?(\d{1,18}(\.\d*)?|\.\d+))$/D';

    public function getNetworkType() : int{
        return AvailableCommandsPacket::ARG_TYPE_VALUE;
    }

    public function getTypeName() : string{
        return "value";
    }

    public function accepts(string $text, CommandSender $sender) : bool{
        return preg_match(self::PATTERN, $text) === 1;
    }

    public function read(string $text, CommandSender $sender) : RelativeValue{
        $relative = str_starts_with($text, "~");

        return new RelativeValue((float) ($relative ? substr($text, 1) : $text), $relative);
    }
}