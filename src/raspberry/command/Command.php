<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\Server;

use LogicException;
use InvalidArgumentException;

use function array_map;
use function array_splice;
use function array_values;
use function count;
use function implode;
use function ksort;
use function preg_match;
use function strtolower;

abstract class Command{

    private string $name;

    private string $label;

    private string $description;

    private ?string $usage = null;

    private ?string $permission = null;

    /** @var list<list<CommandParameter>> */
    private array $overloads;

    /** @var list<string> */
    private array $aliases;

    private bool $registered = false;

    /**
     * The permission is set with setPermission(); without one, everyone can use the command.
     *
     * @param list<list<CommandParameter>> $overloads each list is one way to use the command, tried in order;
     *                                                [[]] means the command takes no arguments
     * @param list<string>                 $aliases
     */
    public function __construct(string $name, string $description = "", array $overloads = [[]], array $aliases = []){
        $this->name = self::checkName($name);
        $this->label = $this->name;
        $this->description = $description;
        $this->overloads = [[]];
        $this->aliases = [];

        $this->setOverloads($overloads);
        $this->setAliases($aliases);
    }

    /**
     * The name the command was created with. It never changes, so plugins can always find their command by it.
     */
    public function getName() : string{
        return $this->name;
    }

    /**
     * Changes the command's name. Also changes the label when the label was still the old name.
     */
    public function setName(string $name) : void{
        $this->checkNotRegistered();

        $name = self::checkName($name);

        if($this->label === $this->name){
            $this->label = $name;
        }

        $this->name = $name;
    }

    /**
     * What players type to run the command, without the slash. The same as the name unless it was
     * changed, e.g. by CommandMap::registerCommand() to avoid a clash with another command.
     */
    public function getLabel() : string{
        return $this->label;
    }

    public function setLabel(string $label) : void{
        $this->checkNotRegistered();

        $this->label = self::checkName($label);
    }

    public function getDescription() : string{
        return $this->description;
    }

    public function setDescription(string $description) : void{
        $this->description = $description;
    }

    /**
     * The usage text shown when the arguments are wrong. Made from the arguments unless setUsage() was used.
     * One line per overload, e.g. "/kick <name: target> [reason: message]".
     */
    public function getUsage() : string{
        if($this->usage !== null){
            return $this->usage;
        }

        $lines = [];

        foreach($this->overloads as $overload){
            $lines[] = implode(" ", ["/" . $this->label, ...array_map(fn(CommandParameter $parameter) => $parameter->getUsage(), $overload)]);
        }

        return implode("\n", $lines);
    }

    /**
     * @param string|null $usage null to go back to the usage made from the arguments
     */
    public function setUsage(?string $usage) : void{
        $this->usage = $usage;
    }

    public function getPermission() : ?string{
        return $this->permission;
    }

    public function setPermission(?string $permission) : void{
        $this->permission = $permission;
    }

    /**
     * @return list<string>
     */
    public function getAliases() : array{
        return $this->aliases;
    }

    /**
     * @param list<string> $aliases
     */
    public function setAliases(array $aliases) : void{
        $this->checkNotRegistered();

        $this->aliases = array_values(array_map(fn(string $alias) => self::checkName($alias), $aliases));
    }

    /**
     * @return list<list<CommandParameter>>
     */
    public function getOverloads() : array{
        return $this->overloads;
    }

    /**
     * @param list<list<CommandParameter>> $overloads
     */
    protected function setOverloads(array $overloads) : void{
        if($overloads === []){
            $overloads = [[]];
        }

        foreach($overloads as $overload){
            $this->checkOverload($overload);
        }

        $this->overloads = array_values(array_map(array_values(...), $overloads));
    }

