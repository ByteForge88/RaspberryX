<?php

declare(strict_types=1);

namespace raspberry\world\format\io;

use raspberry\utils\Logger;
use raspberry\world\Chunk;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

use RuntimeException;
use UnexpectedValueException;

use function file_get_contents;
use function is_array;
use function is_file;
use function json_decode;
use function json_encode;
use function rename;
use function time;

/**
 * RaspberryX's own world format:
 *
 *   <world>/world.json                         metadata
 *   <world>/chunks/<rx>.<rz>/<x>.<z>.chunk     one file per saved chunk, 32x32 chunks per folder
 *
 * Every file is written to a temporary file first and then renamed, so a crash
 * while saving never leaves a half-written file behind.
 */
final class RaspberryWorldProvider implements WorldProvider{

    private const METADATA_FILE = "world.json";

    private string $path;

    private Logger $logger;

    private Filesystem $filesystem;

    public function __construct(string $path, Logger $logger){
        $this->path = $path;
        $this->logger = $logger;
        $this->filesystem = new Filesystem();
    }

    public static function exists(string $path) : bool{
        return is_file(Path::join($path, self::METADATA_FILE));
    }

    public function getPath() : string{
        return $this->path;
    }

    public function loadMetadata() : WorldMetadata{
        $file = Path::join($this->path, self::METADATA_FILE);
        $contents = @file_get_contents($file);

        if($contents === false){
            throw new RuntimeException("Cannot read world metadata: {$file}");
        }

        $data = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);

        if(!is_array($data)){
            throw new UnexpectedValueException("World metadata must be a JSON object: {$file}");
        }

        return WorldMetadata::fromArray($data);
    }

    public function saveMetadata(WorldMetadata $metadata) : void{
        $json = json_encode($metadata->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->filesystem->dumpFile(Path::join($this->path, self::METADATA_FILE), $json . "\n");
    }

    public function loadChunk(int $chunkX, int $chunkZ) : ?Chunk{
        $file = $this->chunkPath($chunkX, $chunkZ);

        if(!is_file($file)){
            return null;
        }

        $data = @file_get_contents($file);

        if($data === false){
            throw new RuntimeException("Cannot read chunk file: {$file}");
        }

        try{
            return ChunkSerializer::deserialize($data, $chunkX, $chunkZ);
        }catch(CorruptChunkException $error){
            $backup = $file . "." . time() . ".corrupt";
            @rename($file, $backup);

            $this->logger->warning($error->getMessage() . ". It will be regenerated; the damaged file was moved to " . Path::makeRelative($backup, $this->path));

            return null;
        }
    }

    public function saveChunk(Chunk $chunk) : void{
        $this->filesystem->dumpFile($this->chunkPath($chunk->getX(), $chunk->getZ()), ChunkSerializer::serialize($chunk));
    }

    public function close() : void{
        //nothing is kept open, present for formats that hold file handles
    }

    private function chunkPath(int $chunkX, int $chunkZ) : string{
        return Path::join($this->path, "chunks", ($chunkX >> 5) . "." . ($chunkZ >> 5), "{$chunkX}.{$chunkZ}.chunk");
    }
}