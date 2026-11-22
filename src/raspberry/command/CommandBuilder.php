<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\command\argument\Argument;

use raspberry\lang\Translation;

use raspberry\player\Player;

use raspberry\Server;

use LogicException;
use InvalidArgumentException;

use function array_map;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function ksort;
use function preg_match;
use function strtolower;

use const PHP_INT_MAX;

abstract class CommandBuilder{

    private ?string $name = null;

    private string $label = "";

    private string $description = "";

    private ?string $permission = null;

    /** @var list<string> */
    private array $aliases = [];

    /** @var array<int, ParameterBuilder> */
    private array $builders = [];

    private bool $registered = false;

    private ?CommandFeedback $feedback = null;

    public function __construct(){
        $this->configure();

        if($this->name === null){
            throw new LogicException(static::class . " has no name, call setName() in configure()");
        }
    }

    /**
     * Where the command says what it is called, who can use it and what it takes.
     */
    abstract protected function configure() : void;

    /**
     * @param array<string, mixed> $args
     *
     * @throws CommandException
     */
    abstract public function onSent(CommandSender $sender, string $label, array $args) : void;

    public function getName() : string{
        return $this->name ?? throw new LogicException(static::class . " has no name yet");
    }

    protected function setName(string $name) : void{
        $this->name = self::checkName($name);
        $this->label = $this->name;
    }

    /**
     * The name it is registered under, the same as getName() unless a plugin had it first.
     */
    public function getLabel() : string{
        return $this->label;
    }

    public function getDescription() : string{
        return $this->description;
    }

    /**
     * @param string $description
     */
    protected function setDescription(string $description) : void{
        $this->description = $description;
    }

    public function getPermission() : ?string{
        return $this->permission;
    }

    /**
     * @param string|null $permission
     */
    protected function setPermission(?string $permission) : void{
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
    protected function setAliases(array $aliases) : void{
        $this->aliases = array_values(array_map(fn(string $alias) => self::checkName($alias), $aliases));
    }

    /**
     * Adds a way to type the command. A command without any takes no arguments.
     */
    protected function addParameterBuilder(ParameterBuilder $builder) : void{
        if(isset($this->builders[$builder->getIndex()])){
            throw new InvalidArgumentException("/{$this->getName()} already has a parameter builder at {$builder->getIndex()}");
        }

        $this->builders[$builder->getIndex()] = $builder;
        ksort($this->builders);
    }

    /**
     * @return array<int, ParameterBuilder>
     */
    public function getParameterBuilders() : array{
        return $this->builders;
    }

    public function canUse(CommandSender $sender) : bool{
        return $this->permission === null || $sender->hasPermission($this->permission);
    }

    /**
     * @param CommandSender|null $sender
     *
     * @return list<list<Argument>>
     */
    public function getOverloads(?CommandSender $sender = null) : array{
        if($this->builders === []){
            return [[]];
        }

        $overloads = [];

        foreach($this->builders as $builder){
            if($sender === null || $builder->canUse($sender)){
                foreach($builder->getOverloads() as $overload){
                    $overloads[] = $overload;
                }
            }
        }

        return $overloads;
    }

    /**
     * @return list<string>
     */
    public function getUsage(?CommandSender $sender = null) : array{
        return array_map(fn(array $overload) => implode(" ", ["/" . $this->label, ...array_map(fn(Argument $argument) => $argument->getUsage(), $overload)]), $this->getOverloads($sender));
    }

    /**
     * Tells the sender something went right. A Translation with a key from the game's language
     * files reaches a player in their own language, a string is shown as it is.
     */
    public function success(Translation|string $message) : void{
        $this->getFeedback()->add($message, true);
    }

    /**
     * Tells the sender something went wrong, without stopping the command. Throw a
     * CommandException instead to stop.
     */
    public function failure(Translation|string $message) : void{
        $this->getFeedback()->add($message, false);
    }

    /**
     * @internal
     *
     * @param list<string> $words
     *
     * @throws CommandException
     * @throws InvalidCommandSyntaxException
     */
    public function run(CommandSender $sender, string $label, array $words, CommandFeedback $feedback) : void{
        $previous = $this->feedback;
        $this->feedback = $feedback;

        try{
            $this->onSent($sender, $label, $this->read($sender, $words));
        }finally{
            $this->feedback = $previous;
        }
    }

    /**
     * @internal
     */
    public function register(string $label) : void{
        if($this->registered){
            throw new LogicException("/{$this->getName()} is already registered");
        }

        $this->label = self::checkName($label);
        $this->registered = true;
    }

    /**
     * @internal
     */
    public function unregister() : void{
        $this->registered = false;
    }

    public function isRegistered() : bool{
        return $this->registered;
    }

    /**
     * The players in $args[$name], or the sender when it was left out.
     *
     * @param array<string, mixed> $args
     *
     * @return list<Player>
     */
    protected function targetsOrSelf(CommandSender $sender, array $args, string $name) : array{
        if(isset($args[$name])){
            return $args[$name];
        }

        return $sender instanceof Player ? [$sender] : throw new CommandException("commands.generic.noTargetMatch");
    }

    /**
     * A line from the server's own language file, for messages the client has no translation for.
     *
     * @param array<string, string|int|float>|list<string|int|float> $params
     */
    protected function text(string $key, array $params = []) : string{
        return Server::getInstance()->getLanguage()->translate($key, $params);
    }

    private function getFeedback() : CommandFeedback{
        return $this->feedback ?? throw new LogicException("/{$this->getName()} can only give feedback while it runs");
    }

    /**
     * @param list<string> $words
     *
     * @return array<string, mixed>
     */
    private function read(CommandSender $sender, array $words) : array{
        $furthest = -1;
        $failure = null;

        foreach($this->getOverloads($sender) as $overload){
            $plan = self::fit($sender, $overload, $words, $wrong);

            if($plan === null){
                $furthest = $wrong > $furthest ? $wrong : $furthest;
                continue;
            }

            try{
                $args = [];

                foreach($plan as [$argument, $text]){
                    $args[$argument->getName()] = $argument->read($text, $sender);
                }

                return $args;
            }catch(CommandException $exception){
                $failure ??= $exception;
            }
        }

        throw $failure ?? new InvalidCommandSyntaxException($furthest);
    }

    /**
     * @param list<Argument> $overload
     * @param list<string>   $words
     * @param int|null       $wrong    set to the word that did not fit when it gives back null
     *
     * @return list<array{Argument, string}>|null each argument with the text it gets
     */
    private static function fit(CommandSender $sender, array $overload, array $words, ?int &$wrong) : ?array{
        $plan = [];
        $at = 0;
        $count = count($words);

        foreach($overload as $argument){
            if($at >= $count){
                if($argument->isOptional()){
                    break;
                }

                $wrong = $count;
                return null;
            }

            $span = $argument->getWordCount() === PHP_INT_MAX ? $count - $at : $argument->getWordCount();
            $text = implode(" ", array_slice($words, $at, $span));

            if($at + $span > $count || !$argument->accepts($text, $sender)){
                $wrong = $at + $span > $count ? $count : $at;
                return null;
            }

            $plan[] = [$argument, $text];
            $at += $span;
        }

        if($at < $count){
            $wrong = $at;
            return null;
        }

        return $plan;
    }

    private static function checkName(string $name) : string{
        $name = strtolower($name);

        if($name === "" || preg_match('/\s/u', $name) === 1){
            throw new InvalidArgumentException("Invalid command name \"{$name}\": it cannot be empty or contain spaces");
        }

        return $name;
    }
}