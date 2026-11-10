<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\Server;

use function array_slice;
use function count;
use function ctype_digit;
use function implode;
use function intdiv;
use function max;
use function min;

class HelpCommand extends Command{

    private const PAGE_SIZE = 7;

    public function __construct(){
        parent::__construct("help", "commands.help.description", "raspberry.command.help", ["?"]);

        $this->addOverload(CommandParameter::enum("command", CommandEnum::commandNames(), true));
        $this->addOverload(CommandParameter::int("page"));
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        $command = $args["command"] ?? null;

        if($command !== null && !ctype_digit($command)){
            $this->showCommand($sender, $output, $command);
            return;
        }

        $lines = [];

        foreach(Server::getInstance()->getCommandMap()->getCommands() as $registered){
            if($registered->testPermission($sender)){
                foreach($registered->getUsage() as $line){
                    $lines[] = $line;
                }
            }
        }

        $pages = max(1, intdiv(count($lines) + self::PAGE_SIZE - 1, self::PAGE_SIZE));
        $page = min(max(1, $args["page"] ?? (int) ($command ?? 1)), $pages);

        $output->success("commands.help.header", [$page, $pages]);

        foreach(array_slice($lines, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE) as $line){
            $output->successText($line);
        }

        $output->success("commands.help.footer");
    }

    private function showCommand(CommandSender $sender, CommandOutput $output, string $name) : void{
        $command = Server::getInstance()->getCommandMap()->getCommand($name);

        if($command === null || !$command->testPermission($sender)){
            throw new CommandException("commands.generic.unknown", [$name]);
        }

        if($command->getAliases() !== []){
            $output->success("commands.help.command.aliases", [$command->getLabel(), implode(", ", $command->getAliases())]);
        }

        foreach($command->getUsage() as $line){
            $output->successText($line);
        }
    }
}