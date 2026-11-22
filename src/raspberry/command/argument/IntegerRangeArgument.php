<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandSender;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

use function preg_match;

/**
 * A number or a range of them: 5, 1..5, 1.. or ..5. The command gets ["min" => ?int, "max" => ?int].
 */
class IntegerRangeArgument extends Argument{

    private const string RANGE = '/^([+-]?\d{1,18})?\.\.([+-]?\d{1,18})?$/D';

    public function getNetworkType() : int{
        return AvailableCommandsPacket::ARG_TYPE_FULL_INTEGER_RANGE;
    }

    public function getTypeName() : string{
        return "integer range";
    }

    public function accepts(string $text, CommandSender $sender) : bool{
        return preg_match(IntArgument::PATTERN, $text) === 1 || ($text !== ".." && preg_match(self::RANGE, $text) === 1);
    }

    /**
     * @return array{min: int|null, max: int|null}
     */
    public function read(string $text, CommandSender $sender) : array{
        if(preg_match(IntArgument::PATTERN, $text) === 1){
            return ["min" => (int) $text, "max" => (int) $text];
        }

        preg_match(self::RANGE, $text, $match);

        return [
            "min" => ($match[1] ?? "") === "" ? null : (int) $match[1],
            "max" => ($match[2] ?? "") === "" ? null : (int) $match[2]
        ];
    }
}