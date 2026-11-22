<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandSender;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

use function preg_match;
use function preg_match_all;
use function str_ends_with;
use function str_starts_with;
use function substr;
use function trim;

use const PREG_SET_ORDER;

/**
 * Block states like ["facing_direction"=1,"open_bit"=true,"color"="red"]. The command gets
 * [name => value].
 */
class BlockStatesArgument extends Argument{

    private const string ENTRY = '\s*"((?:[^"\\\\]|\\\\.)*)"\s*[=:]\s*("(?:[^"\\\\]|\\\\.)*"|[^,\]\s]+)\s*';

    public function getNetworkType() : int{
        return AvailableCommandsPacket::ARG_TYPE_BLOCK_STATES;
    }

    public function getTypeName() : string{
        return "block states";
    }

    public function accepts(string $text, CommandSender $sender) : bool{
        if(!str_starts_with($text, "[") || !str_ends_with($text, "]")){
            return false;
        }

        $inner = trim(substr($text, 1, -1));

        if($inner === ""){
            return true;
        }

        if(preg_match('/^' . self::ENTRY . '(,' . self::ENTRY . ')*$/Ds', $inner) !== 1){
            return false;
        }

        preg_match_all('/' . self::ENTRY . '(?:,|$)/s', $inner, $matches, PREG_SET_ORDER);

        foreach($matches as $match){
            if(self::value($match[2]) === null){
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, bool|int|float|string>
     */
    public function read(string $text, CommandSender $sender) : array{
        $inner = trim(substr($text, 1, -1));
        $states = [];

        if($inner === ""){
            return $states;
        }

        preg_match_all('/' . self::ENTRY . '(?:,|$)/s', $inner, $matches, PREG_SET_ORDER);

        foreach($matches as $match){
            $states[$match[1]] = self::value($match[2]);
        }

        return $states;
    }

    private static function value(string $value) : bool|int|float|string|null{
        return match(true){
            $value === "true" => true,
            $value === "false" => false,
            str_starts_with($value, '"') => substr($value, 1, -1),
            preg_match(IntArgument::PATTERN, $value) === 1 => (int) $value,
            preg_match('/^[+-]?(\d+\.\d*|\.\d+)$/D', $value) === 1 => (float) $value,
            default => null
        };
    }
}