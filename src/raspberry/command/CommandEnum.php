<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\item\ItemIds;

use ReflectionClass;
use InvalidArgumentException;

use function array_values;
use function sort;
use function str_starts_with;
use function strtolower;
use function substr;

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


    /**
     * Every vanilla item name without "minecraft:"
     */
    public static function items() : self{
        $names = [];

        foreach((new ReflectionClass(ItemIds::class))->getConstants() as $id){
            if($id !== ItemIds::AIR){
                $names[] = substr($id, 10);
            }
        }

        sort($names);

        return new self("Item", array_values($names));
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
     * "minecraft:diamond" also matches the value "diamond", like vanilla item and block names.
     */
    public function match(string $input) : ?string{
        $input = strtolower($input);

        if(str_starts_with($input, "minecraft:")){
            $input = substr($input, 10);
        }

        foreach($this->values as $value){
            if(strtolower($value) === $input){
                return $value;
            }
        }

        return null;
    }
}