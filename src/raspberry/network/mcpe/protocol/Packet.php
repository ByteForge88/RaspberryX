<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

interface Packet{

    public static function getPacketId() : int;

    public function encodePayload(BinaryWriter $writer) : void;

    public function decodePayload(BinaryReader $reader) : void;

}