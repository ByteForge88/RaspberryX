<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

use function count;

class UnlockedRecipesPacket implements Packet{

    public const TYPE_EMPTY = 0;
    public const TYPE_INITIALLY_UNLOCKED = 1;
    public const TYPE_NEWLY_UNLOCKED = 2;
    public const TYPE_REMOVE_UNLOCKED = 3;
    public const TYPE_REMOVE_ALL_UNLOCKED = 4;

    public int $type = self::TYPE_EMPTY;

    /** @var list<string> */
    public array $recipes = [];

    /**
     * @param list<string> $recipes
     */
    public static function create(int $type, array $recipes) : self{
        $packet = new self();
        $packet->type = $type;
        $packet->recipes = $recipes;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::UNLOCKED_RECIPES_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedInt($this->type);
        $writer->writeUnsignedVarInt(count($this->recipes));

        foreach($this->recipes as $recipe){
            $writer->writeString($recipe);
        }
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("UnlockedRecipesPacket is server-to-client only");
    }
}