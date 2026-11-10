<?php

declare(strict_types=1);

namespace raspberry\command;

use function str_contains;
use function strlen;
use function substr;
use function trim;

class CommandReader{

    private string $input;

    private int $position = 0;

    public function __construct(string $input){
        $this->input = $input;
    }

    public function getInput() : string{
        return $this->input;
    }

    /**
     * Where the next argument starts, after any whitespace.
     */
    public function getPosition() : int{
        $this->skipWhitespace();

        return $this->position;
    }

    /**
     * True when only whitespace is left.
     */
    public function atEnd() : bool{
        $this->skipWhitespace();

        return $this->position >= strlen($this->input);
    }

    /**
     * @throws InvalidCommandSyntaxException
     */
    public function readWord() : string{
        if($this->atEnd()){
            throw new InvalidCommandSyntaxException($this->position);
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
            throw new InvalidCommandSyntaxException($this->position);
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
            throw new InvalidCommandSyntaxException($start);
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
     * @throws InvalidCommandSyntaxException
     */
    public function readBracketed(string $openers) : string{
        if($this->atEnd() || !str_contains($openers, $this->input[$this->position])){
            throw new InvalidCommandSyntaxException($this->position);
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

        throw new InvalidCommandSyntaxException($start);
    }

    private function readQuoted() : string{
        $start = $this->position;
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

        throw new InvalidCommandSyntaxException($start);
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