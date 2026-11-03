<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\item\Item;

use raspberry\player\Player;

class ClearCommand extends Command{

    public function __construct(){
        parent::__construct("clear");

        $this->setDescription($this->translate("command.clear.description"));
        $this->setPermission("raspberry.command.clear");

        $this->setArgument(0, CommandParameter::target("player", true));
        $this->setArgument(1, CommandParameter::enum("itemName", CommandEnum::items(), true));
        $this->setArgument(2, CommandParameter::int("data", true));
        $this->setArgument(3, CommandParameter::int("maxCount", true));
    }

    public function onSent(CommandSender $sender, array $args) : void{
        if(isset($args["player"])){
            /** @var list<Player> $players */
            $players = $args["player"];
        }elseif($sender instanceof Player){
            $players = [$sender];
        }else{
            throw new CommandException($this->translate("command.clear.noPlayer"));
        }

        $id = isset($args["itemName"]) ? "minecraft:" . $args["itemName"] : null;
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

                $sender->sendMessage($this->translate("command.clear.count", ["player" => $player->getName(), "count" => $count]));
                continue;
            }

            foreach($inventories as $inventory){
                $count += $inventory->removeMatching($filter, $max < 0 ? -1 : $max - $count);

                if($max > 0 && $count >= $max){
                    break;
                }
            }

            if($count === 0){
                $sender->sendMessage($this->translate("command.clear.none", ["player" => $player->getName()]));
            }else{
                $sender->sendMessage($this->translate("command.clear.success", ["player" => $player->getName(), "count" => $count]));
            }
        }
    }
}