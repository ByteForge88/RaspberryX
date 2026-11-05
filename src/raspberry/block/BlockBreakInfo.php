<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\item\Item;
use raspberry\item\ToolInfo;
use raspberry\item\enchantment\Enchantment;

use RuntimeException;
use UnexpectedValueException;

use function ceil;
use function dirname;
use function file_get_contents;
use function is_array;
use function json_decode;
use function mt_getrandmax;
use function mt_rand;

use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;

class BlockBreakInfo{

    public const TIER_LEVELS = ["wooden" => 1, "stone" => 2, "iron" => 3, "diamond" => 4, "netherite" => 5];

    /** @var array<string, self>|null */
    private static ?array $blocks = null;

    /** -1: cannot be broken in survival, 0: breaks at once */
    private float $hardness;

    private ?string $tool;

    private ?string $tier;

    /** @var list<array{item: string, min: int, max: int, chance?: float, states?: array<string, string|int|bool>}> */
    private array $drops;

    /**
     * @param list<array{item: string, min: int, max: int, chance?: float, states?: array<string, string|int|bool>}> $drops
     */
    public function __construct(float $hardness, ?string $tool, ?string $tier, array $drops){
        $this->hardness = $hardness;
        $this->tool = $tool;
        $this->tier = $tier;
        $this->drops = $drops;
    }

    public static function get(string $name) : self{
        self::$blocks ??= self::load(dirname(__DIR__, 3) . "/resources/vanilla/block_breaking.json");

        return self::$blocks[$name] ?? new self(0.0, null, null, []);
    }

    public function getHardness() : float{
        return $this->hardness;
    }

    public function isBreakable() : bool{
        return $this->hardness >= 0;
    }

    public function breaksInstantly() : bool{
        return $this->hardness == 0;
    }

    public function getTool() : ?string{
        return $this->tool;
    }

    public function getTier() : ?string{
        return $this->tier;
    }

    /**
     * Whether a player holding $item gets the drops.
     */
    public function canHarvest(Item $item) : bool{
        if($this->tier === null){
            return true;
        }

        return ToolInfo::getType($item->getId()) === $this->tool && ToolInfo::getTierLevel($item->getId()) >= self::TIER_LEVELS[$this->tier];
    }

    /**
     * How many ticks breaking takes in survival with $item, standing on the ground out of water.
     */
    public function getBreakTicks(Item $item, string $blockName) : int{
        if($this->hardness < 0){
            return PHP_INT_MAX;
        }

        if($this->hardness == 0){
            return 0;
        }

        $speed = ToolInfo::getSpeed($item->getId(), $this->tool, $blockName);

        if($speed > 1){
            $efficiency = $item->getEnchantmentLevel(Enchantment::EFFICIENCY);

            if($efficiency > 0){
                $speed += $efficiency * $efficiency + 1;
            }
        }

        $damagePerTick = $speed / $this->hardness / ($this->canHarvest($item) ? 30 : 100);

        return $damagePerTick >= 1 ? 0 : (int) ceil(1 / $damagePerTick);
    }

    /**
     * What breaking $block with $item drops, nothing when the tool cannot harvest it.
     *
     * @return list<Item>
     */
    public function getDrops(BlockState $block, Item $item) : array{
        if(!$this->canHarvest($item)){
            return [];
        }

        $items = [];

        foreach($this->drops as $entry){
            foreach($entry["states"] ?? [] as $property => $value){
                if(!$block->hasProperty($property) || $block->getProperty($property) !== $value){
                    continue 2;
                }
            }

            if(isset($entry["chance"]) && mt_rand() / mt_getrandmax() >= $entry["chance"]){
                continue;
            }

            $count = mt_rand($entry["min"], $entry["max"]);

            if($count > 0){
                $items[] = new Item($entry["item"], $count);
            }
        }

        return $items;
    }

    /**
     * @return array<string, self>
     */
    private static function load(string $path) : array{
        $contents = @file_get_contents($path);

        if($contents === false){
            throw new RuntimeException("Cannot read block breaking data: {$path}");
        }

        $data = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);

        if(!is_array($data) || !is_array($data["blocks"] ?? null)){
            throw new UnexpectedValueException("Block breaking data has an invalid layout: {$path}");
        }

        $blocks = [];

        foreach($data["blocks"] as $name => $entry){
            $blocks[$name] = new self((float) $entry["hardness"], $entry["tool"], $entry["tier"], $entry["drops"]);
        }

        return $blocks;
    }
}