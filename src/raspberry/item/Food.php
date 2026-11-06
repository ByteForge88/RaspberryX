<?php

declare(strict_types=1);

namespace raspberry\item;

use raspberry\entity\effect\Effect;
use raspberry\entity\effect\EffectInstance;

use raspberry\nbt\NbtSerializer;
use raspberry\nbt\tag\CompoundTag;
use raspberry\nbt\tag\ListTag;
use raspberry\nbt\tag\StringTag;
use raspberry\nbt\tag\Tag;

use function base64_decode;
use function dirname;
use function file_get_contents;
use function is_array;
use function is_string;
use function json_decode;
use function max;
use function str_contains;

use const JSON_THROW_ON_ERROR;

class Food{

    /** use_action of chorus fruit and suspicious stew */
    public const ACTION_CHORUS_TELEPORT = 0;
    public const ACTION_SUSPICIOUS_STEW = 1;

    private const DEFAULT_USE_TICKS = 32;

    /** @var array<string, self>|null */
    private static ?array $foods = null;

    private int $nutrition;

    private float $saturation;

    private bool $alwaysEdible;

    private int $useTicks;

    /** @var list<array{EffectInstance, float}> */
    private array $effects;

    /** @var list<Effect> */
    private array $removedEffects;

    private ?string $convertsTo;

    private int $useAction;

    /**
     * @param list<array{EffectInstance, float}> $effects
     * @param list<Effect> $removedEffects
     */
    public function __construct(int $nutrition, float $saturation, bool $alwaysEdible, int $useTicks, array $effects, array $removedEffects, ?string $convertsTo, int $useAction){
        $this->nutrition = $nutrition;
        $this->saturation = $saturation;
        $this->alwaysEdible = $alwaysEdible;
        $this->useTicks = $useTicks;
        $this->effects = $effects;
        $this->removedEffects = $removedEffects;
        $this->convertsTo = $convertsTo;
        $this->useAction = $useAction;
    }

    public static function get(string $itemId) : ?self{
        if(self::$foods === null){
            self::load(dirname(__DIR__, 3) . "/resources/vanilla/item_components.json");
        }

        return self::$foods[$itemId] ?? null;
    }

    public function getNutrition() : int{
        return $this->nutrition;
    }

    public function getSaturation() : float{
        return $this->saturation;
    }

    /**
     * Golden apples, honey and chorus fruit can be eaten with a full food bar.
     */
    public function isAlwaysEdible() : bool{
        return $this->alwaysEdible;
    }

    public function getUseTicks() : int{
        return $this->useTicks;
    }

    /**
     * @return list<array{EffectInstance, float}>
     */
    public function getEffects() : array{
        return $this->effects;
    }

    /**
     * @return list<Effect>
     */
    public function getRemovedEffects() : array{
        return $this->removedEffects;
    }

    /**
     * The item left in the hand, like the bowl of a stew.
     */
    public function getConvertsTo() : ?string{
        return $this->convertsTo;
    }

    public function getUseAction() : int{
        return $this->useAction;
    }

    private static function load(string $path) : void{
        self::$foods = [];
        $contents = @file_get_contents($path);
        $data = $contents === false ? null : json_decode($contents, true, 4, JSON_THROW_ON_ERROR);

        if(!is_array($data) || !is_array($data["items"] ?? null)){
            return;
        }

        foreach($data["items"] as $id => $entry){
            $nbt = is_string($entry["components"] ?? null) ? base64_decode($entry["components"], true) : false;

            if($nbt === false || !str_contains($nbt, "minecraft:food")){
                continue;
            }

            $components = NbtSerializer::littleEndian()->read($nbt)->getCompoundTag("components");
            $food = $components?->getCompoundTag("minecraft:food");

            if($components !== null && $food !== null){
                self::$foods[$id] = self::fromComponents($components, $food);
            }
        }
    }

    private static function fromComponents(CompoundTag $components, CompoundTag $food) : self{
        $nutrition = (int) ($food->getTag("nutrition")?->getValue() ?? 0);
        $modifier = (float) ($food->getTag("saturation_modifier")?->getValue() ?? 0.0);

        $useTicks = $components->getCompoundTag("minecraft:use_modifiers")?->getTag("use_duration")?->getValue();
        $useTicks = $useTicks !== null ? (int) ($useTicks * 20 + 0.5) : (int) ($components->getTag("minecraft:use_duration")?->getValue() ?? self::DEFAULT_USE_TICKS);

        $effects = [];

        foreach(self::listOf($food, "effects") as $tag){
            $effect = $tag instanceof CompoundTag ? Effect::tryFrom((int) ($tag->getTag("id")?->getValue() ?? 0)) : null;

            if($effect !== null){
                $effects[] = [new EffectInstance($effect, (int) ($tag->getTag("duration")?->getValue() ?? 0) * 20, (int) ($tag->getTag("amplifier")?->getValue() ?? 0)), (float) ($tag->getTag("chance")?->getValue() ?? 1.0)];
            }
        }

        $removed = [];

        foreach(self::listOf($food, "remove_effects") as $tag){
            $effect = Effect::tryFrom((int) $tag->getValue());

            if($effect !== null){
                $removed[] = $effect;
            }
        }

        $convertsTo = $food->getTag("using_converts_to");
        $convertsTo = $convertsTo instanceof StringTag && $convertsTo->getValue() !== "" ? "minecraft:" . $convertsTo->getValue() : null;

        return new self($nutrition, $nutrition * $modifier * 2, ($food->getTag("can_always_eat")?->getValue() ?? 0) == 1, max(1, $useTicks), $effects, $removed, $convertsTo, (int) ($food->getTag("on_use_action")?->getValue() ?? -1));
    }

    /**
     * @return list<Tag>
     */
    private static function listOf(CompoundTag $tag, string $name) : array{
        $list = $tag->getTag($name);

        return $list instanceof ListTag ? $list->getValue() : [];
    }
}