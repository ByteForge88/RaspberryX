<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class ToastRequestPacket implements Packet{

    public string $title = "";

    public string $body = "";

    public static function create(string $title, string $body) : self{
        $packet = new self();
        $packet->title = $title;
        $packet->body = $body;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::TOAST_REQUEST_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeString($this->title);
        $writer->writeString($this->body);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->title = $reader->readString();
        $this->body = $reader->readString();
    }
}