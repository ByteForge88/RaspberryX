<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandException;
use raspberry\command\CommandSender;
use raspberry\command\ParameterBuilder;

use raspberry\command\argument\IntArgument;
use raspberry\command\argument\ItemArgument;
use raspberry\command\argument\JsonArgument;
use raspberry\command\argument\TargetArgument;

use raspberry\item\Item;

use raspberry\lang\Translation;

use raspberry\math\Vector3;

use raspberry\player\Player;

use function is_array;
use function min;
use function substr;

class GiveCommand extends CommandBuilder{

    public const int MAX_AMOUNT = 32767;

    public const int MAX_DATA = 32767;

    protected function configure() : void{
        $this->setName("give");
        $this->setDescription("commands.give.description");
        $this->setPermission("raspberry.command.give");

        $builder = new ParameterBuilder(0);
        $builder->addArgument(0, new TargetArgument("player"));
        $builder->addArgument(1, new ItemArgument("itemName"));
        $builder->addArgument(2, new IntArgument("amount", true));
        $builder->addArgument(3, new IntArgument("data", true));
        $builder->addArgument(4, new JsonArgument("components", true));
        $this->addParameterBuilder($builder);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        /** @var list<Player> $players */
        $players = $args["player"];
        $id = $args["itemName"];
        $amount = $args["amount"] ?? 1;
        $data = $args["data"] ?? 0;

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
            $this->success(new Translation("commands.give.success", [$item->getName(), $amount, $player->getName()]));

            if($player !== $sender){
                $player->sendTranslation("%commands.give.successRecipient", [$item->getName(), (string) $amount]);
            }
        }
    }

    /**
     * @param array<mixed> $components
     */
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