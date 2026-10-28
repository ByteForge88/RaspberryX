<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\Server;

use raspberry\utils\TextFormat;

use LogicException;
use InvalidArgumentException;

use function explode;
use function is_string;
use function ksort;
use function ltrim;
use function preg_split;
use function strtolower;
use function trim;

class CommandMap{

    private Server $server;

    private CommandArgumentParser $parser;

    /** @var array<string, Command> label => command */
    private array $commands = [];

    /** @var array<string, Command> alias => command */
    private array $aliases = [];

    public function __construct(Server $server){
        $this->server = $server;
        $this->parser = new CommandArgumentParser($server);
    }

    /**
     * Registers a command under $name, which becomes its label: what players type to run it.
     */
    public function registerCommand(string $name, Command $command) : void{
        if($command->isRegistered()){
            throw new LogicException("Command \"{$command->getName()}\" is already registered");
        }

        $command->setLabel($name);
        $label = $command->getLabel();

        if(isset($this->commands[$label]) || isset($this->aliases[$label])){
            throw new InvalidArgumentException("A command named \"{$label}\" is already registered");
        }

        $this->commands[$label] = $command;

        foreach($command->getAliases() as $alias){
            if(!isset($this->commands[$alias]) && !isset($this->aliases[$alias])){
                $this->aliases[$alias] = $command;
            }
        }

        ksort($this->commands);
        $command->setRegistered(true);
    }

    /**
     * Registers several commands. A string key is used as the name to register under; otherwise
     * the command's own name is used.
     *
     * @param array<int|string, Command> $commands
     */
    public function registerAllCommands(array $commands) : void{
        foreach($commands as $name => $command){
            $this->registerCommand(is_string($name) ? $name : $command->getName(), $command);
        }
    }

    public function unregisterCommand(Command $command) : void{
        if(($this->commands[$command->getLabel()] ?? null) === $command){
            unset($this->commands[$command->getLabel()]);
        }

        foreach($this->aliases as $alias => $aliased){
            if($aliased === $command){
                unset($this->aliases[$alias]);
            }
        }

        $command->setRegistered(false);
    }

    public function getCommand(string $name) : ?Command{
        $name = strtolower($name);

        return $this->commands[$name] ?? $this->aliases[$name] ?? null;
    }

    /**
     * @return array<string, Command> label => command, sorted, without aliases
     */
    public function getCommands() : array{
        return $this->commands;
    }

    /**
     * Runs a command line like "kick Steve griefing" or "/say hi".
     *
     * @return bool false when the command does not exist
     */
    public function dispatch(CommandSender $sender, string $commandLine) : bool{
        $parts = preg_split('/\s+/u', ltrim(trim($commandLine), "/"), 2) ?: [""];
        $name = $parts[0];

        if($name === ""){
            return true;
        }

        $language = $this->server->getLanguage();
        $command = $this->getCommand($name);

        if($command === null){
            $sender->sendMessage(TextFormat::RED . $language->translate("command.unknown", ["command" => $name]));
            return false;
        }

        if(!$command->testPermission($sender)){
            $sender->sendMessage(TextFormat::RED . $language->translate("command.noPermission"));
            return true;
        }

        try{
            $command->onSent($sender, $this->parser->parse($sender, $command, $parts[1] ?? ""));
        }catch(InvalidCommandSyntaxException){
            foreach(explode("\n", $command->getUsage()) as $line){
                $sender->sendMessage(TextFormat::RED . $language->translate("command.usage", ["usage" => $line]));
            }
        }catch(CommandException $exception){
            $sender->sendMessage(TextFormat::RED . $exception->getMessage());
        }

        return true;
    }
}