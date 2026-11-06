<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\inventory;

class ContainerIds{

    public const ANVIL_INPUT = 0;
    public const ANVIL_MATERIAL = 1;
    public const SMITHING_TABLE_INPUT = 3;
    public const SMITHING_TABLE_MATERIAL = 4;
    public const ARMOR = 6;
    /** chests, ender chests and hoppers */
    public const LEVEL_ENTITY = 7;
    public const BEACON_PAYMENT = 8;
    public const BREWING_STAND_INPUT = 9;
    public const BREWING_STAND_RESULT = 10;
    public const BREWING_STAND_FUEL = 11;
    public const COMBINED_HOTBAR_AND_INVENTORY = 12;
    public const CRAFTING_INPUT = 13;
    public const CRAFTING_OUTPUT_PREVIEW = 14;
    public const ENCHANTING_INPUT = 22;
    public const ENCHANTING_MATERIAL = 23;
    public const FURNACE_FUEL = 24;
    public const FURNACE_INGREDIENT = 25;
    public const FURNACE_RESULT = 26;
    public const HOTBAR = 28;
    public const INVENTORY = 29;
    public const SHULKER_BOX = 30;
    public const OFFHAND = 34;
    public const LOOM_INPUT = 41;
    public const LOOM_DYE = 42;
    public const LOOM_MATERIAL = 43;
    public const BLAST_FURNACE_INGREDIENT = 45;
    public const SMOKER_INGREDIENT = 46;
    public const GRINDSTONE_INPUT = 50;
    public const GRINDSTONE_ADDITIONAL = 51;
    public const STONECUTTER_INPUT = 53;
    public const CARTOGRAPHY_INPUT = 55;
    public const CARTOGRAPHY_ADDITIONAL = 56;
    public const BARREL = 58;
    public const CURSOR = 59;
    public const CREATED_OUTPUT = 60;
    public const SMITHING_TABLE_TEMPLATE = 61;
    public const CRAFTER = 62;

    private function __construct(){
    }
}