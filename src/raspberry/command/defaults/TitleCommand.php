<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandEnum;
use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\player\Player;

class TitleCommand extends Command{

    public function __construct(){
        parent::__construct("title", "commands.title.description", "raspberry.command.title");

        $this->addOverload(CommandParameter::target("player"), CommandParameter::enum("clear", new CommandEnum("TitleClear", ["clear"])));

        $this->addOverload(CommandParameter::target("player"), CommandParameter::enum("reset", new CommandEnum("TitleReset", ["reset"])));

        $this->addOverload(
            CommandParameter::target("player"),
            CommandParameter::enum("titleLocation", new CommandEnum("TitleSet", ["title", "subtitle", "actionbar"])),
            CommandParameter::message("titleText")
        );

        $this->addOverload(
            CommandParameter::target("player"),
            CommandParameter::enum("times", new CommandEnum("TitleTimes", ["times"])),
            CommandParameter::int("fadeIn"),
            CommandParameter::int("stay"),
            CommandParameter::int("fadeOut")
        );
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
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

        $output->success("commands.title.success");
    }
}