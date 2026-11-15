<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\player\Player;

use raspberry\plugin\PluginOwned;

use raspberry\Server;

use LogicException;
use InvalidArgumentException;

use function is_string;
use function ksort;
use function ltrim;
use function min;
use function preg_split;
use function str_ends_with;
use function strlen;
use function strtolower;
use function substr;
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
     * @return array<string, Command>
     */
    public function getCommands() : array{
        return $this->commands;
    }

    public function dispatch(CommandSender $sender, string $commandLine) : CommandOutput{
        $output = new CommandOutput();
        $line = ltrim(trim($commandLine), "/");
        $parts = preg_split('/\s+/u', $line, 2) ?: [""];
        $name = $parts[0];

        if($name === ""){
            return $output;
        }

        $command = $this->getCommand($name);

        if($command instanceof PluginOwned && !$command->getOwningPlugin()->isEnabled()){
            $command = null;
        }

        if($command === null || !$command->testPermission($sender)){
            $output->error("commands.generic.unknown", [$name]);
            return $output;
        }

        $arguments = $parts[1] ?? "";

        try{
            $command->onSent($sender, $output, $this->parser->parse($sender, $command, $arguments));
        }catch(InvalidCommandSyntaxException $exception){
            $output->error("commands.generic.syntax", self::syntaxErrorParts($name, $arguments, $exception->getOffset()));
        }catch(CommandException $exception){
            $output->addException($exception);
        }

        return $output;
    }

    /**
     * @return list<Player>
     * @throws CommandException
     */
    public function selectPlayers(CommandSender $sender, string $target) : array{
        return $this->parser->selectPlayers($sender, $target);
    }

    /**
     * @return list<string>
     */
    private static function syntaxErrorParts(string $name, string $arguments, int $offset) : array{
        $offset = $offset < 0 ? strlen($arguments) : min($offset, strlen($arguments));
        $end = $offset;

        while($end < strlen($arguments) && $arguments[$end] !== " "){
            ++$end;
        }

        $before = $name . " " . substr($arguments, 0, $offset);

        if($end === $offset && !str_ends_with($before, " ")){
            $before .= " ";
        }

        return [
            $before,
            substr($arguments, $offset, $end - $offset),
            substr($arguments, $end)
        ];
    }
}