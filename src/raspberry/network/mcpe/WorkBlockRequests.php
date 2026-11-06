<?php

declare(strict_types=1);

namespace raspberry\network\mcpe;

use raspberry\Server;

use raspberry\block\BlockIds;
use raspberry\block\entity\Beacon;

use raspberry\crafting\Loom;
use raspberry\crafting\Anvil;
use raspberry\crafting\Grindstone;
use raspberry\crafting\CraftingRecipe;
use raspberry\crafting\EnchantingTable;

use raspberry\item\Item;
use raspberry\item\ItemIds;
use raspberry\item\enchantment\Enchantment;

use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\PlayerEnchantOptionsPacket;
use raspberry\network\mcpe\protocol\types\LevelEvent;
use raspberry\network\mcpe\protocol\types\inventory\ContainerIds;
use raspberry\network\mcpe\protocol\types\inventory\StackRequestAction;

use raspberry\player\GameMode;
use raspberry\player\Player;

use UnexpectedValueException;

use function mt_rand;
use function mt_getrandmax;

class WorkBlockRequests{

    /** container ID => [screen it belongs to, UI slot] */
    private const SLOTS = [
        ContainerIds::ANVIL_INPUT => [ContainerWindow::TYPE_ANVIL, Player::UI_ANVIL_INPUT],
        ContainerIds::ANVIL_MATERIAL => [ContainerWindow::TYPE_ANVIL, Player::UI_ANVIL_MATERIAL],
        ContainerIds::STONECUTTER_INPUT => [ContainerWindow::TYPE_STONECUTTER, Player::UI_STONECUTTER_INPUT],
        ContainerIds::LOOM_INPUT => [ContainerWindow::TYPE_LOOM, Player::UI_LOOM_INPUT],
        ContainerIds::LOOM_DYE => [ContainerWindow::TYPE_LOOM, Player::UI_LOOM_DYE],
        ContainerIds::LOOM_MATERIAL => [ContainerWindow::TYPE_LOOM, Player::UI_LOOM_PATTERN],
        ContainerIds::CARTOGRAPHY_INPUT => [ContainerWindow::TYPE_CARTOGRAPHY, Player::UI_CARTOGRAPHY_INPUT],
        ContainerIds::CARTOGRAPHY_ADDITIONAL => [ContainerWindow::TYPE_CARTOGRAPHY, Player::UI_CARTOGRAPHY_ADDITIONAL],
        ContainerIds::ENCHANTING_INPUT => [ContainerWindow::TYPE_ENCHANTMENT, Player::UI_ENCHANTING_INPUT],
        ContainerIds::ENCHANTING_MATERIAL => [ContainerWindow::TYPE_ENCHANTMENT, Player::UI_ENCHANTING_LAPIS],
        ContainerIds::GRINDSTONE_INPUT => [ContainerWindow::TYPE_GRINDSTONE, Player::UI_GRINDSTONE_INPUT],
        ContainerIds::GRINDSTONE_ADDITIONAL => [ContainerWindow::TYPE_GRINDSTONE, Player::UI_GRINDSTONE_ADDITIONAL],
        ContainerIds::SMITHING_TABLE_INPUT => [ContainerWindow::TYPE_SMITHING_TABLE, Player::UI_SMITHING_INPUT],
        ContainerIds::SMITHING_TABLE_MATERIAL => [ContainerWindow::TYPE_SMITHING_TABLE, Player::UI_SMITHING_MATERIAL],
        ContainerIds::SMITHING_TABLE_TEMPLATE => [ContainerWindow::TYPE_SMITHING_TABLE, Player::UI_SMITHING_TEMPLATE],
        ContainerIds::BEACON_PAYMENT => [ContainerWindow::TYPE_BEACON, Player::UI_BEACON_PAYMENT]
    ];

    private ItemStackRequestHandler $handler;

    private NetworkSession $session;

    private Player $player;

    private int $pendingLevels = 0;

    private int $pendingXp = 0;

    private bool $enchanted = false;

    private bool $anvilUsed = false;

    /** @var array{Beacon, int, int}|null */
    private ?array $beaconPayment = null;

    /** what the last enchanting offers were for: window ID and the item */
    private int $offersWindow = -1;
    private ?Item $offersFor = null;

    public function __construct(ItemStackRequestHandler $handler, NetworkSession $session, Player $player){
        $this->handler = $handler;
        $this->session = $session;
        $this->player = $player;
    }

    /**
     * The UI slot behind a work block slot, null when that screen is not open or the slot is wrong.
     */
    public function resolveSlot(int $containerId, int $slot) : ?int{
        [$type, $uiSlot] = self::SLOTS[$containerId] ?? [null, null];

        return $type !== null && $slot === $uiSlot && $this->getWindowType() === $type ? $uiSlot : null;
    }

