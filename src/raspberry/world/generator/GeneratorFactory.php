<?php

declare(strict_types=1);

namespace raspberry\world\generator;

use InvalidArgumentException;
use raspberry\block\BlockStateRegistry;
use function implode;
use function strtolower;
use function trim;

class GeneratorFactory{

    public const OLD = "old";
    public const NORMAL = "normal";
    public const DEFAULT = self::NORMAL;
    public const FLAT = "flat";

    /** @var list<string> */
    public const NAMES = [self::FLAT];

    public function __construct(
        private readonly BlockStateRegistry $blockStateRegistry
    ){}

    /**
     * @param string $settings generator-specific options; for "flat" the layer preset
     */
    public function create(string $name, string $settings = "") : Generator{
        return match(strtolower(trim($name))){
            self::FLAT => FlatGenerator::fromPreset($settings, $this->blockStateRegistry),
            default => throw new InvalidArgumentException("Unknown world generator '{$name}', available: " . implode(", ", self::NAMES))
        };
    }
}