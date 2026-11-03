<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\inventory\TransactionAction;
use raspberry\network\mcpe\serializer\ItemStackSerializer;

use UnexpectedValueException;

use function ord;
use function strlen;

class InventoryTransactionPacket implements Packet{

    public const TYPE_NORMAL = 0;
    public const TYPE_MISMATCH = 1;
    public const TYPE_USE_ITEM = 2;
    public const TYPE_USE_ITEM_ON_ENTITY = 3;
    public const TYPE_RELEASE_ITEM = 4;

    private const MAX_LIST = 64;

    public int $legacyRequestId = 0;

    /** @var array<int, list<int>> */
    public array $legacySlots = [];

    public int $type = self::TYPE_NORMAL;

    /** @var list<TransactionAction> */
    public array $actions = [];

    public static function getPacketId() : int{
        return ProtocolPacketIds::INVENTORY_TRANSACTION_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        throw new UnexpectedValueException("InventoryTransactionPacket is client-to-server only");
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->legacyRequestId = $reader->readVarInt();
        $this->legacySlots = [];

        if($reader->readBool()){
            for($i = 0, $count = self::readCount($reader); $i < $count; ++$i){
                $containerId = $reader->readByte();
                $slots = $reader->readString();

                for($j = 0, $length = strlen($slots); $j < $length; ++$j){
                    $this->legacySlots[$containerId][] = ord($slots[$j]);
                }
            }
        }

        $this->type = $reader->readUnsignedVarInt();
        $this->actions = [];

        for($i = 0, $count = self::readCount($reader); $i < $count; ++$i){
            $sourceType = $reader->readUnsignedVarInt();
            $windowId = $reader->readBool() ? self::signedByte($reader->readByte()) : null;
            $sourceFlags = $reader->readBool() ? $reader->readUnsignedVarInt() : null;
            $slot = $reader->readUnsignedVarInt();
            [$oldItem] = ItemStackSerializer::readInstance($reader);
            [$newItem] = ItemStackSerializer::readInstance($reader);

            $this->actions[] = new TransactionAction($sourceType, $windowId, $sourceFlags, $slot, $oldItem, $newItem);
        }

        match($this->type){
            self::TYPE_NORMAL, self::TYPE_MISMATCH => null,
            self::TYPE_USE_ITEM => self::skipUseItem($reader),
            self::TYPE_USE_ITEM_ON_ENTITY => self::skipUseItemOnEntity($reader),
            self::TYPE_RELEASE_ITEM => self::skipReleaseItem($reader),
            default => throw new UnexpectedValueException("Unknown inventory transaction type {$this->type}")
        };
    }

    private static function skipUseItem(BinaryReader $reader) : void{
        $reader->readVarInt(); //action
        $reader->readByte(); //trigger type
        $reader->readVarInt(); //block x
        $reader->readVarInt(); //block y
        $reader->readVarInt(); //block z
        $reader->readByte(); //face
        $reader->readVarInt(); //hotbar slot
        $reader->readByte(); //hand
        ItemStackSerializer::readInstance($reader);
        self::skipVector($reader); //player position
        self::skipVector($reader); //click position
        $reader->readUnsignedVarInt(); //block runtime ID
        $reader->readByte(); //client prediction
        $reader->readByte(); //cooldown state
    }

    private static function skipUseItemOnEntity(BinaryReader $reader) : void{
        $reader->readUnsignedVarLong(); //target
        $reader->readVarInt(); //action
        $reader->readVarInt(); //hotbar slot
        $reader->readByte(); //hand
        ItemStackSerializer::readInstance($reader);
        self::skipVector($reader);
        self::skipVector($reader);
    }

    private static function skipReleaseItem(BinaryReader $reader) : void{
        $reader->readVarInt(); //action
        $reader->readVarInt(); //hotbar slot
        ItemStackSerializer::readInstance($reader);
        self::skipVector($reader); //head position
        $reader->readByte(); //hand
    }

    private static function skipVector(BinaryReader $reader) : void{
        $reader->readFloat();
        $reader->readFloat();
        $reader->readFloat();
    }

    private static function signedByte(int $value) : int{
        return $value >= 0x80 ? $value - 0x100 : $value;
    }

    private static function readCount(BinaryReader $reader) : int{
        $count = $reader->readUnsignedVarInt();

        if($count > self::MAX_LIST){
            throw new UnexpectedValueException("Too many entries: {$count}, at most " . self::MAX_LIST);
        }

        return $count;
    }
}