    /**
     * The block a crafting recipe has to belong to with the open screen.
     */
    public function getRecipeBlock() : string{
        return match($this->getWindowType()){
            ContainerWindow::TYPE_STONECUTTER => "stonecutter",
            ContainerWindow::TYPE_CARTOGRAPHY => "cartography_table",
            default => CraftingRecipe::BLOCK_CRAFTING_TABLE
        };
    }

    /**
     * The UI slots a stonecutter or cartography recipe takes its ingredients from.
     *
     * @return list<array{int, int}> container ID, UI slot
     */
    public function getRecipeSlots() : array{
        return match($this->getWindowType()){
            ContainerWindow::TYPE_STONECUTTER => [[ContainerIds::STONECUTTER_INPUT, Player::UI_STONECUTTER_INPUT]],
            ContainerWindow::TYPE_CARTOGRAPHY => [
                [ContainerIds::CARTOGRAPHY_INPUT, Player::UI_CARTOGRAPHY_INPUT],
                [ContainerIds::CARTOGRAPHY_ADDITIONAL, Player::UI_CARTOGRAPHY_ADDITIONAL]
            ],
            default => []
        };
    }

    /**
     * Handles a craft action meant for the open work block. False when it is a normal crafting action.
     *
     * @param list<string> $filterStrings
     *
     * @throws UnexpectedValueException
     */
    public function handleAction(StackRequestAction $action, array $filterStrings) : bool{
        $type = $this->getWindowType();

        switch($action->type){
            case StackRequestAction::CRAFT_RECIPE:
                if($type === ContainerWindow::TYPE_ENCHANTMENT){
                    $this->enchant($action->recipeNetworkId);
                    return true;
                }

                if($type === ContainerWindow::TYPE_SMITHING_TABLE){
                    $this->smith($action->recipeNetworkId);
                    return true;
                }

                return false;

            case StackRequestAction::CRAFT_RECIPE_OPTIONAL:
                $this->requireWindow(ContainerWindow::TYPE_ANVIL);
                $this->useAnvil($action->filterStringIndex, $filterStrings);
                return true;

            case StackRequestAction::CRAFT_GRINDSTONE:
                $this->requireWindow(ContainerWindow::TYPE_GRINDSTONE);
                $this->grind();
                return true;

            case StackRequestAction::CRAFT_LOOM:
                $this->requireWindow(ContainerWindow::TYPE_LOOM);
                $this->weave($action->pattern, $action->timesCrafted);
                return true;

            case StackRequestAction::BEACON_PAYMENT:
                $this->requireWindow(ContainerWindow::TYPE_BEACON);
                $this->payBeacon($action->primaryEffect, $action->secondaryEffect);
                return true;

            case StackRequestAction::DESTROY:
            case StackRequestAction::CONSUME:
                return $this->beaconPayment !== null && $action->source?->containerId === ContainerIds::BEACON_PAYMENT;

            default:
                return false;
        }
    }

    /**
     * Applies the levels, experience and anvil wear of a request that went through, or forgets
     * them when it was rejected.
     */
    public function onRequestDone(bool $accepted) : void{
        if($accepted){
            if($this->pendingLevels > 0){
                $this->player->takeXpLevels($this->pendingLevels);
            }

            if($this->pendingXp > 0){
                $this->player->addXp($this->pendingXp);
            }

            if($this->enchanted){
                $this->player->resetEnchantmentSeed();
                $this->offersFor = null;
            }

            if($this->anvilUsed){
                $this->wearAnvil();
            }

            if($this->beaconPayment !== null){
                [$beacon, $primary, $secondary] = $this->beaconPayment;
                $beacon->setEffects($this->player->getWorld(), $primary, $secondary);
            }
        }

        $this->pendingLevels = 0;
        $this->pendingXp = 0;
        $this->enchanted = false;
        $this->anvilUsed = false;
        $this->beaconPayment = null;
    }

    /**
     * Sends the enchanting table's offers when the item in it changed.
     */
    public function updateEnchantOffers() : void{
        $window = $this->session->getInventoryManager()?->getWindow();

        if($window === null || $window->getType() !== ContainerWindow::TYPE_ENCHANTMENT){
            return;
        }

        $input = $this->player->getUIInventory()->getItem(Player::UI_ENCHANTING_INPUT);

        if($window->getWindowId() === $this->offersWindow && $this->offersFor !== null && $this->offersFor->equals($input)){
            return;
        }

        $this->offersWindow = $window->getWindowId();
        $this->offersFor = clone $input;
        $seed = $this->player->getEnchantmentSeed();
        $options = [];

        foreach($this->getOffers() as $button => [$level, $enchantments]){
            $ids = [];

            foreach($enchantments as [$enchantment, $enchantLevel]){
                $ids[] = [$enchantment->value, $enchantLevel];
            }

            $options[] = [$level, $ids, EnchantingTable::getButtonText($seed, $button), $button];
        }

        $this->session->sendDataPacket(PlayerEnchantOptionsPacket::create($options));
    }

