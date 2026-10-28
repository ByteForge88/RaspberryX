<?php

declare(strict_types=1);

namespace raspberry\utils;

use function preg_replace;
use function preg_replace_callback;
use function strtolower;

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
    public const MATERIAL_IRON = self::ESCAPE . "i";
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


    /** color codes as 24-bit terminal colors, using the same RGB values as the game */
    private const ANSI = [
        "0" => "\e[38;2;0;0;0m",
        "1" => "\e[38;2;0;0;170m",
        "2" => "\e[38;2;0;170;0m",
        "3" => "\e[38;2;0;170;170m",
        "4" => "\e[38;2;170;0;0m",
        "5" => "\e[38;2;170;0;170m",
        "6" => "\e[38;2;255;170;0m",
        "7" => "\e[38;2;198;198;198m",
        "8" => "\e[38;2;85;85;85m",
        "9" => "\e[38;2;85;85;255m",
        "a" => "\e[38;2;85;255;85m",
        "b" => "\e[38;2;85;255;255m",
        "c" => "\e[38;2;255;85;85m",
        "d" => "\e[38;2;255;85;255m",
        "e" => "\e[38;2;255;255;85m",
        "f" => "\e[38;2;255;255;255m",
        "g" => "\e[38;2;221;214;5m",
        "h" => "\e[38;2;227;212;209m",
        "i" => "\e[38;2;206;202;202m",
        "j" => "\e[38;2;68;58;59m",
        "m" => "\e[38;2;151;22;7m",
        "n" => "\e[38;2;180;104;77m",
        "p" => "\e[38;2;222;177;45m",
        "q" => "\e[38;2;17;160;54m",
        "s" => "\e[38;2;44;186;168m",
        "t" => "\e[38;2;33;73;123m",
        "u" => "\e[38;2;154;92;198m",
        "v" => "\e[38;2;235;114;20m",
        "l" => "\e[1m",
        "o" => "\e[3m",
        "r" => "\e[0m"
    ];

    /**
     * Removes all color and format codes.
     */
    public static function clean(string $text) : string{
        return preg_replace('/\x{a7}./su', '', $text) ?? $text;
    }

    /**
     * Turns color and format codes into terminal escape codes; codes a terminal cannot show are removed.
     */
    public static function toAnsi(string $text) : string{
        return preg_replace_callback('/\x{a7}(.)/su', fn(array $match) : string => self::ANSI[strtolower($match[1])] ?? "", $text) ?? $text;
    }
}