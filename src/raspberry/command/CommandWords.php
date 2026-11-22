<?php

declare(strict_types=1);

namespace raspberry\command;

use function ctype_space;
use function str_ends_with;
use function str_starts_with;
use function strlen;

/**
 * Cuts a command line into words. A word in "quotes" keeps its spaces, and so does anything in
 * brackets, so @a[name="Byte Forge", r=10] and {"rawtext": []} stay one word each.
 */
class CommandWords{

    private function __construct(){
    }

    /**
     * @return list<string>
     */
    public static function split(string $line) : array{
        $words = [];
        $word = "";
        $depth = 0;
        $quoted = false;

        for($i = 0, $length = strlen($line); $i < $length; ++$i){
            $char = $line[$i];

            if($quoted){
                $word .= $char;

                if($char === "\\" && $i + 1 < $length){
                    $word .= $line[++$i];
                }elseif($char === '"'){
                    $quoted = false;
                }

                continue;
            }

            if($depth === 0 && ctype_space($char)){
                if($word !== ""){
                    $words[] = $word;
                    $word = "";
                }

                continue;
            }

            if($char === '"'){
                $quoted = true;
            }elseif($char === "[" || $char === "{"){
                ++$depth;
            }elseif(($char === "]" || $char === "}") && $depth > 0){
                --$depth;
            }

            $word .= $char;
        }

        if($word !== ""){
            $words[] = $word;
        }

        return $words;
    }

    /**
     * "Byte Forge" to Byte Forge, with \" and \\ inside turned back into " and \.
     */
    public static function unquote(string $word) : string{
        if(strlen($word) < 2 || !str_starts_with($word, '"') || !str_ends_with($word, '"')){
            return $word;
        }

        $text = "";

        for($i = 1, $end = strlen($word) - 1; $i < $end; ++$i){
            if($word[$i] === "\\" && $i + 1 < $end){
                ++$i;
            }

            $text .= $word[$i];
        }

        return $text;
    }

    /**
     * Whether the quotes and brackets in the word all close.
     */
    public static function isClosed(string $word) : bool{
        $depth = 0;
        $quoted = false;

        for($i = 0, $length = strlen($word); $i < $length; ++$i){
            $char = $word[$i];

            if($quoted){
                if($char === "\\"){
                    ++$i;
                }elseif($char === '"'){
                    $quoted = false;
                }
            }elseif($char === '"'){
                $quoted = true;
            }elseif($char === "[" || $char === "{"){
                ++$depth;
            }elseif($char === "]" || $char === "}"){
                if(--$depth < 0){
                    return false;
                }
            }
        }

        return !$quoted && $depth === 0;
    }
}