<?php

declare(strict_types=1);

namespace raspberry\utils;

abstract class TextFormat{

    //§
    public const ESCAPE = "\xc2\xa7";

    //case-break
    public const EOL = "\n";

    public const RESET = self::ESCAPE . "r";

    public const OBFUSCATED = self::ESCAPE . "k";
    public const ITALIC = self::ESCAPE . "o";
    public const BOLD = self::ESCAPE . "l";

    public const GREEN = self::ESCAPE . "a";
    public const WHITE = self::ESCAPE . "f";
    public const YELLOW = self::ESCAPE . "e";
    public const RED = self::ESCAPE . "c";
    public const AQUA = self::ESCAPE . "b";
    public const BLUE = self::ESCAPE . "9";
    public const BLACK = self::ESCAPE . "0";
    public const GRAY = self::ESCAPE . "7";
    public const GOLD = self::ESCAPE . "6";
    public const MINECOIN_GOLD = self::ESCAPE . "g";
    public const MATERIAL_GOLD = self::ESCAPE . "p";
    public const MATERIAL_EMERALD = self::ESCAPE . "q";
    public const MATERIAL_DIAMOND = self::ESCAPE . "s";
    public const MATERIAL_LAPIS = self::ESCAPE . "t";
    public const MATERIAL_AMETHYST = self::ESCAPE . "u";
    public const MATERIAL_RESIN = self::ESCAPE . "v";
    public const MATERIAL_QUARTZ = self::ESCAPE . "h";
    public const MATERIAL_IRON = self::ESCAPE . "h";
    public const MATERIAL_NETHERITE = self::ESCAPE . "j";
    public const MATERIAL_REDSTONE = self::ESCAPE . "m";
    public const MATERIAL_COPPER = self::ESCAPE . "n";
    public const LIGHT_BLUE = self::ESCAPE . "w";
    public const LIGHT_PURPLE = self::ESCAPE . "d";
    public const DARK_PURPLE = self::ESCAPE . "5";
    public const DARK_AQUA = self::ESCAPE . "3";
    public const DARK_BLUE = self::ESCAPE . "1";
    public const DARK_GRAY = self::ESCAPE . "8";
    public const DARK_RED = self::ESCAPE . "4";
    public const DARK_GREEN = self::ESCAPE . "2";

}