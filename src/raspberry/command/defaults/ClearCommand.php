<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandSender;
use raspberry\command\ParameterBuilder;

use raspberry\command\argument\IntArgument;
use raspberry\command\argument\ItemArgument;
use raspberry\command\argument\TargetArgument;

use raspberry\item\Item;

use raspberry\lang\Translation;

class ClearCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("clear");
        $this->setDescription("commands.clear.description");
        $this->setPermission("raspberry.command.clear");

        $builder = new ParameterBuilder(0);
        $builder->addArgument(0, new TargetArgument("player", true));
        $builder->addArgument(1, new ItemArgument("itemName", true));
        $builder->addArgument(2, new IntArgument("data", true));
        $builder->addArgument(3, new IntArgument("maxCount", true));
        $this->addParameterBuilder($builder);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        $players = $this->targetsOrSelf($sender, $args, "player");
        $id = $args["itemName"] ?? null;
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

                $this->success(new Translation("commands.clear.testing", [$player->getName(), $count]));
                continue;
            }

            foreach($inventories as $inventory){
                $count += $inventory->removeMatching($filter, $max < 0 ? -1 : $max - $count);

                if($max > 0 && $count >= $max){
                    break;
                }
            }

            if($count === 0){
                $this->failure(new Translation("commands.clear.failure.no.items", [$player->getName()]));
            }else{
                $this->success(new Translation("commands.clear.success", [$player->getName(), $count]));
            }
        }
    }
}