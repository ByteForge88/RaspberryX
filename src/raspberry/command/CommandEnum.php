<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\entity\effect\Effect;

use raspberry\item\ItemIds;

use ReflectionClass;
use InvalidArgumentException;

use function array_map;
use function dirname;
use function file_get_contents;
use function is_array;
use function is_file;
use function json_decode;
use function str_starts_with;
use function strtolower;
use function substr;

class CommandEnum{

    public const BOOLEAN = "Boolean";

    /** filled with the names of the commands each player can use when the commands are sent */
    public const COMMAND_NAME = "CommandName";

    private string $name;

    /** @var list<string> */
    private array $values;

    private bool $soft;

    /**
     * @param list<string> $values
     * @param bool         $soft   a soft enum can get new values later, see Server::updateSoftEnum()
     */
    public function __construct(string $name, array $values, bool $soft = false){
        if($name === ""){
            throw new InvalidArgumentException("An enum needs a name");
        }

        if($values === [] && !$soft && $name !== self::COMMAND_NAME){
            throw new InvalidArgumentException("Enum \"{$name}\" needs at least one value");
        }

        $this->name = $name;
        $this->values = $values;
        $this->soft = $soft;
    }

    public static function boolean() : self{
        return new self(self::BOOLEAN, ["true", "false"]);
    }

    public static function items() : self{
        $values = self::loadVanilla()["Item"] ?? null;

        if($values === null){
            $values = [];

            foreach(self::getItemIds() as $id => $_){
                if($id !== ItemIds::AIR){
                    $values[] = $id;
                    $values[] = substr($id, 10);
                }
            }
        }

        return new self("Item", $values);
    }

    /**
     * "minecraft:diamond" for "diamond" or "minecraft:diamond", or null when there is no such item.
     */
    public static function toItemId(string $name) : ?string{
        $id = str_starts_with($name, "minecraft:") ? $name : "minecraft:" . $name;

        return isset(self::getItemIds()[$id]) && $id !== ItemIds::AIR ? $id : null;
    }

    public static function effects() : self{
        return new self("Effect", array_map(fn(Effect $effect) => $effect->getName(), Effect::cases()));
    }

    public static function gameModes() : self{
        return new self("GameMode", ["default", "creative", "spectator", "survival", "adventure", "d", "c", "s", "a"]);
    }

    /**
     * The commands a player can use, for /help.
     */
    public static function commandNames() : self{
        return new self(self::COMMAND_NAME, []);
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
     * Whether the values are filled in for each player, like the command names.
     */
    public function isDynamic() : bool{
        return $this->name === self::COMMAND_NAME;
    }

    /**
     * Returns the value as written in the enum, ignoring case, or null when it is not one of them.
     * "minecraft:speed" also matches "speed". A dynamic enum takes any word.
     */
    public function match(string $input) : ?string{
        if($this->isDynamic()){
            return strtolower($input);
        }

        $input = strtolower($input);
        $short = str_starts_with($input, "minecraft:") ? substr($input, 10) : null;
        $found = null;

        foreach($this->values as $value){
            $lower = strtolower($value);

            if($lower === $input){
                return $value;
            }

            if($short !== null && $lower === $short){
                $found = $value;
            }
        }

        return $found;
    }

    /**
     * @return array<string, true>
     */
    private static function getItemIds() : array{
        static $ids = null;

        if($ids === null){
            $ids = [];

            foreach((new ReflectionClass(ItemIds::class))->getConstants() as $id){
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function loadVanilla() : array{
        static $enums = null;

        if($enums === null){
            $path = dirname(__DIR__, 3) . "/resources/vanilla/command_enums.json";
            $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $enums = is_array($data) && is_array($data["enums"] ?? null) ? $data["enums"] : [];
        }

        return $enums;
    }
}