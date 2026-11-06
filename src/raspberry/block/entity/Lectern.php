<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\item\Item;
use raspberry\item\ItemSerializer;

use raspberry\nbt\NbtException;
use raspberry\nbt\tag\CompoundTag;

use raspberry\world\World;

use function count;
use function floor;
use function max;
use function min;
use function preg_match;

class Lectern extends BlockEntity{

    private ?Item $book = null;

    private int $page = 0;

    public function getSaveId() : string{
        return "Lectern";
    }

    public static function isBook(Item $item) : bool{
        return preg_match('/^minecraft:(writable|written)_book$/D', $item->getId()) === 1;
    }

    public function getBook() : ?Item{
        return $this->book;
    }

    public function setBook(?Item $book) : void{
        $this->book = $book === null ? null : (clone $book)->setCount(1);
        $this->page = 0;
        $this->setChanged();
    }

    public function getPage() : int{
        return $this->page;
    }

    public function setPage(int $page) : void{
        $this->page = max(0, min($page, $this->getTotalPages() - 1));
        $this->setChanged();
    }

    public function getTotalPages() : int{
        return max(1, count($this->book?->getExtraTags()->getListTag("pages")?->getValue() ?? []));
    }

    /**
     * What comparators read: how far into the book it is open.
     */
    public function getSignal() : int{
        if($this->book === null){
            return 0;
        }

        $total = $this->getTotalPages();

        return (int) floor(($total > 1 ? $this->page / ($total - 1) : 1.0) * 14) + 1;
    }

    public function readData(CompoundTag $nbt) : void{
        $tag = $nbt->getCompoundTag("book");

        try{
            $book = $tag === null ? null : ItemSerializer::deserialize($tag);
        }catch(NbtException){
            $book = null;
        }

        $this->book = $book !== null && self::isBook($book) ? $book : null;
        $this->page = max(0, min($nbt->getInt("page", 0) ?? 0, $this->getTotalPages() - 1));
    }

    public function writeData(CompoundTag $nbt) : void{
        $nbt->setByte("hasBook", $this->book === null ? 0 : 1);

        if($this->book !== null){
            $nbt->setTag("book", ItemSerializer::serialize($this->book));
            $nbt->setInt("page", $this->page);
            $nbt->setInt("totalPages", $this->getTotalPages());
        }
    }

    public function onBreak(World $world) : array{
        $book = $this->book;
        $this->book = null;
        return $book === null ? [] : [$book];
    }
}