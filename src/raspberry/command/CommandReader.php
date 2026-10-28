<?php

declare(strict_types=1);

namespace raspberry\command;

use function str_contains;
use function strlen;
use function substr;
use function trim;

/**
 * Reads arguments one at a time from the text after a command name.
 */
class CommandReader{

    private string $input;

    private int $position = 0;

    public function __construct(string $input){
        $this->input = $input;
    }

    /**
     * True when only whitespace is left.
     */
    public function atEnd() : bool{
        $this->skipWhitespace();

        return $this->position >= strlen($this->input);
    }

    /**
     * Reads one word, or "text in quotes" (with \" and \\ escapes) as one word.
     *
     * @throws InvalidCommandSyntaxException when nothing is left or a quote is never closed
     */
    public function readWord() : string{
        if($this->atEnd()){
            throw new InvalidCommandSyntaxException();
        }

        if($this->input[$this->position] === '"'){
            return $this->readQuoted();
        }

        $start = $this->position;

        while($this->position < strlen($this->input) && !self::isWhitespace($this->input[$this->position])){
            ++$this->position;
        }

        return substr($this->input, $start, $this->position - $start);
    }

    /**
     * Reads a player name or a selector. A selector may have arguments in brackets, which can
     * contain spaces and quotes: @a[name="Byte Forge", r=10].
     */
    public function readTarget() : string{
        if($this->atEnd()){
            throw new InvalidCommandSyntaxException();
        }

        if($this->input[$this->position] !== "@"){
            return $this->readWord();
        }

        $start = $this->position++;
        $length = strlen($this->input);

        while($this->position < $length && self::isLetter($this->input[$this->position])){
            ++$this->position;
        }

        if($this->position < $length && $this->input[$this->position] === "["){
            $this->readBracketed("[");
        }

        if($this->position < $length && !self::isWhitespace($this->input[$this->position])){
            throw new InvalidCommandSyntaxException();
        }

        return substr($this->input, $start, $this->position - $start);
    }

    /**
     * Reads everything that is left, without the surrounding whitespace.
     */
    public function readRemaining() : string{
        $rest = trim(substr($this->input, $this->position));
        $this->position = strlen($this->input);

        return $rest;
    }

    /**
     * Reads a {...} or [...] block, including nested brackets and quoted strings, e.g. JSON.
     *
     * @param string $openers which of "{" and "[" the block may start with
     *
     * @throws InvalidCommandSyntaxException when there is no such block or it is never closed
     */
    public function readBracketed(string $openers) : string{
        if($this->atEnd() || !str_contains($openers, $this->input[$this->position])){
            throw new InvalidCommandSyntaxException();
        }

        $start = $this->position;
        $depth = 0;
        $inString = false;

        for($length = strlen($this->input); $this->position < $length; ++$this->position){
            $char = $this->input[$this->position];

            if($inString){
                if($char === "\\"){
                    ++$this->position;
                }elseif($char === '"'){
                    $inString = false;
                }
            }elseif($char === '"'){
                $inString = true;
            }elseif($char === "{" || $char === "["){
                ++$depth;
            }elseif($char === "}" || $char === "]"){
                if(--$depth === 0){
                    ++$this->position;
                    return substr($this->input, $start, $this->position - $start);
                }
            }
        }

        throw new InvalidCommandSyntaxException();
    }

    private function readQuoted() : string{
        $value = "";
        ++$this->position;

        for($length = strlen($this->input); $this->position < $length; ++$this->position){
            $char = $this->input[$this->position];

            if($char === "\\" && $this->position + 1 < $length){
                $value .= $this->input[++$this->position];
            }elseif($char === '"'){
                ++$this->position;
                return $value;
            }else{
                $value .= $char;
            }
        }

        throw new InvalidCommandSyntaxException();
    }

    private function skipWhitespace() : void{
        while($this->position < strlen($this->input) && self::isWhitespace($this->input[$this->position])){
            ++$this->position;
        }
    }

    private static function isLetter(string $char) : bool{
        return ($char >= "a" && $char <= "z") || ($char >= "A" && $char <= "Z");
    }

    private static function isWhitespace(string $char) : bool{
        return $char === " " || $char === "\t" || $char === "\n" || $char === "\r";
    }
}