<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\convert;

use raspberry\block\BlockStateRegistry;

use raspberry\inventory\CreativeInventory;

use raspberry\item\Item;
use raspberry\item\ItemIds;
use raspberry\item\ItemSerializer;

use raspberry\network\mcpe\protocol\CreativeContentPacket;
use raspberry\network\mcpe\protocol\types\ItemStack;
use raspberry\network\mcpe\serializer\ItemStackSerializer;

use function count;

class ItemTranslator{

    private ItemTypeDictionary $dictionary;

    private BlockStateRegistry $blocks;

    private BlockTranslator $blockTranslator;

    /** @var array<string, int> */
    private array $blockIds = [];

    public function __construct(ItemTypeDictionary $dictionary, BlockStateRegistry $blocks, BlockTranslator $blockTranslator){
        $this->dictionary = $dictionary;
        $this->blocks = $blocks;
        $this->blockTranslator = $blockTranslator;

        ItemStackSerializer::setShieldRuntimeId($dictionary->getRuntimeId(ItemIds::SHIELD) ?? -1);
    }

    public function toNetwork(Item $item) : ItemStack{
        if($item->isNull()){
            return ItemStack::empty();
        }

        $runtimeId = $this->dictionary->getRuntimeId($item->getId());

        if($runtimeId === null){
            return ItemStack::empty();
        }

        return new ItemStack($runtimeId, $item->getCount(), $item->getMeta(), $this->getBlockNetworkId($item->getId()), ItemSerializer::serializeTag($item));
    }

    /**
     * @param list<Item>|null $items
     */
    public function buildCreativeContent(CreativeInventory $creative, ?array &$items = null) : CreativeContentPacket{
        $items = [];
        $packet = new CreativeContentPacket();
        $groups = [];

        foreach($creative->getEntries() as [$category, $item]){
            $stack = $this->toNetwork($item);

            if($stack->isEmpty()){
                continue;
            }

            if(!isset($groups[$category])){
                $groups[$category] = count($packet->groups);
                $packet->groups[] = [$category, "", ItemStack::empty()];
            }

            $packet->items[] = [$stack, $groups[$category]];
            $items[] = $item;
        }

        return $packet;
    }

    private function getBlockNetworkId(string $id) : int{
        if(!isset($this->blockIds[$id])){
            $this->blockIds[$id] = $this->blocks->isRegistered($id) ? $this->blockTranslator->getNetworkId($this->blocks->getDefaultState($id)) : 0;
        }

        return $this->blockIds[$id];
    }
}