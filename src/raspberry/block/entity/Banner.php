<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\nbt\tag\CompoundTag;
use raspberry\nbt\tag\ListTag;
use raspberry\nbt\tag\Tag;

use raspberry\player\Player;

use function count;

class Banner extends BlockEntity{

    public const MAX_PATTERNS = 16;

    private int $base = 0;

    /** 1 for the ominous banner */
    private int $type = 0;

    /** @var list<array{string, int}> */
    private array $patterns = [];

    public function getSaveId() : string{
        return "Banner";
    }

    public function readData(CompoundTag $nbt) : void{
        $this->base = ($nbt->getInt("Base", 0) ?? 0) & 15;
        $this->type = $nbt->getInt("Type", 0) ?? 0;
        $this->patterns = self::readPatterns($nbt);
    }

    public function writeData(CompoundTag $nbt) : void{
        $nbt->setInt("Base", $this->base);
        $nbt->setInt("Type", $this->type);
        $nbt->setTag("Patterns", $this->writePatterns());
    }

    public function onPlace(Item $item, Player $player, int $face) : void{
        $this->base = $item->getMeta() & 15;
        $this->type = $item->getExtraTags()->getInt("Type", 0) ?? 0;
        $this->patterns = self::readPatterns($item->getExtraTags());
    }

    public function getDrops(array $drops) : array{
        foreach($drops as $i => $drop){
            if($drop->getId() === ItemIds::BANNER){
                $banner = new Item(ItemIds::BANNER, $drop->getCount(), $this->base);

                if($this->patterns !== []){
                    $banner->getExtraTags()->setTag("Patterns", $this->writePatterns());
                }

                if($this->type !== 0){
                    $banner->getExtraTags()->setInt("Type", $this->type);
                }

                $drops[$i] = $banner;
            }
        }

        return $drops;
    }

    /**
     * @return list<array{string, int}>
     */
    private static function readPatterns(CompoundTag $nbt) : array{
        $patterns = [];

        foreach($nbt->getListTag("Patterns")?->getValue() ?? [] as $entry){
            if($entry instanceof CompoundTag && count($patterns) < self::MAX_PATTERNS){
                $patterns[] = [$entry->getString("Pattern", "") ?? "", ($entry->getInt("Color", 0) ?? 0) & 15];
            }
        }

        return $patterns;
    }

    private function writePatterns() : ListTag{
        $list = new ListTag([], Tag::TAG_COMPOUND);

        foreach($this->patterns as [$pattern, $color]){
            $list->push((new CompoundTag())->setString("Pattern", $pattern)->setInt("Color", $color));
        }

        return $list;
    }
}