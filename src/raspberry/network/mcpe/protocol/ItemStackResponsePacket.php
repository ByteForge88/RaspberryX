<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\inventory\ItemStackResponse;

use UnexpectedValueException;

use function count;

class ItemStackResponsePacket implements Packet{

    /** @var list<ItemStackResponse> */
    public array $responses = [];

    /**
     * @param list<ItemStackResponse> $responses
     */
    public static function create(array $responses) : self{
        $packet = new self();
        $packet->responses = $responses;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::ITEM_STACK_RESPONSE_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarInt(count($this->responses));

        foreach($this->responses as $response){
            $writer->writeByte($response->status);
            $writer->writeVarInt($response->requestId);
            $writer->writeBool($response->containers !== []);

            if($response->containers === []){
                continue;
            }

            $writer->writeUnsignedVarInt(count($response->containers));

            foreach($response->containers as $containerId => $slots){
                $writer->writeByte($containerId);
                $writer->writeBool(false); //no dynamic container ID
                $writer->writeUnsignedVarInt(count($slots));

                foreach($slots as [$slot, $count, $stackNetworkId, $customName, $durabilityCorrection]){
                    $writer->writeByte($slot);
                    $writer->writeByte($slot); //hotbar slot: the same
                    $writer->writeByte($count);
                    $writer->writeBool($stackNetworkId > 0);

                    if($stackNetworkId > 0){
                        $writer->writeVarInt($stackNetworkId);
                    }

                    $writer->writeString($customName);
                    $writer->writeBool(false); //no filtered custom name
                    $writer->writeVarInt($durabilityCorrection);
                }
            }
        }
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("ItemStackResponsePacket is server-to-client only");
    }
}