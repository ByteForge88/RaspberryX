<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\inventory;

class ContainerIds{

    public const ARMOR = 6;
    /** chests, ender chests and hoppers */
    public const LEVEL_ENTITY = 7;
    public const BREWING_STAND_INPUT = 9;
    public const BREWING_STAND_RESULT = 10;
    public const BREWING_STAND_FUEL = 11;
    public const COMBINED_HOTBAR_AND_INVENTORY = 12;
    public const CRAFTING_INPUT = 13;
    public const CRAFTING_OUTPUT_PREVIEW = 14;
    public const FURNACE_FUEL = 24;
    public const FURNACE_INGREDIENT = 25;
    public const FURNACE_RESULT = 26;
    public const HOTBAR = 28;
    public const INVENTORY = 29;
    public const SHULKER_BOX = 30;
    public const OFFHAND = 34;
    public const BLAST_FURNACE_INGREDIENT = 45;
    public const SMOKER_INGREDIENT = 46;
    public const BARREL = 58;
    public const CURSOR = 59;
    public const CREATED_OUTPUT = 60;

    private function __construct(){
    }
}