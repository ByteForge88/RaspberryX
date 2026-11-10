<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\player\Player;

use raspberry\Server;

use LogicException;
use InvalidArgumentException;

use function array_map;
use function array_values;
use function count;
use function implode;
use function preg_match;
use function strtolower;

abstract class Command{

    private string $name;

    private string $label;

    private string $description;

    private ?string $permission;

    /** @var list<list<CommandParameter>> */
    private array $overloads = [];

    /** @var list<string> */
    private array $aliases = [];

    private bool $registered = false;

    /**
     * @param string       $description a key from the game's language files, like "commands.give.description", or plain text
     * @param string|null  $permission  null lets everyone use the command
     * @param list<string> $aliases
     */
    public function __construct(string $name, string $description = "", ?string $permission = null, array $aliases = []){
        $this->name = self::checkName($name);
        $this->label = $this->name;
        $this->description = $description;
        $this->permission = $permission;

        $this->setAliases($aliases);
    }

    /**
     * The name the command was created with. It never changes, so it always finds the command.
     */
    public function getName() : string{
        return $this->name;
    }

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
     * @return list<list<CommandParameter>> [[]] when the command takes no arguments
     */
    public function getOverloads() : array{
        return $this->overloads === [] ? [[]] : $this->overloads;
    }

    protected function addOverload(CommandParameter ...$parameters) : void{
        $parameters = array_values($parameters);
        $this->checkOverload($parameters);
        $this->overloads[] = $parameters;
    }

    /**
     * @param list<list<CommandParameter>> $overloads
     */
    protected function setOverloads(array $overloads) : void{
        $this->overloads = [];

        foreach($overloads as $overload){
            $this->addOverload(...$overload);
        }
    }

    /**
     * @return list<string>
     */
    public function getUsage() : array{
        $lines = [];

        foreach($this->getOverloads() as $overload){
            $lines[] = implode(" ", ["/" . $this->label, ...array_map(fn(CommandParameter $parameter) => $parameter->getUsage(), $overload)]);
        }

        return $lines;
    }

    public function testPermission(CommandSender $sender) : bool{
        return $this->permission === null || $sender->hasPermission($this->permission);
    }

    /**
     * @internal
     */
    public function setRegistered(bool $registered) : void{
        $this->registered = $registered;
    }

    public function isRegistered() : bool{
        return $this->registered;
    }

    /**
     * @param array<string, mixed> $args
     * @throws CommandException
     */
    abstract public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void;

    /**
     * @param array<string, mixed> $args
     * @return list<Player>
     */
    protected function playersOrSelf(CommandSender $sender, array $args, string $name) : array{
        if(isset($args[$name])){
            return $args[$name];
        }

        return $sender instanceof Player ? [$sender] : throw new CommandException("commands.generic.noTargetMatch");
    }

    /**
     * @param array<string, string|int|float> $params
     */
    protected function text(string $key, array $params = []) : string{
        return Server::getInstance()->getLanguage()->translate($key, $params);
    }

    private function checkNotRegistered() : void{
        if($this->registered){
            throw new LogicException("The label and aliases of \"{$this->name}\" cannot change while it is registered");
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
        $last = count($overload) - 1;

        foreach($overload as $index => $parameter){
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
        }
    }
}