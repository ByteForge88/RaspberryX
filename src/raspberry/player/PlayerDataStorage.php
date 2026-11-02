<?php

declare(strict_types=1);

namespace raspberry\player;

use raspberry\nbt\NbtException;
use raspberry\nbt\NbtSerializer;
use raspberry\nbt\tag\CompoundTag;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

use RuntimeException;

use function file_get_contents;
use function is_file;
use function preg_match;
use function rename;
use function time;
use function zlib_decode;
use function zlib_encode;

use const ZLIB_ENCODING_DEFLATE;

class PlayerDataStorage{

    private string $path;

    private Filesystem $filesystem;

    public function __construct(string $path){
        $this->path = $path;
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($path);
    }

    public function exists(string $xuid) : bool{
        return is_file($this->filePath($xuid));
    }

    public function load(string $xuid) : ?CompoundTag{
        $file = $this->filePath($xuid);

        if(!is_file($file)){
            return null;
        }

        $compressed = file_get_contents($file);
        $data = $compressed === false ? false : @zlib_decode($compressed);

        try{
            if($data === false){
                throw new NbtException("Cannot read or decompress the file");
            }

            return NbtSerializer::littleEndian()->read($data);
        }catch(NbtException $exception){
            @rename($file, $file . "." . time() . ".broken");
            throw $exception;
        }
    }

    public function save(string $xuid, CompoundTag $data) : void{
        $compressed = zlib_encode(NbtSerializer::littleEndian()->write($data), ZLIB_ENCODING_DEFLATE);

        if($compressed === false){
            throw new RuntimeException("Cannot compress the player data of {$xuid}");
        }

        $this->filesystem->dumpFile($this->filePath($xuid), $compressed);
    }

    private function filePath(string $xuid) : string{
        if(preg_match('/^\d{1,20}$/D', $xuid) !== 1){
            throw new RuntimeException("Invalid XUID \"{$xuid}\"");
        }

        return Path::join($this->path, $xuid . ".dat");
    }
}