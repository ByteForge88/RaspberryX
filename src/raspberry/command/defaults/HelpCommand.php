<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandException;
use raspberry\command\CommandSender;
use raspberry\command\ParameterBuilder;

use raspberry\command\argument\CommandNameArgument;
use raspberry\command\argument\IntArgument;

use raspberry\lang\Translation;

use raspberry\Server;

use function array_slice;
use function count;
use function ctype_digit;
use function implode;
use function intdiv;
use function max;
use function min;

class HelpCommand extends CommandBuilder{

    private const int PAGE_SIZE = 7;

    protected function configure() : void{
        $this->setName("help");
        $this->setDescription("commands.help.description");
        $this->setPermission("raspberry.command.help");
        $this->setAliases(["?"]);

        $builder = new ParameterBuilder(0);
        $builder->addArgument(0, new CommandNameArgument("command", true));
        $builder->addArgument(0, new IntArgument("page"));
        $this->addParameterBuilder($builder);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        $command = $args["command"] ?? null;

        if($command !== null && !ctype_digit($command)){
            $this->showCommand($sender, $command);
            return;
        }

        $lines = [];

        foreach(Server::getInstance()->getCommandMap()->getCommands() as $registered){
            if($registered->canUse($sender)){
                foreach($registered->getUsage($sender) as $line){
                    $lines[] = $line;
                }
            }
        }

        $pages = max(1, intdiv(count($lines) + self::PAGE_SIZE - 1, self::PAGE_SIZE));
        $page = min(max(1, $args["page"] ?? (int) ($command ?? 1)), $pages);

        $this->success(new Translation("commands.help.header", [$page, $pages]));

        foreach(array_slice($lines, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE) as $line){
            $this->success($line);
        }

        $this->success(new Translation("commands.help.footer"));
    }

    private function showCommand(CommandSender $sender, string $name) : void{
        $command = Server::getInstance()->getCommandMap()->getCommand($name);

        if($command === null || !$command->canUse($sender)){
            throw new CommandException("commands.generic.unknown", [$name]);
        }

        if($command->getAliases() !== []){
            $this->success(new Translation("commands.help.command.aliases", [$command->getLabel(), implode(", ", $command->getAliases())]));
        }

        foreach($command->getUsage($sender) as $line){
            $this->success($line);
        }
    }
}