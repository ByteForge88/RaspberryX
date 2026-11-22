<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandSender;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

use function preg_match;

/**
 * A slot like slot.weapon.mainhand or slot.armor.head, as in /replaceitem.
 */
class EquipmentSlotArgument extends Argument{

    public function getNetworkType() : int{
        return AvailableCommandsPacket::ARG_TYPE_EQUIPMENT_SLOT;
    }

    public function getTypeName() : string{
        return "equipment slots";
    }

    public function accepts(string $text, CommandSender $sender) : bool{
        return preg_match('/^slot\.[a-z_]+(\.[a-z_]+)*$/D', $text) === 1;
    }

    public function read(string $text, CommandSender $sender) : string{
        return $text;
    }
}