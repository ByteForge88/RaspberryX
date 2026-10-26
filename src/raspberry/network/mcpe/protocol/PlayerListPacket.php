<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;
use raspberry\network\mcpe\protocol\types\PlayerListEntry;
use raspberry\network\mcpe\serializer\SkinSerializer;

use LogicException;
use UnexpectedValueException;

use function count;

class PlayerListPacket implements Packet{

    private const ACTION_ADD = 0;
    private const ACTION_REMOVE = 1;

    /** @var list<PlayerListEntry> */
    public array $entries = [];

    /**
     * @param list<PlayerListEntry> $entries
     */
    public static function create(array $entries) : self{
        $packet = new self();
        $packet->entries = $entries;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::PLAYER_LIST_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarInt(count($this->entries));

        foreach($this->entries as $entry){
            $writer->writeUnsignedVarInt($entry->add ? 1 : 0); //entry variant: 1 = add, 0 = remove
            $writer->writeByte($entry->add ? self::ACTION_ADD : self::ACTION_REMOVE); //legacy action
            $writer->writeUuid($entry->uuid);

            if(!$entry->add){
                continue;
            }

            $skin = $entry->skin ?? throw new LogicException("A player list add entry needs a skin");

            $writer->writeVarLong($entry->actorUniqueId);
            $writer->writeString($entry->username);
            $writer->writeString($entry->xuid);
            $writer->writeString(""); //platform chat ID
            $writer->writeInt($entry->buildPlatform);
            SkinSerializer::write($writer, $skin);
            $writer->writeBool(false); //teacher (Education Edition)
            $writer->writeBool(false); //host
            $writer->writeBool(false); //sub-client
            $writer->writeInt(0, false); //name colour (ARGB, big-endian): default
        }
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("PlayerListPacket is server-to-client only");
    }
}