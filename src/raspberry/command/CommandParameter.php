<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

use InvalidArgumentException;

use function count;
use function in_array;

class CommandParameter{

    /** types that take the rest of the line, so they must be the last parameter */
    public const REST_OF_LINE_TYPES = [
        AvailableCommandsPacket::ARG_TYPE_MESSAGE,
        AvailableCommandsPacket::ARG_TYPE_RAWTEXT,
        AvailableCommandsPacket::ARG_TYPE_COMMAND
    ];

    private string $name;

    private int $type;

    private bool $optional;

    private ?CommandEnum $enum;

    private ?string $postfix;

    private bool $resolve = true;

    public function __construct(string $name, int $type, bool $optional = false, ?CommandEnum $enum = null, ?string $postfix = null){
        if($name === ""){
            throw new InvalidArgumentException("A command parameter needs a name");
        }

        if($postfix !== null && $type !== AvailableCommandsPacket::ARG_TYPE_INT){
            throw new InvalidArgumentException("Only an int parameter can have a postfix");
        }

        $this->name = $name;
        $this->type = $type;
        $this->optional = $optional;
        $this->enum = $enum;
        $this->postfix = $postfix;
    }

    /** an int */
    public static function int(string $name, bool $optional = false) : self{
        return new self($name, AvailableCommandsPacket::ARG_TYPE_INT, $optional);
    }

    /** a float */
    public static function float(string $name, bool $optional = false) : self{
        return new self($name, AvailableCommandsPacket::ARG_TYPE_FLOAT, $optional);
    }

    /** a number that may be relative: "10", "~" or "~-5", as a string */
    public static function value(string $name, bool $optional = false) : self{
        return new self($name, AvailableCommandsPacket::ARG_TYPE_VALUE, $optional);
    }

    /** an int, or "*" */
    public static function wildcardInt(string $name, bool $optional = false) : self{
        return new self($name, AvailableCommandsPacket::ARG_TYPE_WILDCARD_INT, $optional);
    }

    /** =, +=, -=, *=, /=, %=, <, > or >< */
    public static function operator(string $name, bool $optional = false) : self{
        return new self($name, AvailableCommandsPacket::ARG_TYPE_OPERATOR, $optional);
    }

    /** <, <=, =, >= or > */
    public static function compareOperator(string $name, bool $optional = false) : self{
        return new self($name, AvailableCommandsPacket::ARG_TYPE_COMPARE_OPERATOR, $optional);
    }

    /**
     * A player name or a selector (@a, @p, @r, @s, @e, with arguments like @a[r=10]), as the
     * list<Player> it matches, a selector matching nobody stops the command. With $resolve false
     * the command gets the text as typed, e.g. to look up a player who is offline.
     */
    public static function target(string $name, bool $optional = false, bool $resolve = true) : self{
        $parameter = new self($name, AvailableCommandsPacket::ARG_TYPE_TARGET, $optional);
        $parameter->resolve = $resolve;
        return $parameter;
    }

    /** like target, but "*" means every player */
    public static function wildcardTarget(string $name, bool $optional = false) : self{
        return new self($name, AvailableCommandsPacket::ARG_TYPE_WILDCARD_TARGET, $optional);
    }

    public static function filepath(string $name, bool $optional = false) : self{
        return new self($name, AvailableCommandsPacket::ARG_TYPE_FILEPATH, $optional);
    }

    /** 5, 1.5, .5 or 1., as ["min" => ?int, "max" => ?int] */
    public static function integerRange(string $name, bool $optional = false) : self{
        return new self($name, AvailableCommandsPacket::ARG_TYPE_FULL_INTEGER_RANGE, $optional);
    }

    /** slot.weapon.mainhand, slot.armor.head, ... */
    public static function equipmentSlot(string $name, bool $optional = false) : self{
        return new self($name, AvailableCommandsPacket::ARG_TYPE_EQUIPMENT_SLOT, $optional);
    }

    /** one word, or "words in quotes" */
    public static function string(string $name, bool $optional = false) : self{
        return new self($name, AvailableCommandsPacket::ARG_TYPE_STRING, $optional);
    }

    /** x y z of a block, as a Vector3 of whole numbers */
    public static function blockPosition(string $name, bool $optional = false) : self{
        return new self($name, AvailableCommandsPacket::ARG_TYPE_INT_POSITION, $optional);
    }

    /** x y z, as a Vector3 */
    public static function position(string $name, bool $optional = false) : self{
        return new self($name, AvailableCommandsPacket::ARG_TYPE_POSITION, $optional);
    }

