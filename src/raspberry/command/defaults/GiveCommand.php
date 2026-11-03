<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\item\Item;

use raspberry\math\Vector3;

use raspberry\player\Player;

use function min;

class GiveCommand extends Command{

    public const MAX_AMOUNT = 32767;

    public function __construct(){
        parent::__construct("give");

        $this->setDescription($this->translate("command.give.description"));
        $this->setPermission("raspberry.command.give");

        $this->setArgument(0, CommandParameter::target("player"));
        $this->setArgument(1, CommandParameter::enum("itemName", CommandEnum::items()));
        $this->setArgument(2, CommandParameter::int("amount", true));
        $this->setArgument(3, CommandParameter::int("data", true));
    }

    public function onSent(CommandSender $sender, array $args) :void{
        /** @var list<Player> $players */
        $players = $args["player"];
        $amount = $args["amount"] ?? 1;
        $data = $args["data"] ?? 0;

        if ($amount < 1 || $amount > self::MAX_AMOUNT) {
            throw new CommandException($this->translate("command.give.amount", ["max" => self::MAX_AMOUNT]));
        }

        if ($data < 0 || $data > 32767) {
            throw new CommandException($this->translate("command.give.data"));
        }

        $item = new Item("minecraft:" . $args["itemName"], 1, $data);

        foreach ($players as $player) {
            $this->give($player, $item, $amount);

            $sender->sendMessage($this->translate("command.give.success", [
                "item" => $item->getName(),
                "amount" => $amount,
                "player" => $player->getName()
            ]));
        }
    }

    private function give(Player $player, Item $item, int $amount) : void{
        $stacks = [];

        while ($amount > 0) {
            $count = min($amount, $item->getMaxStackSize());
            $stacks[] = (clone $item)->setCount($count);
            $amount -= $count;
        }

        foreach ($player->getInventory()->addItem(...$stacks) as $leftover) {
            $player->getWorld()->dropItem($player->getPosition(), $leftover, Vector3::zero(), 0);
        }
    }
}