<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\lang\Translation;

use raspberry\player\Player;

use raspberry\plugin\PluginOwned;

use raspberry\Server;

use InvalidArgumentException;

use function array_shift;
use function array_slice;
use function count;
use function implode;
use function is_string;
use function ksort;
use function ltrim;
use function str_starts_with;
use function strtolower;
use function trim;

class CommandMap{

    private Server $server;

    private TargetSelector $targetSelector;

    /** @var array<string, CommandBuilder> label => command */
    private array $commands = [];

    /** @var array<string, CommandBuilder> alias => command */
    private array $aliases = [];

    public function __construct(Server $server){
        $this->server = $server;
        $this->targetSelector = new TargetSelector($server);
    }

    public function registerCommand(string $name, CommandBuilder $command) : void{
        $label = strtolower($name);

        if(isset($this->commands[$label]) || isset($this->aliases[$label])){
            throw new InvalidArgumentException("A command named \"{$label}\" is already registered");
        }

        $command->register($label);
        $this->commands[$label] = $command;

        foreach($command->getAliases() as $alias){
            if(!isset($this->commands[$alias]) && !isset($this->aliases[$alias])){
                $this->aliases[$alias] = $command;
            }
        }

        ksort($this->commands);
    }

    /**
     * @param array<int|string, CommandBuilder> $commands
     */
    public function registerAllCommands(array $commands) : void{
        foreach($commands as $name => $command){
            $this->registerCommand(is_string($name) ? $name : $command->getName(), $command);
        }
    }

    public function unregisterCommand(CommandBuilder $command) : void{
        if(($this->commands[$command->getLabel()] ?? null) === $command){
            unset($this->commands[$command->getLabel()]);
        }

        foreach($this->aliases as $alias => $aliased){
            if($aliased === $command){
                unset($this->aliases[$alias]);
            }
        }

        $command->unregister();
    }

    public function getCommand(string $name) : ?CommandBuilder{
        $name = strtolower($name);

        return $this->commands[$name] ?? $this->aliases[$name] ?? null;
    }

    /**
     * @return array<string, CommandBuilder>
     */
    public function getCommands() : array{
        return $this->commands;
    }

    /**
     * Runs a command line like "gamemode creative Steve", with or without the slash, and gives back
     * what it said. Nothing is sent yet, Server::dispatchCommand() does that.
     */
    public function dispatch(CommandSender $sender, string $commandLine) : CommandFeedback{
        $feedback = new CommandFeedback();
        $words = CommandWords::split(ltrim(trim($commandLine), "/"));
        $label = array_shift($words);

        if($label === null){
            return $feedback;
        }

        $command = $this->getCommand($label);

        if($command instanceof PluginOwned && !$command->getOwningPlugin()->isEnabled()){
            $command = null;
        }

        if($command === null || !$command->canUse($sender)){
            $feedback->add(new Translation("commands.generic.unknown", [$label]), false);
            return $feedback;
        }

        try{
            $command->run($sender, strtolower($label), $words, $feedback);
        }catch(InvalidCommandSyntaxException $exception){
            $feedback->add(new Translation("commands.generic.syntax", self::syntaxErrorParts($label, $words, $exception->getWord())), false);
        }catch(CommandException $exception){
            $feedback->add($exception->getTranslation() ?? $exception->getMessage(), false);
        }

        return $feedback;
    }

    /**
     * @return list<Player>
     * @throws CommandException
     */
    public function selectPlayers(CommandSender $sender, string $target) : array{
        if($target === "*"){
            return $this->server->getInGamePlayers();
        }

        if(str_starts_with($target, "@")){
            try{
                return $this->targetSelector->select($sender, $target);
            }catch(InvalidCommandSyntaxException){
                throw new CommandException("commands.generic.noTargetMatch");
            }
        }

        $player = $this->server->getPlayerByPrefix($target);

        return $player === null ? [] : [$player];
    }

    /**
     * @param list<string> $words
     *
     * @return list<string>
     */
    private static function syntaxErrorParts(string $label, array $words, int $wrong) : array{
        if($wrong < 0 || $wrong > count($words)){
            $wrong = count($words);
        }

        $before = implode(" ", [$label, ...array_slice($words, 0, $wrong)]) . " ";
        $after = array_slice($words, $wrong + 1);

        return [
            $before,
            $words[$wrong] ?? "",
            $after === [] ? "" : " " . implode(" ", $after)
        ];
    }
}