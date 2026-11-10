<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\item\Item;

class ClearCommand extends Command{

    public function __construct(){
        parent::__construct("clear", "commands.clear.description", "raspberry.command.clear");

        $this->addOverload(
            CommandParameter::target("player", true),
            CommandParameter::enum("itemName", CommandEnum::items(), true),
            CommandParameter::int("data", true),
            CommandParameter::int("maxCount", true)
        );
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        $players = $this->playersOrSelf($sender, $args, "player");
        $id = isset($args["itemName"]) ? (CommandEnum::toItemId($args["itemName"]) ?? throw new CommandException("commands.give.item.notFound", [$args["itemName"]])) : null;
        $data = $args["data"] ?? -1;
        $max = $args["maxCount"] ?? -1;
        $filter = fn(Item $item) : bool => ($id === null || $item->getId() === $id) && ($data === -1 || $item->getMeta() === $data);

        foreach($players as $player){
            $inventories = [$player->getInventory(), $player->getArmorInventory(), $player->getOffHandInventory()];
            $count = 0;

            if($max === 0){
                foreach($inventories as $inventory){
                    $count += $inventory->countMatching($filter);
                }

                $output->success("commands.clear.testing", [$player->getName(), $count]);
                continue;
            }

            foreach($inventories as $inventory){
                $count += $inventory->removeMatching($filter, $max < 0 ? -1 : $max - $count);

                if($max > 0 && $count >= $max){
                    break;
                }
            }

            if($count === 0){
                $output->error("commands.clear.failure.no.items", [$player->getName()]);
            }else{
                $output->success("commands.clear.success", [$player->getName(), $count]);
            }
        }
    }
}