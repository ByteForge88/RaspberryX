<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\EntityMetadata;

use raspberry\utils\Uuid;

use UnexpectedValueException;

use function str_repeat;

/**
 * Server -> client: makes another player appear. Send the player's PlayerListPacket entry first.
 */
class AddPlayerPacket implements Packet{

    /** every ability bit (1 << 20) - 1: other players' abilities are only used for display */
    private const ALL_ABILITIES = 0xfffff;

    private const ABILITY_LAYER_BASE = 1;

    public Uuid $uuid;

    public string $username = "";

    public int $actorRuntimeId = 0;

    /** eye position */
    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;

    public float $pitch = 0.0;
    public float $yaw = 0.0;
    public float $headYaw = 0.0;

    public int $gameMode = 0;

    public EntityMetadata $metadata;

    public int $buildPlatform = -1;

    public function __construct(){
        $this->uuid = new Uuid(str_repeat("\x00", 16));
        $this->metadata = new EntityMetadata();
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::ADD_PLAYER_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUuid($this->uuid);
        $writer->writeString($this->username);
        $writer->writeUnsignedVarLong($this->actorRuntimeId);
        $writer->writeString(""); //platform chat ID
        $writer->writeFloat($this->x);
        $writer->writeFloat($this->y);
        $writer->writeFloat($this->z);
        $writer->writeFloat(0.0); //velocity
        $writer->writeFloat(0.0);
        $writer->writeFloat(0.0);
        $writer->writeFloat($this->pitch);
        $writer->writeFloat($this->yaw);
        $writer->writeFloat($this->headYaw);

        //held item: empty
        $writer->writeShort(0); //network ID
        $writer->writeUnsignedShort(0); //count
        $writer->writeUnsignedVarInt(0); //metadata
        $writer->writeBool(false); //no stack network ID
        $writer->writeUnsignedVarInt(0); //block runtime ID
        $writer->writeUnsignedVarInt(0); //extra data

        $writer->writeVarInt($this->gameMode);
        $this->metadata->write($writer);
        $writer->writeUnsignedVarInt(0); //entity properties: ints
        $writer->writeUnsignedVarInt(0); //entity properties: floats

        //abilities
        $writer->writeLong($this->actorRuntimeId); //unique ID (same as runtime ID in RaspberryX)
        $writer->writeByte(1); //player permissions: member
        $writer->writeByte(0); //command permissions: normal
        $writer->writeUnsignedVarInt(1); //one layer
        $writer->writeUnsignedShort(self::ABILITY_LAYER_BASE);
        $writer->writeUnsignedInt(self::ALL_ABILITIES);
        $writer->writeUnsignedInt(0); //ability values
        $writer->writeFloat(0.0); //fly speed
        $writer->writeFloat(0.0); //vertical fly speed
        $writer->writeFloat(0.0); //walk speed

        $writer->writeUnsignedVarInt(0); //entity links
        $writer->writeBool(false); //passenger of block: not riding a block (26.60)
        $writer->writeString(""); //device ID
        $writer->writeInt($this->buildPlatform);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("AddPlayerPacket is server-to-client only");
    }
}