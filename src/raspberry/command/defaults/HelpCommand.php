<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandException;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;
use raspberry\command\ConsoleCommandSender;

use raspberry\Server;

use raspberry\utils\TextFormat;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function implode;
use function intdiv;
use function max;
use function min;

class HelpCommand extends Command{

    private const PAGE_SIZE = 8;

    public function __construct(){
        parent::__construct("help");

        $this->setDescription($this->translate("command.help.description"));
        $this->setPermission("raspberry.command.help");
        $this->setAliases(["?"]);

        $this->setArgument(0, CommandParameter::int("page", true));
        $this->setArgument(0, CommandParameter::string("command"), 1);
    }

    public function onSent(CommandSender $sender, array $args) : void{
        if(isset($args["command"])){
            $this->showCommand($sender, $args["command"]);
            return;
        }

        $commands = array_values(array_filter(Server::getInstance()->getCommandMap()->getCommands(), fn(Command $command) => $command->testPermission($sender)));

        if(!$sender instanceof ConsoleCommandSender){
            $pages = max(1, intdiv(count($commands) + self::PAGE_SIZE - 1, self::PAGE_SIZE));
            $page = min(max(1, $args["page"] ?? 1), $pages);
            $commands = array_slice($commands, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

            $sender->sendMessage(TextFormat::GOLD . $this->translate("command.help.page", ["page" => $page, "pages" => $pages]));
        }else{
            $sender->sendMessage(TextFormat::GOLD . $this->translate("command.help.header"));
        }

        foreach($commands as $command){
            $sender->sendMessage(TextFormat::YELLOW . "/" . $command->getLabel() . TextFormat::RESET . ": " . $command->getDescription());
        }
    }

    private function showCommand(CommandSender $sender, string $name) : void{
        $command = Server::getInstance()->getCommandMap()->getCommand($name);

        if($command === null || !$command->testPermission($sender)){
            throw new CommandException($this->translate("command.unknown", ["command" => $name]));
        }

        $sender->sendMessage(TextFormat::YELLOW . "/" . $command->getLabel() . TextFormat::RESET . ": " . $command->getDescription());

        foreach(explode("\n", $command->getUsage()) as $line){
            $sender->sendMessage($this->translate("command.usage", ["usage" => $line]));
        }

        if($command->getAliases() !== []){
            $sender->sendMessage($this->translate("command.help.aliases", ["aliases" => implode(", ", $command->getAliases())]));
        }
    }
}