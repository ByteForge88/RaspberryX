<?php

declare(strict_types=1);

namespace raspberry\world\generator;

use InvalidArgumentException;
use raspberry\block\BlockStateRegistry;
use function implode;
use function strtolower;
use function trim;

class GeneratorFactory{

    public const FLAT = "flat";
    public const OLD = "old";
    public const PRE_NORMAL = "pre-normal";
    public const VOID = "void";
    public const DEFAULT = self::PRE_NORMAL;

    /** @var list<string> */
    public const NAMES = [self::FLAT, self::OLD, self::PRE_NORMAL, self::VOID];

    public function __construct(
        private readonly BlockStateRegistry $blockStateRegistry
    ){}

    /**
     * @param string $settings
     */
    public function create(string $name, string $settings = "", int $seed = 0) : Generator{
        return match(strtolower(trim($name))){
            self::FLAT => FlatGenerator::fromPreset($settings, $this->blockStateRegistry),
            self::OLD => new OldGenerator($seed, $this->blockStateRegistry),
            self::PRE_NORMAL => new PreNormalGenerator($seed, $this->blockStateRegistry),
            self::VOID => new VoidGenerator($this->blockStateRegistry),
            default => throw new InvalidArgumentException("Unknown world generator '{$name}', available: " . implode(", ", self::NAMES))
        };
    }
}