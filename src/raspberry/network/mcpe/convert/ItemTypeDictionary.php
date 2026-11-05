<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\convert;

use raspberry\nbt\NbtSerializer;

use raspberry\network\mcpe\protocol\ItemRegistryPacket;
use raspberry\network\mcpe\serializer\NetworkNbtWriter;

use RuntimeException;
use UnexpectedValueException;

use function base64_decode;
use function dirname;
use function file_get_contents;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;

class ItemTypeDictionary{

    private string $version;

    /** @var array<string, int> item name => runtime ID */
    private array $items = [];

    /** @var array<string, array{bool, int, string}> item name => component based, version, components as network NBT */
    private array $components = [];

    public function __construct(?string $path = null, ?string $componentsPath = null){
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
        $this->loadComponents($componentsPath ?? dirname($path) . "/item_components.json");
    }

    public function getVersion() : string{
        return $this->version;
    }

    /**
     * @return array<string, int>
     */
    public function getEntries() : array{
        return $this->items;
    }

    public function getRuntimeId(string $name) : ?int{
        return $this->items[$name] ?? null;
    }

    /**
     * @return list<array{string, int, bool, int, string}>
     */
    public function getRegistryEntries() : array{
        $entries = [];
        $empty = NetworkNbtWriter::emptyCompound();

        foreach($this->items as $name => $runtimeId){
            [$componentBased, $version, $components] = $this->components[$name] ?? [false, ItemRegistryPacket::VERSION_NONE, $empty];
            $entries[] = [$name, $runtimeId, $componentBased, $version, $components];
        }

        return $entries;
    }

    private function loadComponents(string $path) : void{
        $contents = @file_get_contents($path);

        if($contents === false){
            throw new RuntimeException("Cannot read item components: {$path}");
        }

        $data = json_decode($contents, true, 4, JSON_THROW_ON_ERROR);

        if(!is_array($data) || !is_array($data["items"] ?? null)){
            throw new UnexpectedValueException("Item components have an invalid layout: {$path}");
        }

        $empty = NetworkNbtWriter::emptyCompound();

        foreach($data["items"] as $name => $entry){
            if(!is_string($name) || !is_array($entry) || !is_int($entry["version"] ?? null) || !is_bool($entry["component_based"] ?? null)){
                throw new UnexpectedValueException("Invalid item components entry in {$path}");
            }

            $components = $empty;

            if(isset($entry["components"])){
                $nbt = is_string($entry["components"]) ? base64_decode($entry["components"], true) : false;

                if($nbt === false){
                    throw new UnexpectedValueException("Invalid components for {$name} in {$path}");
                }

                $components = NbtSerializer::network()->write(NbtSerializer::littleEndian()->read($nbt));
            }

            $this->components[$name] = [$entry["component_based"], $entry["version"], $components];
        }
    }
}