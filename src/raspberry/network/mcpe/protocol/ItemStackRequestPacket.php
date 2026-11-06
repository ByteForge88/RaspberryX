<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\inventory\ContainerIds;
use raspberry\network\mcpe\protocol\types\inventory\ItemStackRequest;
use raspberry\network\mcpe\protocol\types\inventory\StackRequestAction;
use raspberry\network\mcpe\protocol\types\inventory\StackRequestSlot;

use UnexpectedValueException;

class ItemStackRequestPacket implements Packet{

    private const MAX_REQUESTS = 64;
    private const MAX_ACTIONS = 64;
    private const MAX_LIST = 64;
    private const MAX_FILTER_STRING = 1024;

    /** @var list<ItemStackRequest> */
    public array $requests = [];

    public static function getPacketId() : int{
        return ProtocolPacketIds::ITEM_STACK_REQUEST_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        throw new UnexpectedValueException("ItemStackRequestPacket is client-to-server only");
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->requests = [];

        for($i = 0, $count = self::readCount($reader, self::MAX_REQUESTS); $i < $count; ++$i){
            $this->requests[] = self::readRequest($reader);
        }
    }

    public static function readRequest(BinaryReader $reader) : ItemStackRequest{
        $requestId = $reader->readVarInt();
        $actions = [];

        for($j = 0, $actionCount = self::readCount($reader, self::MAX_ACTIONS); $j < $actionCount; ++$j){
            $actions[] = self::readAction($reader);
        }

        $filterStrings = [];

        for($j = 0, $filterCount = self::readCount($reader, self::MAX_LIST); $j < $filterCount; ++$j){
            $filterStrings[] = $reader->readString(self::MAX_FILTER_STRING);
        }

        $reader->readInt(); //filter cause

        return new ItemStackRequest($requestId, $actions, $filterStrings);
    }

    private static function readAction(BinaryReader $reader) : StackRequestAction{
        $variant = $reader->readUnsignedVarInt();
        $reader->readByte();

        $action = new StackRequestAction($variant >= 7 ? $variant + 2 : $variant);

        switch($action->type){
            case StackRequestAction::TAKE:
            case StackRequestAction::PLACE:
                $action->count = $reader->readByte();
                $action->source = self::readSlot($reader);
                $action->destination = self::readSlot($reader);
                break;

            case StackRequestAction::SWAP:
                $action->source = self::readSlot($reader);
                $action->destination = self::readSlot($reader);
                break;

            case StackRequestAction::DROP:
                $action->count = $reader->readByte();
                $action->source = self::readSlot($reader);
                $reader->readBool(); //randomly
                break;

            case StackRequestAction::DESTROY:
            case StackRequestAction::CONSUME:
                $action->count = $reader->readByte();
                $action->source = self::readSlot($reader);
                break;

            case StackRequestAction::CREATE:
                $action->resultsSlot = $reader->readByte();
                break;

            case StackRequestAction::LAB_TABLE_COMBINE:
            case StackRequestAction::CRAFT_NOT_IMPLEMENTED:
                break;

            case StackRequestAction::BEACON_PAYMENT:
                $action->primaryEffect = $reader->readVarInt();
                $action->secondaryEffect = $reader->readVarInt();
                break;

            case StackRequestAction::MINE_BLOCK:
                $hotbarSlot = $reader->readVarInt();
                $reader->readVarInt(); //durability the client predicts
                $action->source = new StackRequestSlot(ContainerIds::INVENTORY, null, $hotbarSlot & 0xff, $reader->readInt());
                break;

            case StackRequestAction::CRAFT_RECIPE:
                $action->recipeNetworkId = $reader->readUnsignedVarInt();
                $action->timesCrafted = $reader->readByte();
                break;

            case StackRequestAction::CRAFT_RECIPE_AUTO:
                $action->recipeNetworkId = $reader->readUnsignedVarInt();
                $action->timesCrafted = $reader->readByte();

                for($i = 0, $count = self::readCount($reader, self::MAX_LIST); $i < $count; ++$i){
                    self::skipDescriptor($reader);
                }
                break;

            case StackRequestAction::CRAFT_CREATIVE:
                $action->creativeItemId = $reader->readUnsignedVarInt();
                $reader->readByte(); //number of crafts
                break;

            case StackRequestAction::CRAFT_RECIPE_OPTIONAL:
                $action->recipeNetworkId = $reader->readUnsignedVarInt();
                $action->filterStringIndex = $reader->readInt();
                break;

            case StackRequestAction::CRAFT_GRINDSTONE:
                $action->recipeNetworkId = $reader->readInt();
                $action->timesCrafted = $reader->readByte();
                $reader->readVarInt(); //the cost the client shows, the server works it out itself
                break;

            case StackRequestAction::CRAFT_LOOM:
                $action->pattern = $reader->readString(64);
                $action->timesCrafted = $reader->readByte();
                break;

            case StackRequestAction::CRAFT_RESERVED:
                $reader->readString();
                $reader->readByte();
                break;

            case StackRequestAction::CRAFT_RESULTS_DEPRECATED:
                for($i = 0, $count = self::readCount($reader, self::MAX_LIST); $i < $count; ++$i){
                    self::skipResultItem($reader);
                }

                $reader->readByte(); //times crafted
                break;

            default:
                throw new UnexpectedValueException("Unknown inventory action {$action->type}");
        }

        return $action;
    }

    private static function readSlot(BinaryReader $reader) : StackRequestSlot{
        $containerId = $reader->readByte();
        $dynamicId = $reader->readBool() ? $reader->readUnsignedInt() : null;
        $slot = $reader->readByte();
        $stackNetworkId = $reader->readInt();

        return new StackRequestSlot($containerId, $dynamicId, $slot, $stackNetworkId);
    }

    private static function skipDescriptor(BinaryReader $reader) : void{
        $variant = $reader->readUnsignedVarInt();
        $reader->readByte();

        match($variant){
            0 => null,
            1 => [$reader->readString(), $reader->readVarInt()],
            2 => [$reader->readString(), $reader->readShort()],
            3 => $reader->readString(),
            default => throw new UnexpectedValueException("Unknown item descriptor {$variant}")
        };

        $reader->readUnsignedShort(); //count
    }

    private static function skipResultItem(BinaryReader $reader) : void{
        $variant = $reader->readUnsignedVarInt();
        $reader->readByte();

        if($variant === 1){
            $reader->readString();
            $reader->readVarInt();
        }elseif($variant !== 0){
            throw new UnexpectedValueException("Unknown result item descriptor {$variant}");
        }

        $reader->readShort(); //count
        $reader->readUnsignedVarInt(); //block runtime ID
        $reader->readString(); //extra data
    }

    private static function readCount(BinaryReader $reader, int $max) : int{
        $count = $reader->readUnsignedVarInt();

        if($count > $max){
            throw new UnexpectedValueException("Too many entries: {$count}, at most {$max}");
        }

        return $count;
    }
}