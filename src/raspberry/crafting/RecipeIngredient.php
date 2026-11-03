<?php

declare(strict_types=1);

namespace raspberry\crafting;

use raspberry\item\Item;

use function array_keys;

class RecipeIngredient{

    public const ANY_META = -1;

    private ?string $itemId;

    private int $meta;

    private ?string $tag;

    /** @var array<string, true> */
    private array $tagItems;

    private int $count;

    /**
     * @param list<string> $tagItems
     */
    public function __construct(?string $itemId, int $meta, ?string $tag, array $tagItems, int $count){
        $this->itemId = $itemId;
        $this->meta = $meta;
        $this->tag = $tag;
        $this->tagItems = [];
        $this->count = $count;

        foreach($tagItems as $id){
            $this->tagItems[$id] = true;
        }
    }

    public function getItemId() : ?string{
        return $this->itemId;
    }

    public function getMeta() : int{
        return $this->meta;
    }

    public function getTag() : ?string{
        return $this->tag;
    }

    /**
     * @return list<string>
     */
    public function getItemIds() : array{
        return $this->itemId !== null ? [$this->itemId] : array_keys($this->tagItems);
    }

    public function getCount() : int{
        return $this->count;
    }

    public function matches(Item $item) : bool{
        if($item->isNull()){
            return false;
        }

        if($this->tag !== null){
            return isset($this->tagItems[$item->getId()]);
        }

        return $item->getId() === $this->itemId && ($this->meta === self::ANY_META || $item->getMeta() === $this->meta);
    }
}