<?php

declare(strict_types=1);

namespace raspberry\nbt\tag;

use raspberry\nbt\NbtException;

use function count;

class ListTag extends Tag{

    private int $tagType;

    /** @var list<Tag> */
    private array $tags = [];

    /**
     * @param list<Tag> $tags
     */
    public function __construct(array $tags = [], int $tagType = self::TAG_END){
        $this->tagType = $tagType;

        foreach($tags as $tag){
            $this->push($tag);
        }
    }

    public function getType() : int{
        return self::TAG_LIST;
    }

    /**
     * The type of every tag in the list.
     */
    public function getTagType() : int{
        return $this->tagType;
    }

    public function push(Tag $tag) : void{
        if($this->tagType === self::TAG_END){
            $this->tagType = $tag->getType();
        }elseif($tag->getType() !== $this->tagType){
            throw new NbtException("Cannot add a " . Tag::getTypeName($tag->getType()) . " tag to a list of " . Tag::getTypeName($this->tagType) . " tags");
        }

        $this->tags[] = $tag;
    }

    public function get(int $index) : ?Tag{
        return $this->tags[$index] ?? null;
    }

    public function count() : int{
        return count($this->tags);
    }

    public function getValue() : array{
        return $this->tags;
    }

    public function getAllValues() : array{
        $values = [];

        foreach($this->tags as $tag){
            $values[] = $tag->getValue();
        }

        return $values;
    }
}