    private function enchant(int $button) : void{
        $offer = $this->getOffers()[$button] ?? throw new UnexpectedValueException("The enchanting table has no offer {$button}");
        [$level, $enchantments] = $offer;
        $cost = $button + 1;
        $input = $this->getItem(Player::UI_ENCHANTING_INPUT);

        if(!$this->isCreative()){
            $lapis = $this->getItem(Player::UI_ENCHANTING_LAPIS);

            if($this->player->getXpLevel() < $level || $lapis->getId() !== ItemIds::LAPIS_LAZULI || $lapis->getCount() < $cost){
                throw new UnexpectedValueException("Not enough levels or lapis for enchanting offer {$button}");
            }

            $this->setItem(ContainerIds::ENCHANTING_MATERIAL, Player::UI_ENCHANTING_LAPIS, (clone $lapis)->setCount($lapis->getCount() - $cost));
            $this->pendingLevels += $cost;
        }

        $this->setItem(ContainerIds::ENCHANTING_INPUT, Player::UI_ENCHANTING_INPUT, Item::air());
        $this->handler->createResult(EnchantingTable::apply($input, $enchantments));
        $this->enchanted = true;
    }

    /**
     * @param list<string> $filterStrings
     */
    private function useAnvil(int $filterIndex, array $filterStrings) : void{
        $name = $filterStrings[$filterIndex] ?? $filterStrings[0] ?? null;
        $material = $this->getItem(Player::UI_ANVIL_MATERIAL);

        [$result, $cost, $used] = Anvil::combine($this->getItem(Player::UI_ANVIL_INPUT), $material, $name, $this->isCreative()) ?? throw new UnexpectedValueException("The anvil cannot make anything from these items");

        if(!$this->isCreative()){
            if($this->player->getXpLevel() < $cost){
                throw new UnexpectedValueException("The anvil needs {$cost} levels");
            }

            $this->pendingLevels += $cost;
        }

        $this->setItem(ContainerIds::ANVIL_INPUT, Player::UI_ANVIL_INPUT, Item::air());

        if($used > 0){
            $this->setItem(ContainerIds::ANVIL_MATERIAL, Player::UI_ANVIL_MATERIAL, (clone $material)->setCount($material->getCount() - $used));
        }

        $this->handler->createResult($result);
        $this->anvilUsed = true;
    }

    private function grind() : void{
        [$result, $experience] = Grindstone::grind($this->getItem(Player::UI_GRINDSTONE_INPUT), $this->getItem(Player::UI_GRINDSTONE_ADDITIONAL)) ?? throw new UnexpectedValueException("The grindstone cannot use these items");

        $this->setItem(ContainerIds::GRINDSTONE_INPUT, Player::UI_GRINDSTONE_INPUT, Item::air());
        $this->setItem(ContainerIds::GRINDSTONE_ADDITIONAL, Player::UI_GRINDSTONE_ADDITIONAL, Item::air());
        $this->handler->createResult($result);
        $this->pendingXp += $experience;
    }

    private function weave(string $pattern, int $times) : void{
        $banner = $this->getItem(Player::UI_LOOM_INPUT);
        $dye = $this->getItem(Player::UI_LOOM_DYE);

        if($times < 1 || $banner->getCount() < $times || $dye->getCount() < $times){
            throw new UnexpectedValueException("The loom needs {$times} banners and dyes");
        }

        $result = Loom::apply($banner, $dye, $this->getItem(Player::UI_LOOM_PATTERN), $pattern) ?? throw new UnexpectedValueException("The loom cannot add pattern {$pattern}");

        $this->setItem(ContainerIds::LOOM_INPUT, Player::UI_LOOM_INPUT, (clone $banner)->setCount($banner->getCount() - $times));
        $this->setItem(ContainerIds::LOOM_DYE, Player::UI_LOOM_DYE, (clone $dye)->setCount($dye->getCount() - $times));
        $this->handler->createResult($result->setCount($times));
    }

