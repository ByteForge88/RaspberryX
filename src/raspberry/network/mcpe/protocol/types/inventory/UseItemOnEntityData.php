<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\inventory;

use raspberry\network\encoding\BinaryReader;

use raspberry\network\mcpe\serializer\ItemStackSerializer;

class UseItemOnEntityData{

    public const ACTION_INTERACT = 0;
    public const ACTION_ATTACK = 1;
    public const ACTION_ITEM_INTERACT = 2;

    public int $targetRuntimeId;

    public int $action;

    public int $hotbarSlot;

    /** where the player was */
    public float $playerX;
    public float $playerY;
    public float $playerZ;

    /** where on the entity the click landed */
    public float $clickX;
    public float $clickY;
    public float $clickZ;

    public function __construct(int $targetRuntimeId, int $action, int $hotbarSlot, float $playerX, float $playerY, float $playerZ, float $clickX, float $clickY, float $clickZ){
        $this->targetRuntimeId = $targetRuntimeId;
        $this->action = $action;
        $this->hotbarSlot = $hotbarSlot;
        $this->playerX = $playerX;
        $this->playerY = $playerY;
        $this->playerZ = $playerZ;
        $this->clickX = $clickX;
        $this->clickY = $clickY;
        $this->clickZ = $clickZ;
    }

    public static function read(BinaryReader $reader) : self{
        $target = $reader->readUnsignedVarLong();
        $action = $reader->readVarInt();
        $hotbarSlot = $reader->readVarInt();
        $reader->readByte(); //hand
        ItemStackSerializer::readInstance($reader); //what the client thinks it holds
        $playerX = $reader->readFloat();
        $playerY = $reader->readFloat();
        $playerZ = $reader->readFloat();
        $clickX = $reader->readFloat();
        $clickY = $reader->readFloat();
        $clickZ = $reader->readFloat();

        return new self($target, $action, $hotbarSlot, $playerX, $playerY, $playerZ, $clickX, $clickY, $clickZ);
    }
}