    /**
     * Puts an argument at $position, replacing the one already there. Positions start at 0 and must not
     * leave a gap. Using the next overload number ($overload = count(getOverloads())) adds a new overload,
     * which is another way to use the command.
     */
    protected function setArgument(int $position, CommandParameter $argument, int $overload = 0) : void{
        if($overload < 0 || $overload > count($this->overloads)){
            throw new InvalidArgumentException("Overload {$overload} does not exist, the next new overload is " . count($this->overloads));
        }

        $arguments = $this->overloads[$overload] ?? [];

        if($position < 0 || $position > count($arguments)){
            throw new InvalidArgumentException("Argument position {$position} leaves a gap, the next free position is " . count($arguments));
        }

        $arguments[$position] = $argument;
        ksort($arguments);

        $this->replaceOverload($overload, $arguments);
    }

    /**
     * Removes the argument at $position; the arguments after it move one place to the left.
     */
    protected function clearArgument(int $position, int $overload = 0) : void{
        if(!isset($this->overloads[$overload][$position])){
            throw new InvalidArgumentException("Overload {$overload} has no argument at position {$position}");
        }

        $arguments = $this->overloads[$overload];
        array_splice($arguments, $position, 1);

        $this->replaceOverload($overload, $arguments);
    }

    /**
     * Removes every argument and overload, so the command takes no arguments.
     */
    protected function clearAllArguments() : void{
        $this->overloads = [[]];
    }

    public function testPermission(CommandSender $sender) : bool{
        return $this->permission === null || $sender->hasPermission($this->permission);
    }

    /**
     * @internal called by CommandMap; the name, label and aliases cannot change while registered
     */
    public function setRegistered(bool $registered) : void{
        $this->registered = $registered;
    }

    public function isRegistered() : bool{
        return $this->registered;
    }

    /**
     * Runs the command. $args holds the arguments of the overload that matched, by name;
     * optional arguments that were left out are missing. See CommandParameter for the value types.
     *
     * @param array<string, mixed> $args
     *
     * @throws CommandException to stop with a message
     */
    abstract public function onSent(CommandSender $sender, array $args) : void;

    /**
     * @param array<string, string|int|float> $params
     */
    protected function translate(string $key, array $params = []) : string{
        return Server::getInstance()->getLanguage()->translate($key, $params);
    }

    /**
     * @param list<CommandParameter> $arguments
     */
    private function replaceOverload(int $overload, array $arguments) : void{
        $arguments = array_values($arguments);
        $this->checkOverload($arguments);
        $this->overloads[$overload] = $arguments;
    }

    private function checkNotRegistered() : void{
        if($this->registered){
            throw new LogicException("The name, label and aliases of \"{$this->name}\" cannot change while it is registered");
        }
    }

    private static function checkName(string $name) : string{
        $name = strtolower($name);

        if($name === "" || preg_match('/\s/u', $name) === 1){
            throw new InvalidArgumentException("Invalid command name \"{$name}\": it cannot be empty or contain spaces");
        }

        return $name;
    }

    /**
     * @param list<CommandParameter> $overload
     */
    private function checkOverload(array $overload) : void{
        $optional = false;
        $names = [];
        $index = 0;
        $last = count($overload) - 1;

        foreach($overload as $parameter){
            if(!$parameter instanceof CommandParameter){
                throw new InvalidArgumentException("Arguments of \"{$this->name}\" must be CommandParameter objects");
            }

            if(isset($names[$parameter->getName()])){
                throw new InvalidArgumentException("Command \"{$this->name}\" has two arguments named \"{$parameter->getName()}\" in one overload");
            }

            if($optional && !$parameter->isOptional()){
                throw new InvalidArgumentException("Command \"{$this->name}\": required argument \"{$parameter->getName()}\" cannot come after an optional one");
            }

            if($parameter->takesRestOfLine() && $index !== $last){
                throw new InvalidArgumentException("Command \"{$this->name}\": \"{$parameter->getName()}\" takes the rest of the line, so it must be the last argument");
            }

            $names[$parameter->getName()] = true;
            $optional = $parameter->isOptional();
            ++$index;
        }
    }
}