    /** the rest of the line, selectors in it are replaced by player names, like in /say */
    public static function message(string $name, bool $optional = false) : self{
        return new self($name, AvailableCommandsPacket::ARG_TYPE_MESSAGE, $optional);
    }

    /** the rest of the line, exactly as typed */
    public static function rawText(string $name, bool $optional = false) : self{
        return new self($name, AvailableCommandsPacket::ARG_TYPE_RAWTEXT, $optional);
    }

    /** a JSON object or array, e.g. {"rawtext":[{"text":"hi"}]}, decoded to an array */
    public static function json(string $name, bool $optional = false) : self{
        return new self($name, AvailableCommandsPacket::ARG_TYPE_JSON, $optional);
    }

    /** ["facing_direction"=1,"open_bit"=true], as [name => value] */
    public static function blockStates(string $name, bool $optional = false) : self{
        return new self($name, AvailableCommandsPacket::ARG_TYPE_BLOCK_STATES, $optional);
    }

    /** another command line, as in /execute ... run <command> */
    public static function command(string $name, bool $optional = false) : self{
        return new self($name, AvailableCommandsPacket::ARG_TYPE_COMMAND, $optional);
    }

    /** one of the enum's values, as written in the enum */
    public static function enum(string $name, CommandEnum $enum, bool $optional = false) : self{
        return new self($name, 0, $optional, $enum);
    }

    /** true or false, as a bool */
    public static function bool(string $name, bool $optional = false) : self{
        return self::enum($name, CommandEnum::boolean(), $optional);
    }

    /** an int with a letter after it, like 5L in /xp */
    public static function postfix(string $name, string $postfix, bool $optional = false) : self{
        return new self($name, AvailableCommandsPacket::ARG_TYPE_INT, $optional, null, $postfix);
    }

    public function getName() : string{
        return $this->name;
    }

    public function getType() : int{
        return $this->type;
    }

    public function isOptional() : bool{
        return $this->optional;
    }

    public function getEnum() : ?CommandEnum{
        return $this->enum;
    }

    public function getPostfix() : ?string{
        return $this->postfix;
    }

    public function resolvesTargets() : bool{
        return $this->resolve;
    }

    public function takesRestOfLine() : bool{
        return $this->enum === null && in_array($this->type, self::REST_OF_LINE_TYPES, true);
    }

    public function getUsage() : string{
        if($this->enum !== null && count($this->enum->getValues()) === 1){
            $text = $this->enum->getValues()[0];
        }else{
            $text = $this->name . ": " . ($this->enum?->getName() ?? self::getTypeName($this->type));
        }

        return ($this->optional ? "[{$text}]" : "<{$text}>") . ($this->postfix ?? "");
    }

    public static function getTypeName(int $type) : string{
        return match($type){
            AvailableCommandsPacket::ARG_TYPE_INT => "int",
            AvailableCommandsPacket::ARG_TYPE_FLOAT => "float",
            AvailableCommandsPacket::ARG_TYPE_VALUE => "value",
            AvailableCommandsPacket::ARG_TYPE_WILDCARD_INT => "wildcard int",
            AvailableCommandsPacket::ARG_TYPE_OPERATOR => "operator",
            AvailableCommandsPacket::ARG_TYPE_COMPARE_OPERATOR => "compare operator",
            AvailableCommandsPacket::ARG_TYPE_TARGET, AvailableCommandsPacket::ARG_TYPE_WILDCARD_TARGET => "target",
            AvailableCommandsPacket::ARG_TYPE_FILEPATH => "filepath",
            AvailableCommandsPacket::ARG_TYPE_FULL_INTEGER_RANGE => "integer range",
            AvailableCommandsPacket::ARG_TYPE_EQUIPMENT_SLOT => "equipment slots",
            AvailableCommandsPacket::ARG_TYPE_STRING => "string",
            AvailableCommandsPacket::ARG_TYPE_INT_POSITION, AvailableCommandsPacket::ARG_TYPE_POSITION => "x y z",
            AvailableCommandsPacket::ARG_TYPE_MESSAGE => "message",
            AvailableCommandsPacket::ARG_TYPE_RAWTEXT => "text",
            AvailableCommandsPacket::ARG_TYPE_JSON => "json",
            AvailableCommandsPacket::ARG_TYPE_BLOCK_STATES => "block states",
            AvailableCommandsPacket::ARG_TYPE_COMMAND => "command",
            default => "value"
        };
    }
}