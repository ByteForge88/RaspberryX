<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\convert;

use RuntimeException;
use UnexpectedValueException;

use function dirname;
use function file_get_contents;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;

/**
 * Every vanilla item and its network runtime ID, from resources/vanilla/item_registry.json
 * (tools/generate-block-data.py). Sent to the client in ItemRegistryPacket.
 */
class ItemTypeDictionary{

    private string $version;

    /** @var array<string, int> item name => runtime ID */
    private array $items = [];

    public function __construct(?string $path = null){
        $path ??= dirname(__DIR__, 5) . "/resources/vanilla/item_registry.json";

        $contents = @file_get_contents($path);

        if($contents === false){
            throw new RuntimeException("Cannot read item data: {$path}");
        }

        $data = json_decode($contents, true, 4, JSON_THROW_ON_ERROR);

        if(!is_array($data) || !is_string($data["version"] ?? null) || !is_array($data["items"] ?? null)){
            throw new UnexpectedValueException("Item data has an invalid layout: {$path}");
        }

        foreach($data["items"] as $name => $runtimeId){
            if(!is_string($name) || !is_int($runtimeId)){
                throw new UnexpectedValueException("Invalid item entry in {$path}");
            }

            $this->items[$name] = $runtimeId;
        }

        $this->version = $data["version"];
    }

    public function getVersion() : string{
        return $this->version;
    }

    /**
     * @return array<string, int> item name => runtime ID
     */
    public function getEntries() : array{
        return $this->items;
    }

    public function getRuntimeId(string $name) : ?int{
        return $this->items[$name] ?? null;
    }
}