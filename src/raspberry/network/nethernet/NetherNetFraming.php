<?php

declare(strict_types=1);

namespace raspberry\network\nethernet;

use InvalidArgumentException;
use UnexpectedValueException;

class NetherNetFraming{

    private const MAX_PAYLOAD = 8 * 1024 * 1024;
    private const TIMEOUT_NS = 10_000_000_000;

    private string $buffer = "";
    private ?int $expected = null;
    private int $startedAt = 0;

    public function checkTimeout() : void{
        if($this->expected !== null && hrtime(true) - $this->startedAt > self::TIMEOUT_NS){
            $this->reset();

            throw new UnexpectedValueException("NetherNet fragmented message timed out");
        }
    }

    public function decode(string $message, bool $reliable) : ?string{
        $this->checkTimeout();

        if($message === ""){
            throw new UnexpectedValueException("Missing NetherNet message header");
        }

        $remaining = ord($message[0]);
        $payload = substr($message, 1);

        if(!$reliable){
            if($remaining !== 0){
                throw new UnexpectedValueException("Unreliable messages cannot be fragmented");
            }

            if(strlen($payload) > self::MAX_PAYLOAD){
                throw new UnexpectedValueException("Message too large");
            }

            return $payload;
        }

        if($this->expected !== null && $remaining !== $this->expected){
            $this->reset();

            throw new UnexpectedValueException("Invalid NetherNet fragment sequence");
        }

        if(strlen($this->buffer) + strlen($payload) > self::MAX_PAYLOAD){
            $this->reset();

            throw new UnexpectedValueException("Message too large");
        }

        if($this->expected === null && $remaining > 0){
            $this->startedAt = hrtime(true);
        }

        $this->buffer .= $payload;

        if($remaining > 0){
            $this->expected = $remaining - 1;
            return null;
        }

        $complete = $this->buffer;
        $this->reset();

        return $complete;
    }

    /**
     * @return list<string>
     */
    public function encode(string $payload, int $maxMessageBytes, bool $reliable = true) : array{
        if($maxMessageBytes < 2){
            throw new InvalidArgumentException("Invalid channel message size");
        }

        $length = strlen($payload);

        if($length > self::MAX_PAYLOAD){
            throw new InvalidArgumentException("Message too large");
        }

        $chunkSize = $maxMessageBytes - 1;

        if(!$reliable && $length > $chunkSize){
            throw new InvalidArgumentException("Unreliable message exceeds channel capacity");
        }

        $count = max(1, intdiv($length + $chunkSize - 1, $chunkSize));

        if($count > 256){
            throw new InvalidArgumentException("Too many fragments");
        }

        $messages = [];

        for($index = 0; $index < $count; ++$index){
            $messages[] = chr($count - $index - 1) .
                substr($payload, $index * $chunkSize, $chunkSize);
        }

        return $messages;
    }

    private function reset() : void{
        $this->buffer = "";
        $this->expected = null;
        $this->startedAt = 0;
    }
}