<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\recipe;

use raspberry\network\encoding\BinaryWriter;

class ItemDescriptor{

    public const ANY_META = 32767;

    public ?string $name;

    public int $meta;

    public ?string $tag;

    public int $count;

    public function __construct(?string $name, int $meta, ?string $tag, int $count){
        $this->name = $name;
        $this->meta = $meta;
        $this->tag = $tag;
        $this->count = $count;
    }

    public static function empty() : self{
        return new self(null, 0, null, 0);
    }

    public function write(BinaryWriter $writer) : void{
        if($this->name === null && $this->tag === null){
            $writer->writeUnsignedVarInt(0);
            $writer->writeVarInt(self::ANY_META);
        }elseif($this->tag !== null){
            $writer->writeUnsignedVarInt(1);
            $writer->writeString("item_tag");
            $writer->writeString($this->tag);
            $writer->writeVarInt(self::ANY_META);
        }else{
            $writer->writeUnsignedVarInt(1);
            $writer->writeString("name");
            $writer->writeString($this->name);
            $writer->writeVarInt($this->meta);
        }

        $writer->writeVarInt($this->count);
    }
}