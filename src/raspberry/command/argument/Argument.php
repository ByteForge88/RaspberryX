<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandSender;

use InvalidArgumentException;

use function count;

use const PHP_INT_MAX;

/**
 * One argument of a command. accepts() says whether the text is the right kind, before anything
 * is done with it, and read() turns it into what the command gets.
 */
abstract class Argument{

    protected string $name;

    protected bool $optional;

    public function __construct(string $name, bool $optional = false){
        if($name === ""){
            throw new InvalidArgumentException("An argument needs a name");
        }

        $this->name = $name;
        $this->optional = $optional;
    }

    public function getName() : string{
        return $this->name;
    }

    public function isOptional() : bool{
        return $this->optional;
    }

    /**
     * One of the AvailableCommandsPacket::ARG_TYPE_* constants, ignored for enums.
     */
    abstract public function getNetworkType() : int;

    /**
     * What /help shows after the name, like "int" in <amount: int>.
     */
    abstract public function getTypeName() : string;

    /**
     * Whether the text is the right kind for this argument. It is the words the argument spans,
     * joined with spaces.
     */
    abstract public function accepts(string $text, CommandSender $sender) : bool;

    /**
     * Only called after accepts() said yes.
     *
     * @throws CommandException
     */
    abstract public function read(string $text, CommandSender $sender) : mixed;

    /**
     * How many words the argument takes, PHP_INT_MAX for the rest of the line.
     */
    public function getWordCount() : int{
        return 1;
    }

    public function takesRestOfLine() : bool{
        return $this->getWordCount() === PHP_INT_MAX;
    }

    public function getEnum() : ?CommandEnum{
        return null;
    }

    /**
     * The letter an int ends with, like the L in /xp 5L.
     */
    public function getPostfix() : ?string{
        return null;
    }

    public function getUsage() : string{
        $enum = $this->getEnum();

        if($enum !== null && count($enum->getValues()) === 1){
            $text = $enum->getValues()[0];
        }else{
            $text = $this->name . ": " . $this->getTypeName();
        }

        return ($this->optional ? "[$text]" : "<$text>") . ($this->getPostfix() ?? "");
    }
}