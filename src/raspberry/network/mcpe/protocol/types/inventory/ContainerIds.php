<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\inventory;

class ContainerIds{

    public const int ANVIL_INPUT = 0;
    public const int ANVIL_MATERIAL = 1;
    public const int SMITHING_TABLE_INPUT = 3;
    public const int SMITHING_TABLE_MATERIAL = 4;
    public const int ARMOR = 6;
    /** chests, ender chests and hoppers */
    public const int LEVEL_ENTITY = 7;
    public const int BEACON_PAYMENT = 8;
    public const int BREWING_STAND_INPUT = 9;
    public const int BREWING_STAND_RESULT = 10;
    public const int BREWING_STAND_FUEL = 11;
    public const int COMBINED_HOTBAR_AND_INVENTORY = 12;
    public const int CRAFTING_INPUT = 13;
    public const int CRAFTING_OUTPUT_PREVIEW = 14;
    public const int ENCHANTING_INPUT = 22;
    public const int ENCHANTING_MATERIAL = 23;
    public const int FURNACE_FUEL = 24;
    public const int FURNACE_INGREDIENT = 25;
    public const int FURNACE_RESULT = 26;
    public const int HOTBAR = 28;
    public const int INVENTORY = 29;
    public const int SHULKER_BOX = 30;
    public const int OFFHAND = 34;
    public const int LOOM_INPUT = 41;
    public const int LOOM_DYE = 42;
    public const int LOOM_MATERIAL = 43;
    public const int BLAST_FURNACE_INGREDIENT = 45;
    public const int SMOKER_INGREDIENT = 46;
    public const int GRINDSTONE_INPUT = 50;
    public const int GRINDSTONE_ADDITIONAL = 51;
    public const int STONECUTTER_INPUT = 53;
    public const int CARTOGRAPHY_INPUT = 55;
    public const int CARTOGRAPHY_ADDITIONAL = 56;
    public const int BARREL = 58;
    public const int CURSOR = 59;
    public const int CREATED_OUTPUT = 60;
    public const int SMITHING_TABLE_TEMPLATE = 61;
    public const int CRAFTER = 62;

    private function __construct(){
    }
}