<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

use function count;

class PlayerEnchantOptionsPacket implements Packet{

    /** @var list<array{int, list<array{int, int}>, string, int}> */
    public array $options = [];

    /**
     * @param list<array{int, list<array{int, int}>, string, int}> $options
     */
    public static function create(array $options) : self{
        $packet = new self();
        $packet->options = $options;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::PLAYER_ENCHANT_OPTIONS_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarInt(count($this->options));

        foreach($this->options as $slot => [$cost, $enchantments, $name, $optionId]){
            $writer->writeByte($cost);
            $writer->writeInt($slot);

            //the client only reads the middle list
            for($list = 0; $list < 3; ++$list){
                $entries = $list === 1 ? $enchantments : [];
                $writer->writeUnsignedVarInt(count($entries));

                foreach($entries as [$id, $level]){
                    $writer->writeByte($id);
                    $writer->writeByte($level);
                }
            }

            $writer->writeString($name);
            $writer->writeUnsignedVarInt($optionId);
        }
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("PlayerEnchantOptionsPacket is only sent by the server");
    }
}