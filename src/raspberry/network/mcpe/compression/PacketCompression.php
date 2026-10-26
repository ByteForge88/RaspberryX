<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\compression;

use raspberry\network\mcpe\protocol\PacketBatch;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use UnexpectedValueException;

use function extension_loaded;
use function gzdeflate;
use function gzinflate;
use function ord;
use function strlen;
use function substr;

class PacketCompression{

    private const HEADER_DEFLATE = 0x00;
    private const HEADER_UNCOMPRESSED = 0xff;

    private bool $enabled = false;
    private int $threshold = 256;

    public function __construct(){
        if(!extension_loaded("zlib")){
            throw new RuntimeException("The zlib extension is required for packet compression");
        }
    }

    public function isEnabled() : bool{
        return $this->enabled;
    }

    public function enable(int $threshold = 256) : void{
        if($this->enabled){
            throw new LogicException("Compression has already been negotiated");
        }

        if($threshold < 1 || $threshold > 65535){
            throw new InvalidArgumentException("Compression threshold must be between 1 and 65535");
        }

        $this->threshold = $threshold;
        $this->enabled = true;
    }

    public function encode(string $batch) : string{
        $this->checkSize($batch);

        if(!$this->enabled){
            return $batch;
        }

        if(strlen($batch) < $this->threshold){
            $payload = "\xff" . $batch;
        }else{
            $compressed = gzdeflate($batch, 6);

            if($compressed === false){
                throw new RuntimeException("Unable to compress packet batch");
            }

            $payload = "\x00" . $compressed;
        }

        $this->checkSize($payload);

        return $payload;
    }

    public function decode(string $payload) : string{
        $this->checkSize($payload);

        if(!$this->enabled){
            return $payload;
        }

        if(strlen($payload) < 2){
            throw new UnexpectedValueException("Compression header has no batch data");
        }

        $algorithm = ord($payload[0]);
        $body = substr($payload, 1);

        if($algorithm === self::HEADER_UNCOMPRESSED){
            return $body;
        }

        if($algorithm !== self::HEADER_DEFLATE){
            throw new UnexpectedValueException("Unsupported compression header: " . $algorithm);
        }

        $batch = @gzinflate($body, PacketBatch::MAX_BATCH_BYTES);

        if($batch === false){
            throw new UnexpectedValueException("Invalid DEFLATE batch or decompressed size limit exceeded");
        }

        $this->checkSize($batch);

        return $batch;
    }

    private function checkSize(string $buffer) : void{
        $length = strlen($buffer);

        if($length === 0){
            throw new UnexpectedValueException("Packet batch cannot be empty");
        }

        if($length > PacketBatch::MAX_BATCH_BYTES){
            throw new UnexpectedValueException("Packet batch exceeds the maximum size");
        }
    }
}