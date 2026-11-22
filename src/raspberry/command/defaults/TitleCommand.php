<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandEnum;
use raspberry\command\CommandSender;
use raspberry\command\ParameterBuilder;

use raspberry\command\argument\EnumArgument;
use raspberry\command\argument\IntArgument;
use raspberry\command\argument\KeywordArgument;
use raspberry\command\argument\MessageArgument;
use raspberry\command\argument\TargetArgument;

use raspberry\lang\Translation;

use raspberry\player\Player;

class TitleCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("title");
        $this->setDescription("commands.title.description");
        $this->setPermission("raspberry.command.title");

        $player = new TargetArgument("player");

        $clear = new ParameterBuilder(0);
        $clear->addArgument(0, $player);
        $clear->addArgument(1, new KeywordArgument("clear", "TitleClear", ["clear"]));
        $this->addParameterBuilder($clear);

        $reset = new ParameterBuilder(1);
        $reset->addArgument(0, $player);
        $reset->addArgument(1, new KeywordArgument("reset", "TitleReset", ["reset"]));
        $this->addParameterBuilder($reset);

        $show = new ParameterBuilder(2);
        $show->addArgument(0, $player);
        $show->addArgument(1, new EnumArgument("titleLocation", new CommandEnum("TitleSet", ["title", "subtitle", "actionbar"])));
        $show->addArgument(2, new MessageArgument("titleText"));
        $this->addParameterBuilder($show);

        $times = new ParameterBuilder(3);
        $times->addArgument(0, $player);
        $times->addArgument(1, new KeywordArgument("times", "TitleTimes", ["times"]));
        $times->addArgument(2, new IntArgument("fadeIn"));
        $times->addArgument(3, new IntArgument("stay"));
        $times->addArgument(4, new IntArgument("fadeOut"));
        $this->addParameterBuilder($times);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
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

        $this->success(new Translation("commands.title.success"));
    }
}