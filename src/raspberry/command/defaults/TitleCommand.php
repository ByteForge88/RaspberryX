<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandEnum;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\player\Player;

use function count;

class TitleCommand extends Command{

    public function __construct(){
        parent::__construct("title");

        $this->setDescription($this->translate("command.title.description"));
        $this->setPermission("raspberry.command.title");

        //overload 0: /title <player> clear
        $this->setArgument(0, CommandParameter::target("player"));
        $this->setArgument(1, CommandParameter::enum("clear", new CommandEnum("TitleClear", ["clear"])));

        //overload 1: /title <player> reset
        $this->setArgument(0, CommandParameter::target("player"), 1);
        $this->setArgument(1, CommandParameter::enum("reset", new CommandEnum("TitleReset", ["reset"])), 1);

        //overload 2: /title <player> title|subtitle|actionbar <text>
        $this->setArgument(0, CommandParameter::target("player"), 2);
        $this->setArgument(1, CommandParameter::enum("titleLocation", new CommandEnum("TitleSet", ["title", "subtitle", "actionbar"])), 2);
        $this->setArgument(2, CommandParameter::message("titleText"), 2);

        //overload 3: /title <player> times <fadeIn> <stay> <fadeOut>
        $this->setArgument(0, CommandParameter::target("player"), 3);
        $this->setArgument(1, CommandParameter::enum("times", new CommandEnum("TitleTimes", ["times"])), 3);
        $this->setArgument(2, CommandParameter::int("fadeIn"), 3);
        $this->setArgument(3, CommandParameter::int("stay"), 3);
        $this->setArgument(4, CommandParameter::int("fadeOut"), 3);
    }

    public function onSent(CommandSender $sender, array $args) : void{
        /** @var list<Player> $players */
        $players = $args["player"];

        foreach($players as $player){
            match(true){
                isset($args["clear"]) => $player->removeTitles(),
                isset($args["reset"]) => $player->resetTitles(),
                isset($args["times"]) => $player->setTitleDuration($args["fadeIn"], $args["stay"], $args["fadeOut"]),
                $args["titleLocation"] === "title" => $player->sendTitle($args["titleText"]),
                $args["titleLocation"] === "subtitle" => $player->sendSubTitle($args["titleText"]),
                default => $player->sendActionBarMessage($args["titleText"])
            };
        }

        $sender->sendMessage($this->translate("command.title.done", ["count" => count($players)]));
    }
}