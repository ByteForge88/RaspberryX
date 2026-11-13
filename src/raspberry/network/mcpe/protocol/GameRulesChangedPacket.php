<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

use function count;
use function is_bool;
use function is_int;

class GameRulesChangedPacket implements Packet{

    private const TYPE_BOOL = 1;
    private const TYPE_INT = 2;
    private const TYPE_FLOAT = 3;

    /** @var array<string, bool|int|float> */
    public array $gameRules = [];

    /**
     * @param array<string, bool|int|float> $gameRules
     */
    public static function create(array $gameRules) : self{
        $packet = new self();
        $packet->gameRules = $gameRules;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::GAME_RULES_CHANGED_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarInt(count($this->gameRules));

        foreach($this->gameRules as $name => $value){
            $writer->writeString($name);
            $writer->writeBool(false); //editable by players

            if(is_bool($value)){
                $writer->writeUnsignedVarInt(self::TYPE_BOOL);
                $writer->writeBool($value);
            }elseif(is_int($value)){
                $writer->writeUnsignedVarInt(self::TYPE_INT);
                $writer->writeUnsignedInt($value);
            }else{
                $writer->writeUnsignedVarInt(self::TYPE_FLOAT);
                $writer->writeFloat($value);
            }
        }
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("GameRulesChangedPacket is server-to-client only");
    }
}