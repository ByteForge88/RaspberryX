<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\biome\BiomeDefinitionData;

use UnexpectedValueException;

use function count;

class BiomeDefinitionListPacket implements Packet{

    /** @var list<BiomeDefinitionData> */
    public array $definitions = [];

    /**
     * @param list<BiomeDefinitionData> $definitions
     */
    public static function create(array $definitions) : self{
        $packet = new self();
        $packet->definitions = $definitions;

        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::BIOME_DEFINITION_LIST_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $strings = [];
        $indices = [];

        $index = function(string $value) use (&$strings, &$indices) : int{
            if(!isset($indices[$value])){
                $indices[$value] = count($strings);
                $strings[] = $value;
            }

            return $indices[$value];
        };

        $writer->writeUnsignedVarInt(count($this->definitions));

        foreach($this->definitions as $definition){
            $writer->writeUnsignedShort($index($definition->name));
            $writer->writeUnsignedShort($definition->id);
            $writer->writeFloat($definition->temperature);
            $writer->writeFloat($definition->downfall);
            $writer->writeFloat($definition->foliageSnow);
            $writer->writeFloat($definition->depth);
            $writer->writeFloat($definition->scale);
            $writer->writeUnsignedInt($definition->mapWaterColor);
            $writer->writeBool($definition->rain);
            $writer->writeBool($definition->tags !== null);

            if($definition->tags !== null){
                $writer->writeUnsignedVarInt(count($definition->tags));

                foreach($definition->tags as $tag){
                    $writer->writeUnsignedShort($index($tag));
                }
            }

            $writer->writeBool(false);
        }

        $writer->writeUnsignedVarInt(count($strings));

        foreach($strings as $string){
            $writer->writeString($string);
        }
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("BiomeDefinitionListPacket is server-to-client only");
    }
}