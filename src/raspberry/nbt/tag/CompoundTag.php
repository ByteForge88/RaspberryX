<?php

declare(strict_types=1);

namespace raspberry\nbt\tag;

use raspberry\nbt\NbtException;

class CompoundTag extends Tag{

    /** @var array<string, Tag> */
    private array $tags = [];

    public function getType() : int{
        return self::TAG_COMPOUND;
    }

    /**
     * @return array<string, Tag>
     */
    public function getValue() : array{
        return $this->tags;
    }

    public function setTag(string $name, Tag $tag) : self{
        $this->tags[$name] = $tag;
        return $this;
    }

    public function getTag(string $name) : ?Tag{
        return $this->tags[$name] ?? null;
    }

    public function hasTag(string $name) : bool{
        return isset($this->tags[$name]);
    }

    public function removeTag(string $name) : void{
        unset($this->tags[$name]);
    }

    public function setByte(string $name, int $value) : self{
        return $this->setTag($name, new ByteTag($value));
    }

    public function setShort(string $name, int $value) : self{
        return $this->setTag($name, new ShortTag($value));
    }

    public function setInt(string $name, int $value) : self{
        return $this->setTag($name, new IntTag($value));
    }

    public function setLong(string $name, int $value) : self{
        return $this->setTag($name, new LongTag($value));
    }

    public function setFloat(string $name, float $value) : self{
        return $this->setTag($name, new FloatTag($value));
    }

    public function setDouble(string $name, float $value) : self{
        return $this->setTag($name, new DoubleTag($value));
    }

    public function setString(string $name, string $value) : self{
        return $this->setTag($name, new StringTag($value));
    }

    public function getByte(string $name, ?int $default = null) : ?int{
        return $this->getTyped($name, ByteTag::class)?->getValue() ?? $default;
    }

    public function getShort(string $name, ?int $default = null) : ?int{
        return $this->getTyped($name, ShortTag::class)?->getValue() ?? $default;
    }

    public function getInt(string $name, ?int $default = null) : ?int{
        return $this->getTyped($name, IntTag::class)?->getValue() ?? $default;
    }

    public function getLong(string $name, ?int $default = null) : ?int{
        return $this->getTyped($name, LongTag::class)?->getValue() ?? $default;
    }

    public function getFloat(string $name, ?float $default = null) : ?float{
        return $this->getTyped($name, FloatTag::class)?->getValue() ?? $default;
    }

    public function getDouble(string $name, ?float $default = null) : ?float{
        return $this->getTyped($name, DoubleTag::class)?->getValue() ?? $default;
    }

    public function getString(string $name, ?string $default = null) : ?string{
        return $this->getTyped($name, StringTag::class)?->getValue() ?? $default;
    }

    public function getListTag(string $name) : ?ListTag{
        return $this->getTyped($name, ListTag::class);
    }

    public function getCompoundTag(string $name) : ?CompoundTag{
        return $this->getTyped($name, CompoundTag::class);
    }

    /**
     * @template T of Tag
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function getTyped(string $name, string $class) : ?Tag{
        $tag = $this->tags[$name] ?? null;

        if($tag !== null && !$tag instanceof $class){
            throw new NbtException("Tag \"{$name}\" is a " . Tag::getTypeName($tag->getType()) . " tag, not the expected type");
        }

        return $tag;
    }
}