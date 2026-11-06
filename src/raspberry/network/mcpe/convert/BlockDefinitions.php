<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\convert;

use raspberry\nbt\NbtSerializer;

use RuntimeException;
use UnexpectedValueException;

use function base64_decode;
use function dirname;
use function file_get_contents;
use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class BlockDefinitions{

    /** @var list<array{string, string}>|null */
    private static ?array $definitions = null;

    private function __construct(){
    }

    /**
     * @return list<array{string, string}>
     */
    public static function get() : array{
        return self::$definitions ??= self::load(dirname(__DIR__, 5) . "/resources/vanilla/block_components.json");
    }

    /**
     * @return list<array{string, string}>
     */
    private static function load(string $path) : array{
        $contents = @file_get_contents($path);

        if($contents === false){
            throw new RuntimeException("Cannot read block components: {$path}");
        }

        $data = json_decode($contents, true, 3, JSON_THROW_ON_ERROR);

        if(!is_array($data) || !is_array($data["blocks"] ?? null)){
            throw new UnexpectedValueException("Block components have an invalid layout: {$path}");
        }

        $definitions = [];

        foreach($data["blocks"] as $name => $components){
            $nbt = is_string($name) && is_string($components) ? base64_decode($components, true) : false;

            if($nbt === false){
                throw new UnexpectedValueException("Invalid block components entry in {$path}");
            }

            $definitions[] = [$name, NbtSerializer::network()->write(NbtSerializer::littleEndian()->read($nbt))];
        }

        return $definitions;
    }
}