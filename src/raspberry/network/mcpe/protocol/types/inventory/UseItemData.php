<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\inventory;

use raspberry\network\encoding\BinaryReader;

use raspberry\network\mcpe\protocol\types\ItemStack;
use raspberry\network\mcpe\serializer\ItemStackSerializer;

class UseItemData{

    public const ACTION_CLICK_BLOCK = 0;
    public const ACTION_CLICK_AIR = 1;
    public const ACTION_BREAK_BLOCK = 2;

    public int $action;

    public int $x;
    public int $y;
    public int $z;

    public int $face;

    public int $hotbarSlot;

    public ItemStack $heldItem;

    /** where the player was */
    public float $playerX;
    public float $playerY;
    public float $playerZ;

    /** where on the block face the click was, 0 to 1 */
    public float $clickX;
    public float $clickY;
    public float $clickZ;

    /** the block the client thinks is there */
    public int $blockRuntimeId;

    public function __construct(int $action, int $x, int $y, int $z, int $face, int $hotbarSlot, ItemStack $heldItem, float $playerX, float $playerY, float $playerZ, float $clickX, float $clickY, float $clickZ, int $blockRuntimeId){
        $this->action = $action;
        $this->x = $x;
        $this->y = $y;
        $this->z = $z;
        $this->face = $face;
        $this->hotbarSlot = $hotbarSlot;
        $this->heldItem = $heldItem;
        $this->playerX = $playerX;
        $this->playerY = $playerY;
        $this->playerZ = $playerZ;
        $this->clickX = $clickX;
        $this->clickY = $clickY;
        $this->clickZ = $clickZ;
        $this->blockRuntimeId = $blockRuntimeId;
    }

    public static function read(BinaryReader $reader) : self{
        $action = $reader->readVarInt();
        $reader->readByte(); //trigger type
        $x = $reader->readVarInt();
        $y = $reader->readVarInt();
        $z = $reader->readVarInt();
        $face = $reader->readByte();
        $hotbarSlot = $reader->readVarInt();
        $reader->readByte(); //hand
        [$heldItem] = ItemStackSerializer::readInstance($reader);
        $playerX = $reader->readFloat();
        $playerY = $reader->readFloat();
        $playerZ = $reader->readFloat();
        $clickX = $reader->readFloat();
        $clickY = $reader->readFloat();
        $clickZ = $reader->readFloat();
        $blockRuntimeId = $reader->readUnsignedVarInt();
        $reader->readByte(); //client prediction
        $reader->readByte(); //cooldown state
        return new self($action, $x, $y, $z, $face, $hotbarSlot, $heldItem, $playerX, $playerY, $playerZ, $clickX, $clickY, $clickZ, $blockRuntimeId);
    }
}