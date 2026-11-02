<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

use function count;

class DeathInfoPacket implements Packet{

    public string $cause = "";

    /** @var list<string> */
    public array $parameters = [];

    /**
     * @param list<string> $parameters
     */
    public static function create(string $cause, array $parameters) : self{
        $packet = new self();
        $packet->cause = $cause;
        $packet->parameters = $parameters;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::DEATH_INFO_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeString($this->cause);
        $writer->writeUnsignedVarInt(count($this->parameters));

        foreach($this->parameters as $parameter){
            $writer->writeString($parameter);
        }
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("DeathInfoPacket is server-to-client only");
    }
}