    private function smith(int $networkId) : void{
        $recipe = Server::getInstance()->getCraftingManager()->getSmithingRecipes()[$networkId] ?? throw new UnexpectedValueException("There is no smithing recipe {$networkId}");
        $template = $this->getItem(Player::UI_SMITHING_TEMPLATE);
        $base = $this->getItem(Player::UI_SMITHING_INPUT);
        $addition = $this->getItem(Player::UI_SMITHING_MATERIAL);

        if(!$recipe->matches($template, $base, $addition)){
            throw new UnexpectedValueException("The smithing table does not hold recipe {$recipe->getId()}");
        }

        foreach([[ContainerIds::SMITHING_TABLE_TEMPLATE, Player::UI_SMITHING_TEMPLATE, $template], [ContainerIds::SMITHING_TABLE_INPUT, Player::UI_SMITHING_INPUT, $base], [ContainerIds::SMITHING_TABLE_MATERIAL, Player::UI_SMITHING_MATERIAL, $addition]] as [$containerId, $slot, $item]){
            $this->setItem($containerId, $slot, (clone $item)->setCount($item->getCount() - 1));
        }

        $this->handler->createResult($recipe->apply($template, $base, $addition));
    }

    /**
     * @return array<int, array{int, list<array{Enchantment, int}>}>
     */
    private function getOffers() : array{
        $window = $this->session->getInventoryManager()?->getWindow();

        if($window === null){
            return [];
        }

        [$x, $y, $z] = $window->getPositions()[0];
        $bookshelves = EnchantingTable::countBookshelves($this->player->getWorld(), $x, $y, $z);

        return EnchantingTable::getOffers($this->player->getUIInventory()->getItem(Player::UI_ENCHANTING_INPUT), $bookshelves, $this->player->getEnchantmentSeed());
    }

    /**
     * Anvils wear out: chipped, then damaged, then gone.
     */
    private function wearAnvil() : void{
        $window = $this->session->getInventoryManager()?->getWindow();

        if($window === null){
            return;
        }

        [$x, $y, $z] = $window->getPositions()[0];
        $world = $this->player->getWorld();
        $block = $world->getBlock($x, $y, $z);
        $event = LevelEvent::SOUND_ANVIL_USED;

        if(!$this->isCreative() && mt_rand() / mt_getrandmax() < Anvil::DAMAGE_CHANCE){
            $next = match($block->getName()){
                BlockIds::ANVIL => BlockIds::CHIPPED_ANVIL,
                BlockIds::CHIPPED_ANVIL => BlockIds::DAMAGED_ANVIL,
                default => BlockIds::AIR
            };

            if($next === BlockIds::AIR){
                $world->setBlock($x, $y, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::AIR));
                $this->session->getInventoryManager()?->closeWindow(true);
                $event = LevelEvent::SOUND_ANVIL_BROKEN;
            }else{
                $state = Server::getInstance()->getBlockStateRegistry()->getDefaultState($next);

                foreach($block->getProperties() as $property => $value){
                    if($state->hasProperty($property)){
                        $state = $state->withProperty($property, $value);
                    }
                }

                $world->setBlock($x, $y, $z, $state);
            }
        }

        $world->broadcastPacket($x, $z, LevelEventPacket::create($event, $x + 0.5, $y + 0.5, $z + 0.5));
    }

    /**
     * The player picked the beacon's effects and paid for them with an ingot or a gem.
     */
    private function payBeacon(int $primary, int $secondary) : void{
        $beacon = $this->session->getInventoryManager()?->getWindow()?->getEntity();

        if(!$beacon instanceof Beacon){
            throw new UnexpectedValueException("No beacon is open");
        }

        $beacon->updateLevel($this->player->getWorld());

        if(!$beacon->canSelect($primary, $secondary)){
            throw new UnexpectedValueException("A level {$beacon->getLevel()} beacon cannot give effects {$primary} and {$secondary}");
        }

        $payment = $this->getItem(Player::UI_BEACON_PAYMENT);

        if(!Beacon::isPayment($payment)){
            if(!$this->isCreative() || !$payment->isNull()){
                throw new UnexpectedValueException("The beacon cannot be paid with " . $payment->getId());
            }
        }else{
            $this->setItem(ContainerIds::BEACON_PAYMENT, Player::UI_BEACON_PAYMENT, (clone $payment)->setCount($payment->getCount() - 1));
        }

        $this->beaconPayment = [$beacon, $primary, $secondary];
    }

    private function requireWindow(int $type) : void{
        if($this->getWindowType() !== $type){
            throw new UnexpectedValueException("This craft action needs screen type {$type} open");
        }
    }

    private function getItem(int $uiSlot) : Item{
        return $this->player->getUIInventory()->getItem($uiSlot);
    }

    private function setItem(int $containerId, int $uiSlot, Item $item) : void{
        $this->handler->setUIItem($containerId, $uiSlot, $item);
    }

    private function getWindowType() : ?int{
        return $this->session->getInventoryManager()?->getWindow()?->getType();
    }

    private function isCreative() : bool{
        return $this->player->getGameMode() === GameMode::CREATIVE;
    }
}