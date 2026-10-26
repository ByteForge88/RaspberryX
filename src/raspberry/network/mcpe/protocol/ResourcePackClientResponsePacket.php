<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

use function count;
use function is_string;

class ResourcePackClientResponsePacket implements Packet{

    public const CANCEL = 0;
    public const DOWNLOADING = 1;
    public const DOWNLOADING_FINISHED = 2;
    public const STACK_FINISHED = 3;

    private const NAMES = [
        self::CANCEL => "cancel",
        self::DOWNLOADING => "downloading",
        self::DOWNLOADING_FINISHED => "downloadingfinished",
        self::STACK_FINISHED => "resourcepackstackfinished"
    ];

    private const MAX_PACKS = 128;
    private const MAX_PACK_ID_BYTES = 256;

    public int $status = self::CANCEL;

    /** @var list<string> */
    public array $packIds = [];

    public static function getPacketId() : int{
        return ProtocolPacketIds::RESOURCE_PACK_CLIENT_RESPONSE_PACKET;
    }

    public function getStatusName() : string{
        return self::NAMES[$this->status] ?? throw new UnexpectedValueException("Unknown resource-pack response selector");
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $name = $this->getStatusName();

        if(count($this->packIds) > self::MAX_PACKS){
            throw new UnexpectedValueException("Too many requested resource packs");
        }

        if($this->status !== self::DOWNLOADING && $this->packIds !== []){
            throw new UnexpectedValueException("Only the downloading response can contain pack IDs");
        }

        $writer->writeUnsignedVarInt($this->status);
        $writer->writeString($name, 32);

        if($this->status === self::DOWNLOADING){
            $writer->writeUnsignedVarInt(count($this->packIds));

            foreach($this->packIds as $packId){
                if(!is_string($packId) || $packId === ""){
                    throw new UnexpectedValueException("Invalid requested resource-pack ID");
                }

                $writer->writeString($packId, self::MAX_PACK_ID_BYTES);
            }
        }
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->status = $reader->readUnsignedVarInt();
        $expectedName = $this->getStatusName();

        $receivedName = $reader->readString(32);

        if($receivedName !== $expectedName){
            throw new UnexpectedValueException("Resource-pack response selector and name disagree");
        }

        $this->packIds = [];

        if($this->status === self::DOWNLOADING){
            $count = $reader->readUnsignedVarInt();

            if($count > self::MAX_PACKS){
                throw new UnexpectedValueException("Too many requested resource packs");
            }

            for($index = 0; $index < $count; ++$index){
                $packId = $reader->readString(self::MAX_PACK_ID_BYTES);

                if($packId === ""){
                    throw new UnexpectedValueException("Requested resource-pack ID cannot be empty");
                }

                $this->packIds[] = $packId;
            }
        }
    }
}