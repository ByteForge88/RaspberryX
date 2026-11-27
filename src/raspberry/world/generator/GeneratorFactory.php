<?php

declare(strict_types=1);

namespace raspberry\world\generator;

use raspberry\block\BlockStateRegistry;

use raspberry\world\Dimension;

use InvalidArgumentException;

use function implode;
use function strtolower;
use function trim;

class GeneratorFactory{

    public const string FLAT = "flat";
    public const string OLD = "old";
    public const string PRE_NORMAL = "pre-normal";
    public const string VOID = "void";
    public const string NETHER = "nether";
    public const string THE_END = "the_end";
    public const string DEFAULT = self::PRE_NORMAL;

    /** @var list<string> */
    public const array NAMES = [self::FLAT, self::OLD, self::PRE_NORMAL, self::VOID];

    public function __construct(
        private readonly BlockStateRegistry $blockStateRegistry
    ){}

    /**
     * @param string $name
     * @param string $settings
     * @param int $seed
     * @return Generator
     */
    public function create(string $name, string $settings = "", int $seed = 0) : Generator{
        return match(strtolower(trim($name))){
            self::FLAT => FlatGenerator::fromPreset($settings, $this->blockStateRegistry),
            self::OLD => new OldGenerator($seed, $this->blockStateRegistry),
            self::PRE_NORMAL => new PreNormalGenerator($seed, $this->blockStateRegistry),
            self::VOID => new VoidGenerator($this->blockStateRegistry),
            self::NETHER => new NetherGenerator($this->blockStateRegistry),
            self::THE_END => new EndGenerator($seed, $this->blockStateRegistry),
            default => throw new InvalidArgumentException("Unknown world generator '$name', available: " . implode(", ", self::NAMES))
        };
    }

    public static function forDimension(Dimension $dimension) : string{
        return match($dimension){
            Dimension::OVERWORLD => self::DEFAULT,
            Dimension::NETHER => self::NETHER,
            Dimension::THE_END => self::THE_END
        };
    }
}