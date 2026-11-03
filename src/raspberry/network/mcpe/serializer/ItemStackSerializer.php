<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\serializer;

use raspberry\nbt\NbtException;
use raspberry\nbt\NbtSerializer;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\ItemStack;

use UnexpectedValueException;

use function count;
use function strlen;

class ItemStackSerializer{

    /** most bytes of extra data accepted from a client */
    private const MAX_EXTRA_DATA = 0x20000;

    private static int $shieldRuntimeId = -1;

    private function __construct(){
    }

    public static function setShieldRuntimeId(int $runtimeId) : void{
        self::$shieldRuntimeId = $runtimeId;
    }

    /**
     * @param int $stackNetworkId
     */
    public static function writeInstance(BinaryWriter $writer, ItemStack $item, int $stackNetworkId) : void{
        if($item->isEmpty()){
            $writer->writeShort(0);
            $writer->writeUnsignedShort(0);
            $writer->writeUnsignedVarInt(0);
            $writer->writeBool(false);
            $writer->writeUnsignedVarInt(0);
            $writer->writeUnsignedVarInt(0);
            return;
        }

        $writer->writeShort($item->networkId);
        $writer->writeUnsignedShort($item->count);
        $writer->writeUnsignedVarInt($item->meta);
        $writer->writeBool($stackNetworkId !== 0);

        if($stackNetworkId !== 0){
            $writer->writeVarInt($stackNetworkId);
        }

        $writer->writeUnsignedVarInt($item->blockRuntimeId & 0xffffffff);
        self::writeExtraData($writer, $item);
    }

    /**
     * @return array{ItemStack, int}
     */
    public static function readInstance(BinaryReader $reader) : array{
        $networkId = $reader->readShort();
        $count = $reader->readUnsignedShort();
        $meta = $reader->readUnsignedVarInt();
        $stackNetworkId = $reader->readBool() ? $reader->readVarInt() : 0;
        $blockRuntimeId = $reader->readUnsignedVarInt();

        $item = new ItemStack($networkId, $count, $meta, $blockRuntimeId);
        self::readExtraData($reader, $item, $networkId !== 0);

        return [$item, $stackNetworkId];
    }

    public static function writeStack(BinaryWriter $writer, ItemStack $item) : void{
        if($item->isEmpty()){
            $writer->writeVarInt(0);
            $writer->writeUnsignedShort(0);
            $writer->writeUnsignedVarInt(0);
            $writer->writeVarInt(0);
            $writer->writeUnsignedVarInt(0);
            return;
        }

        $writer->writeVarInt($item->networkId);
        $writer->writeUnsignedShort($item->count);
        $writer->writeUnsignedVarInt($item->meta);
        $writer->writeVarInt($item->blockRuntimeId);
        self::writeExtraData($writer, $item);
    }

    private static function writeExtraData(BinaryWriter $writer, ItemStack $item) : void{
        $extra = new BinaryWriter();

        if($item->nbt !== null){
            $extra->writeShort(-1);
            $extra->writeByte(1);
            $extra->writeBytes(NbtSerializer::littleEndian()->write($item->nbt));
        }else{
            $extra->writeShort(0);
        }

        foreach([$item->canPlaceOn, $item->canDestroy] as $blocks){
            $extra->writeInt(count($blocks));

            foreach($blocks as $block){
                $extra->writeShort(strlen($block));
                $extra->writeBytes($block);
            }
        }

        if($item->networkId === self::$shieldRuntimeId){
            $extra->writeLong($item->blockingTick);
        }

        $writer->writeString($extra->getBuffer(), self::MAX_EXTRA_DATA);
    }

    private static function readExtraData(BinaryReader $reader, ItemStack $item, bool $present) : void{
        $data = $reader->readString(self::MAX_EXTRA_DATA);

        if(!$present){
            if($data !== ""){
                throw new UnexpectedValueException("An empty item has extra data");
            }

            return;
        }

        $extra = new BinaryReader($data);
        $length = $extra->readShort();

        if($length === -1){
            if($extra->readByte() !== 1){
                throw new UnexpectedValueException("Unsupported item NBT version");
            }

            try{
                $item->nbt = NbtSerializer::littleEndian()->readFrom($extra);
            }catch(NbtException $exception){
                throw new UnexpectedValueException("Invalid item NBT: " . $exception->getMessage(), 0, $exception);
            }
        }elseif($length !== 0){
            throw new UnexpectedValueException("Invalid item NBT length {$length}");
        }

        $item->canPlaceOn = self::readBlockList($extra);
        $item->canDestroy = self::readBlockList($extra);

        if($item->networkId === self::$shieldRuntimeId){
            $item->blockingTick = $extra->readLong();
        }

        if(!$extra->isFinished()){
            throw new UnexpectedValueException("Unexpected bytes after item extra data");
        }
    }

    /**
     * @return list<string>
     */
    private static function readBlockList(BinaryReader $reader) : array{
        $count = $reader->readInt();

        if($count < 0 || $count > 64){
            throw new UnexpectedValueException("Invalid block list length {$count}");
        }

        $blocks = [];

        for($i = 0; $i < $count; ++$i){
            $blocks[] = $reader->readBytes($reader->readShort());
        }

        return $blocks;
    }
}