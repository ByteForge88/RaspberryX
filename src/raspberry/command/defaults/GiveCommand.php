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

use raspberry\math\Vector3;

use raspberry\player\Player;

use function is_array;
use function min;
use function substr;

class GiveCommand extends Command{

    public const MAX_AMOUNT = 32767;

    public const MAX_DATA = 32767;

    public function __construct(){
        parent::__construct("give", "commands.give.description", "raspberry.command.give");

        $this->addOverload(
            CommandParameter::target("player"),
            CommandParameter::enum("itemName", CommandEnum::items()),
            CommandParameter::int("amount", true),
            CommandParameter::int("data", true),
            CommandParameter::json("components", true)
        );
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        /** @var list<Player> $players */
        $players = $args["player"];
        $amount = $args["amount"] ?? 1;
        $data = $args["data"] ?? 0;
        $name = $args["itemName"];
        $id = CommandEnum::toItemId($name) ?? throw new CommandException("commands.give.item.notFound", [$name]);

        if($amount < 1){
            throw new CommandException("commands.generic.num.tooSmall", [$amount, 1]);
        }

        if($amount > self::MAX_AMOUNT){
            throw new CommandException("commands.generic.num.tooBig", [$amount, self::MAX_AMOUNT]);
        }

        if($data < 0 || $data > self::MAX_DATA){
            throw new CommandException("commands.give.item.invalid", [substr($id, 10)]);
        }

        $item = new Item($id, 1, $data);

        if(isset($args["components"])){
            self::applyComponents($item, $args["components"]);
        }

        foreach($players as $player){
            self::give($player, $item, $amount);
            $output->success("commands.give.success", [$item->getName(), $amount, $player->getName()]);

            if($player !== $sender){
                $player->sendTranslation("%commands.give.successRecipient", [$item->getName(), (string) $amount]);
            }
        }
    }

    private static function applyComponents(Item $item, array $components) : void{
        foreach($components as $name => $value){
            if(!is_array($value)){
                throw new CommandException("commands.generic.componentError");
            }

            match($name){
                "minecraft:item_lock", "item_lock" => $item->getExtraTags()->setByte("minecraft:item_lock", match($value["mode"] ?? null){
                    "lock_in_slot" => 1,
                    "lock_in_inventory" => 2,
                    default => throw new CommandException("commands.generic.componentError")
                }),
                "minecraft:keep_on_death", "keep_on_death" => $item->getExtraTags()->setByte("minecraft:keep_on_death", 1),
                "minecraft:can_place_on", "can_place_on", "minecraft:can_destroy", "can_destroy" => is_array($value["blocks"] ?? null) ? null : throw new CommandException("commands.generic.componentError"),
                default => throw new CommandException("commands.generic.componentError")
            };
        }
    }

    private static function give(Player $player, Item $item, int $amount) : void{
        $stacks = [];

        while($amount > 0){
            $count = min($amount, $item->getMaxStackSize());
            $stacks[] = (clone $item)->setCount($count);
            $amount -= $count;
        }

        foreach($player->getInventory()->addItem(...$stacks) as $leftover){
            $player->getWorld()->dropItem($player->getPosition(), $leftover, Vector3::zero(), 0);
        }
    }
}