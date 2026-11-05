<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class SpawnParticleEffectPacket implements Packet{

    /** 0 overworld, 1 nether, 2 end */
    public int $dimension = 0;

    /** the entity it follows, -1 for none */
    public int $actorUniqueId = -1;

    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;

    public string $particle = "";

    /** MoLang variables as JSON, null for none */
    public ?string $molangVariables = null;

    public static function create(string $particle, float $x, float $y, float $z, int $dimension = 0) : self{
        $packet = new self();
        $packet->particle = $particle;
        $packet->x = $x;
        $packet->y = $y;
        $packet->z = $z;
        $packet->dimension = $dimension;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::SPAWN_PARTICLE_EFFECT_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeByte($this->dimension);
        $writer->writeVarLong($this->actorUniqueId);
        $writer->writeFloat($this->x);
        $writer->writeFloat($this->y);
        $writer->writeFloat($this->z);
        $writer->writeString($this->particle);
        $writer->writeBool($this->molangVariables !== null);

        if($this->molangVariables !== null){
            $writer->writeString($this->molangVariables);
        }
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("SpawnParticleEffectPacket is only sent by the server");
    }
}