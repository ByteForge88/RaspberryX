<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

use function strlen;

class LoginPacket implements Packet{

    public const MAX_AUTH_BYTES = 65536;
    public const MAX_CLIENT_DATA_BYTES = 2 * 1024 * 1024;
    public const MAX_CONNECTION_BYTES = 2 * 1024 * 1024;

    public int $protocolVersion = 0;
    public string $authInfoJson = "";
    public string $clientDataJwt = "";

    public static function getPacketId() : int{
        return ProtocolPacketIds::LOGIN_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        self::checkLength(
            strlen($this->authInfoJson), self::MAX_AUTH_BYTES);

        self::checkLength(strlen($this->clientDataJwt), self::MAX_CLIENT_DATA_BYTES);

        $connection = new BinaryWriter();

        $connection->writeUnsignedInt(strlen($this->authInfoJson));
        $connection->writeBytes($this->authInfoJson);

        $connection->writeUnsignedInt(strlen($this->clientDataJwt));
        $connection->writeBytes($this->clientDataJwt);

        $writer->writeInt($this->protocolVersion, false);

        $writer->writeString($connection->getBuffer(), self::MAX_CONNECTION_BYTES);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->protocolVersion = $reader->readInt(false);

        $connection = new BinaryReader($reader->readString(self::MAX_CONNECTION_BYTES));

        $authLength = $connection->readUnsignedInt();
        self::checkLength($authLength, self::MAX_AUTH_BYTES);

        $this->authInfoJson = $connection->readBytes($authLength);

        $clientLength = $connection->readUnsignedInt();
        self::checkLength($clientLength, self::MAX_CLIENT_DATA_BYTES);

        $this->clientDataJwt = $connection->readBytes($clientLength);

        if(!$connection->isFinished()){
            throw new UnexpectedValueException("Login connection request contains trailing bytes");
        }
    }

    private static function checkLength(int $length, int $maximum) : void{
        if($length < 1 || $length > $maximum){
            throw new UnexpectedValueException("Login field length is outside the permitted range");
        }
    }
}