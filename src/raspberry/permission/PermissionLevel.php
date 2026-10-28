<?php

declare(strict_types=1);

namespace raspberry\permission;

use InvalidArgumentException;

use function is_bool;
use function is_string;
use function strtolower;

enum PermissionLevel : string{

    /** every player ("default: true") */
    case PUBLIC = "true";

    /** server operators ("default: op") */
    case OPERATOR = "op";

    /** only the server console ("default: console") */
    case CONSOLE = "console";

    /**
     * Reads a "default:" value. YAML turns an unquoted true into a boolean, so that is accepted too.
     */
    public static function fromValue(mixed $value) : self{
        if($value === true){
            return self::PUBLIC;
        }

        $level = is_string($value) ? self::tryFrom(strtolower($value)) : null;

        if($level === null){
            $shown = is_bool($value) ? "false" : (is_string($value) ? "\"{$value}\"" : "this value");
            throw new InvalidArgumentException("A permission default must be true, op or console, not {$shown}");
        }

        return $level;
    }

    /**
     * True when a sender at this level gets a permission whose default is $required.
     */
    public function includes(self $required) : bool{
        return $this->rank() >= $required->rank();
    }

    private function rank() : int{
        return match($this){
            self::PUBLIC => 0,
            self::OPERATOR => 1,
            self::CONSOLE => 2
        };
    }
}