<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\inventory\TransactionAction;
use raspberry\network\mcpe\protocol\types\inventory\UseItemData;
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

    /** set for TYPE_USE_ITEM */
    public ?UseItemData $useItem = null;

    /** set for TYPE_RELEASE_ITEM: 0 let go (a bow), 1 finished eating or drinking */
    public ?int $releaseAction = null;

    public static function getPacketId() : int{
        return ProtocolPacketIds::INVENTORY_TRANSACTION_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        throw new UnexpectedValueException("InventoryTransactionPacket is client-to-server only");
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->legacyRequestId = $reader->readVarInt();
        $this->legacySlots = self::readLegacySlots($reader);
        $this->type = $reader->readUnsignedVarInt();
        $this->actions = self::readActions($reader);
        $this->useItem = null;
        $this->releaseAction = null;

        match($this->type){
            self::TYPE_NORMAL, self::TYPE_MISMATCH => null,
            self::TYPE_USE_ITEM => $this->useItem = UseItemData::read($reader),
            self::TYPE_USE_ITEM_ON_ENTITY => self::skipUseItemOnEntity($reader),
            self::TYPE_RELEASE_ITEM => $this->releaseAction = self::readReleaseItem($reader),
            default => throw new UnexpectedValueException("Unknown inventory transaction type {$this->type}")
        };
    }

    /**
     * @return array<int, list<int>> container ID => slots
     */
    public static function readLegacySlots(BinaryReader $reader) : array{
        $legacySlots = [];

        if($reader->readBool()){
            for($i = 0, $count = self::readCount($reader); $i < $count; ++$i){
                $containerId = $reader->readByte();
                $slots = $reader->readString();

                for($j = 0, $length = strlen($slots); $j < $length; ++$j){
                    $legacySlots[$containerId][] = ord($slots[$j]);
                }
            }
        }

        return $legacySlots;
    }

    /**
     * @return list<TransactionAction>
     */
    public static function readActions(BinaryReader $reader) : array{
        $actions = [];

        for($i = 0, $count = self::readCount($reader); $i < $count; ++$i){
            $sourceType = $reader->readUnsignedVarInt();
            $windowId = $reader->readBool() ? self::signedByte($reader->readByte()) : null;
            $sourceFlags = $reader->readBool() ? $reader->readUnsignedVarInt() : null;
            $slot = $reader->readUnsignedVarInt();
            [$oldItem] = ItemStackSerializer::readInstance($reader);
            [$newItem] = ItemStackSerializer::readInstance($reader);

            $actions[] = new TransactionAction($sourceType, $windowId, $sourceFlags, $slot, $oldItem, $newItem);
        }

        return $actions;
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

    private static function readReleaseItem(BinaryReader $reader) : int{
        $action = $reader->readVarInt();
        $reader->readVarInt(); //hotbar slot
        ItemStackSerializer::readInstance($reader);
        self::skipVector($reader); //head position
        $reader->readByte(); //hand

        return $action;
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