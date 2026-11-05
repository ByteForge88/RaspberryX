<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\inventory\ItemStackRequest;
use raspberry\network\mcpe\protocol\types\inventory\UseItemData;
use raspberry\network\mcpe\protocol\types\PlayerBlockAction;

use UnexpectedValueException;

class PlayerAuthInputPacket implements Packet{

    public const FLAG_SNEAKING = 8;
    public const FLAG_START_SPRINTING = 25;
    public const FLAG_STOP_SPRINTING = 26;
    public const FLAG_START_SNEAKING = 27;
    public const FLAG_STOP_SNEAKING = 28;
    public const FLAG_START_SWIMMING = 29;
    public const FLAG_STOP_SWIMMING = 30;
    public const FLAG_START_JUMPING = 31;
    public const FLAG_START_GLIDING = 32;
    public const FLAG_STOP_GLIDING = 33;
    public const FLAG_PERFORM_ITEM_INTERACTION = 34;
    public const FLAG_PERFORM_BLOCK_ACTIONS = 35;
    public const FLAG_PERFORM_ITEM_STACK_REQUEST = 36;
    public const FLAG_START_FLYING = 42;
    public const FLAG_STOP_FLYING = 43;
    public const FLAG_VERTICAL_COLLISION = 50;

    public const FLAG_COUNT = 66;

    private const MAX_BLOCK_ACTIONS = 64;

    public float $pitch = 0.0;
    public float $yaw = 0.0;

    /** eye position */
    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;

    public float $moveX = 0.0;
    public float $moveZ = 0.0;

    public float $headYaw = 0.0;

    /** @var array<int, true> */
    public array $inputFlags = [];

    /** the client's tick counter, one more for every PlayerAuthInput */
    public int $tick = 0;

    /** using the held item; only breaking blocks uses this now */
    public ?UseItemData $itemInteraction = null;

    /** an inventory request, sent when mining with a tool */
    public ?ItemStackRequest $itemStackRequest = null;

    /** @var list<PlayerBlockAction> */
    public array $blockActions = [];

    public static function getPacketId() : int{
        return ProtocolPacketIds::PLAYER_AUTH_INPUT_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        throw new UnexpectedValueException("PlayerAuthInputPacket is client-to-server only");
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->pitch = $reader->readFloat();
        $this->yaw = $reader->readFloat();
        $this->x = $reader->readFloat();
        $this->y = $reader->readFloat();
        $this->z = $reader->readFloat();
        $this->moveX = $reader->readFloat();
        $this->moveZ = $reader->readFloat();
        $this->headYaw = $reader->readFloat();

        $count = $reader->readUnsignedVarInt();

        if($count > self::FLAG_COUNT){
            throw new UnexpectedValueException("Too many input flags: {$count}");
        }

        for($i = 0; $i < $count; ++$i){
            $flag = $reader->readVarInt();

            if($flag < 0 || $flag >= self::FLAG_COUNT){
                throw new UnexpectedValueException("Unknown input flag {$flag}");
            }

            $this->inputFlags[$flag] = true;
        }

        $reader->readUnsignedVarInt(); //input mode
        $reader->readUnsignedVarInt(); //play mode
        $reader->readVarInt(); //interaction model
        $reader->readFloat(); //interact pitch
        $reader->readFloat(); //interact yaw
        $this->tick = $reader->readUnsignedVarLong();
        $reader->readFloat(); //movement delta
        $reader->readFloat();
        $reader->readFloat();

        $this->itemInteraction = null;

        if($reader->readBool()){
            $reader->readVarInt(); //legacy request ID
            InventoryTransactionPacket::readLegacySlots($reader);
            InventoryTransactionPacket::readActions($reader);
            $this->itemInteraction = UseItemData::read($reader);
        }

        $this->itemStackRequest = $reader->readBool() ? ItemStackRequestPacket::readRequest($reader) : null;
        $this->blockActions = [];

        if($reader->readBool()){
            $count = $reader->readUnsignedVarInt();

            if($count > self::MAX_BLOCK_ACTIONS){
                throw new UnexpectedValueException("Too many block actions: {$count}");
            }

            for($i = 0; $i < $count; ++$i){
                $action = $reader->readVarInt();
                $x = $reader->readVarInt();
                $y = $reader->readVarInt();
                $z = $reader->readVarInt();
                $this->blockActions[] = new PlayerBlockAction($action, $x, $y, $z, $reader->readVarInt());
            }
        }

        //vehicle rotation, predicted vehicle, analog movement, camera orientation, raw movement: not used yet
        $reader->readBytes($reader->remaining());
    }

    public function hasFlag(int $flag) : bool{
        return isset($this->inputFlags[$flag]);
    }
}