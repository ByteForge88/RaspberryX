<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class PlayerAuthInputPacket implements Packet{

    public const FLAG_START_SPRINTING = 25;
    public const FLAG_STOP_SPRINTING = 26;
    public const FLAG_START_SNEAKING = 27;
    public const FLAG_STOP_SNEAKING = 28;
    public const FLAG_START_SWIMMING = 29;
    public const FLAG_STOP_SWIMMING = 30;
    public const FLAG_START_JUMPING = 31;
    public const FLAG_START_GLIDING = 32;
    public const FLAG_STOP_GLIDING = 33;
    public const FLAG_START_FLYING = 42;
    public const FLAG_STOP_FLYING = 43;
    public const FLAG_VERTICAL_COLLISION = 50;

    public const FLAG_COUNT = 66;

    public float $pitch = 0.0;
    public float $yaw = 0.0;

    /** eye position */
    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;

    public float $moveX = 0.0;
    public float $moveZ = 0.0;

    public float $headYaw = 0.0;

    /** @var array<int, true> input flags that are set, as keys */
    public array $inputFlags = [];

    public static function getPacketId() : int{
        return ProtocolPacketIds::PLAYER_AUTH_INPUT_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        throw new UnexpectedValueException("PlayerAuthInputPacket is client-to-server only");
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->pitch = $reader->readFloat();
        $this->yaw = $reader->readFloat();
        $this->x = $reader->readFloat();
        $this->y = $reader->readFloat();
        $this->z = $reader->readFloat();
        $this->moveX = $reader->readFloat();
        $this->moveZ = $reader->readFloat();
        $this->headYaw = $reader->readFloat();

        $count = $reader->readUnsignedVarInt();

        if($count > self::FLAG_COUNT){
            throw new UnexpectedValueException("Too many input flags: {$count}");
        }

        for($i = 0; $i < $count; ++$i){
            $flag = $reader->readVarInt();

            if($flag < 0 || $flag >= self::FLAG_COUNT){
                throw new UnexpectedValueException("Unknown input flag {$flag}");
            }

            $this->inputFlags[$flag] = true;
        }

        $reader->readBytes($reader->remaining());
    }

    public function hasFlag(int $flag) : bool{
        return isset($this->inputFlags[$flag]);
    }
}