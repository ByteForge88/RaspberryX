<?php

declare(strict_types=1);

namespace raspberry\command;

use InvalidArgumentException;

use function strtolower;

class CommandEnum{

    public const BOOLEAN = "Boolean";

    private string $name;

    /** @var list<string> */
    private array $values;

    private bool $soft;

    /**
     * @param list<string> $values
     */
    public function __construct(string $name, array $values, bool $soft = false){
        if($name === ""){
            throw new InvalidArgumentException("An enum needs a name");
        }

        if($values === [] && !$soft){
            throw new InvalidArgumentException("Enum \"{$name}\" needs at least one value");
        }

        $this->name = $name;
        $this->values = $values;
        $this->soft = $soft;
    }

    public static function boolean() : self{
        return new self(self::BOOLEAN, ["true", "false"]);
    }

    public function getName() : string{
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function getValues() : array{
        return $this->values;
    }

    /**
     * @param list<string> $values
     */
    public function setValues(array $values) : void{
        if(!$this->soft){
            throw new InvalidArgumentException("Only a soft enum can change, \"{$this->name}\" is not soft");
        }

        $this->values = $values;
    }

    public function isSoft() : bool{
        return $this->soft;
    }

    /**
     * Returns the value as written in the enum, ignoring case, or null when it is not one of them.
     */
    public function match(string $input) : ?string{
        $input = strtolower($input);

        foreach($this->values as $value){
            if(strtolower($value) === $input){
                return $value;
            }
        }

        return null;
    }
}