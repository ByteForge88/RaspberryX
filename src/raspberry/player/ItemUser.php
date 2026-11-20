<?php

declare(strict_types=1);

namespace raspberry\player;

use raspberry\block\BlockCollision;

use raspberry\entity\effect\Effect;
use raspberry\entity\effect\EffectInstance;
use raspberry\entity\object\ThrownPotion;
use raspberry\entity\projectile\Arrow;
use raspberry\entity\projectile\Egg;
use raspberry\entity\projectile\FishingHook;
use raspberry\entity\projectile\Projectile;
use raspberry\entity\projectile\Snowball;
use raspberry\entity\projectile\Throwable;

use raspberry\event\player\PlayerItemUseEvent;
use raspberry\event\player\PlayerItemConsumeEvent;

use raspberry\inventory\Inventory;

use raspberry\item\Food;
use raspberry\item\Item;
use raspberry\item\ItemIds;
use raspberry\item\Potion;
use raspberry\item\enchantment\Enchantment;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\world\Chunk;

use function cos;
use function deg2rad;
use function floor;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function sin;

class ItemUser{

    public const int RELEASE = 0;
    public const int CONSUME = 1;

    /** how long drinking a potion or milk takes */
    private const int DRINK_TICKS = 32;

    /** the client and server clocks drift a little */
    private const int TICK_TOLERANCE = 4;

    private const float THROW_SPEED = 0.5;

    /** a bow is held drawn for as long as the player likes */
    private const int BOW_TICKS = 72000;

    /** an ominous bottle gives bad omen for 100 minutes */
    private const int BAD_OMEN_TICKS = 120000;

    private const int CHORUS_RANGE = 8;
    private const int CHORUS_TRIES = 16;

    /** suspicious stew meta => effect, ticks */
    private const array STEW_EFFECTS = [
        0 => [Effect::NIGHT_VISION, 100], //poppy
        1 => [Effect::JUMP_BOOST, 100], //cornflower
        2 => [Effect::WEAKNESS, 140], //tulips
        3 => [Effect::BLINDNESS, 220], //azure bluet
        4 => [Effect::POISON, 220], //lily of the valley
        5 => [Effect::SATURATION, 7], //dandelion
        6 => [Effect::SATURATION, 7], //blue orchid
        7 => [Effect::FIRE_RESISTANCE, 60], //allium
        8 => [Effect::REGENERATION, 140], //oxeye daisy
        9 => [Effect::WITHER, 140], //wither rose
        10 => [Effect::NIGHT_VISION, 100], //torchflower
        11 => [Effect::BLINDNESS, 220], //open eyeblossom
        12 => [Effect::NAUSEA, 140] //closed eyeblossom
    ];

    private Player $player;

    /** @var array{int, string, int, int}|null */
    private ?array $using = null;

    public function __construct(Player $player){
        $this->player = $player;
    }

    public function isUsing() : bool{
        return $this->using !== null;
    }

    public function useInAir() : void{
        $player = $this->player;
        $held = $player->getInventory()->getItemInHand();
        $id = $held->getId();

        if($player->isDead() || $held->isNull() || $player->getGameMode() === GameMode::SPECTATOR){
            return;
        }

        $event = new PlayerItemUseEvent($player, $held);
        $event->call();

        if($event->isCancelled()){
            $player->getNetworkSession()->resendHeldItem();
            return;
        }

        if($id === ItemIds::SPLASH_POTION || $id === ItemIds::LINGERING_POTION){
            $this->throwPotion($held);
            return;
        }

        if($id === ItemIds::SNOWBALL || $id === ItemIds::EGG){
            $this->throwItem($held);
            return;
        }

        if($id === ItemIds::FISHING_ROD){
            $this->useFishingRod($held);
            return;
        }

        if($id === ItemIds::BOW){
            if($player->getGameMode()->hasInstantBuild() || $this->findArrow() !== null){
                $this->using = [$player->getInventory()->getHeldSlot(), $id, $this->getTick(), self::BOW_TICKS];
                $player->onUsingItemChanged();
            }

            return;
        }

        $ticks = $this->getUseTicks($held);

        if($ticks !== null){
            $this->using = [$player->getInventory()->getHeldSlot(), $id, $this->getTick(), $ticks];
            $player->onUsingItemChanged();
        }
    }

    /**
     * The client let go of the item, or finished eating it.
     */
    public function release(int $action) : void{
        $using = $this->using;
        $this->using = null;
        $player = $this->player;

        if($using !== null){
            $player->onUsingItemChanged();
        }

        if($action === self::RELEASE && $using !== null && $using[1] === ItemIds::BOW && $player->getInventory()->getHeldSlot() === $using[0]){
            $this->shootBow($this->getTick() - $using[2]);
            return;
        }

        if($action !== self::CONSUME || $using === null){
            return;
        }

        $held = $this->player->getInventory()->getItemInHand();
        [$slot, $id, $start, $ticks] = $using;

        if($this->player->isDead() || $this->player->getInventory()->getHeldSlot() !== $slot || $held->getId() !== $id || $this->getTick() - $start < $ticks - self::TICK_TOLERANCE){
            $this->player->getNetworkSession()->getInventoryManager()?->sendSlot($this->player->getInventory(), $this->player->getInventory()->getHeldSlot());
            return;
        }

        $event = new PlayerItemConsumeEvent($this->player, $held);
        $event->call();

        if($event->isCancelled()){
            $this->player->getNetworkSession()->resendHeldItem();
            return;
        }

        $this->consume($held);
    }

    /**
     * How long it takes to eat or drink $item, null when it cannot be right now.
     */
    private function getUseTicks(Item $item) : ?int{
        $id = $item->getId();

        if($id === ItemIds::POTION || $id === ItemIds::MILK_BUCKET || $id === ItemIds::OMINOUS_BOTTLE){
            return self::DRINK_TICKS;
        }

        $food = Food::get($id);

        if($food === null || (!$food->isAlwaysEdible() && $this->player->getFood() >= Player::MAX_FOOD)){
            return null;
        }

        return $food->getUseTicks();
    }

    private function consume(Item $held) : void{
        $player = $this->player;
        $effects = $player->getEffects();
        $id = $held->getId();
        $leftover = null;

        if($id === ItemIds::POTION){
            foreach(Potion::getEffects($held->getMeta()) as $effect){
                $effects->add($effect);
            }

            $leftover = ItemIds::GLASS_BOTTLE;
        }elseif($id === ItemIds::MILK_BUCKET){
            $effects->clear();
            $leftover = ItemIds::BUCKET;
        }elseif($id === ItemIds::OMINOUS_BOTTLE){
            $effects->add(new EffectInstance(Effect::BAD_OMEN, self::BAD_OMEN_TICKS, min($held->getMeta(), 4)));
            $this->playSound(LevelSoundEvent::APPLY_EFFECT_BAD_OMEN);
        }else{
            $food = Food::get($id);

            if($food === null){
                return;
            }

            $player->setFood(min($player->getFood() + $food->getNutrition(), Player::MAX_FOOD));
            $player->setSaturation(min($player->getSaturation() + $food->getSaturation(), (float) $player->getFood()));

            foreach($food->getEffects() as [$effect, $chance]){
                if($chance >= 1.0 || mt_rand() / mt_getrandmax() < $chance){
                    $effects->add(clone $effect);
                }
            }

            foreach($food->getRemovedEffects() as $effect){
                $effects->remove($effect);
            }

            if($food->getUseAction() === Food::ACTION_SUSPICIOUS_STEW && isset(self::STEW_EFFECTS[$held->getMeta()])){
                [$effect, $ticks] = self::STEW_EFFECTS[$held->getMeta()];
                $effects->add(new EffectInstance($effect, $ticks));
            }elseif($food->getUseAction() === Food::ACTION_CHORUS_TELEPORT){
                $this->chorusTeleport();
            }

            $leftover = $food->getConvertsTo();
            $this->playSound(LevelSoundEvent::BURP);
        }

        if($player->getGameMode()->hasInstantBuild()){
            return;
        }

        if($held->getCount() > 1){
            $player->getInventory()->setItemInHand((clone $held)->setCount($held->getCount() - 1));

            if($leftover !== null){
                foreach($player->getInventory()->addItem(new Item($leftover)) as $left){
                    $player->dropItem($left);
                }
            }
        }else{
            $player->getInventory()->setItemInHand($leftover === null ? Item::air() : new Item($leftover));
        }
    }

    private function throwPotion(Item $held) : void{
        $player = $this->player;
        $yaw = deg2rad($player->getYaw());
        $pitch = deg2rad($player->getPitch() - 20);
        $motion = new Vector3(-sin($yaw) * cos($pitch) * self::THROW_SPEED, -sin($pitch) * self::THROW_SPEED, cos($yaw) * cos($pitch) * self::THROW_SPEED);
        $eyes = $player->getEyePosition();

        new ThrownPotion($player->getWorld(), new Vector3($eyes->x, $eyes->y - 0.1, $eyes->z), $held, $motion, $player);

        $packet = LevelSoundEventPacket::create(LevelSoundEvent::THROW, $eyes->x, $eyes->y, $eyes->z);
        $packet->entityType = "minecraft:player";
        $player->getWorld()->broadcastPacket($eyes->getFloorX(), $eyes->getFloorZ(), $packet);

        if(!$player->getGameMode()->hasInstantBuild()){
            $player->getInventory()->setItemInHand($held->getCount() > 1 ? (clone $held)->setCount($held->getCount() - 1) : Item::air());
        }
    }

    /**
     * Snowballs and eggs fly straight where the player looks.
     */
    private function throwItem(Item $held) : void{
        $player = $this->player;
        $eyes = $player->getEyePosition();
        $motion = Projectile::aimFromRotation($player->getYaw(), $player->getPitch(), Throwable::VELOCITY, 1.0);
        $position = new Vector3($eyes->x, $eyes->y - 0.1, $eyes->z);

        if($held->getId() === ItemIds::SNOWBALL){
            new Snowball($player->getWorld(), $position, $motion, $player);
        }else{
            new Egg($player->getWorld(), $position, $motion, $player);
        }

        $packet = LevelSoundEventPacket::create(LevelSoundEvent::THROW, $eyes->x, $eyes->y, $eyes->z);
        $packet->entityType = "minecraft:player";
        $player->getWorld()->broadcastPacket($eyes->getFloorX(), $eyes->getFloorZ(), $packet);

        if(!$player->getGameMode()->hasInstantBuild()){
            $player->getInventory()->setItemInHand($held->getCount() > 1 ? (clone $held)->setCount($held->getCount() - 1) : Item::air());
        }
    }

    private function useFishingRod(Item $held) : void{
        $player = $this->player;
        $hook = $player->getFishingHook();

        if($hook !== null){
            $wear = $hook->reel();
            $player->setFishingHook(null);

            if(!$player->getGameMode()->hasInstantBuild()){
                for($i = 0; $i < $wear; ++$i){
                    $player->wearHeld(1);
                }
            }

            return;
        }

        $player->setFishingHook(new FishingHook($player->getWorld(), $player, $held->getEnchantmentLevel(Enchantment::LURE), $held->getEnchantmentLevel(Enchantment::LUCK_OF_THE_SEA)));

        $eyes = $player->getEyePosition();
        $packet = LevelSoundEventPacket::create(LevelSoundEvent::THROW, $eyes->x, $eyes->y, $eyes->z);
        $packet->entityType = "minecraft:player";
        $player->getWorld()->broadcastPacket($eyes->getFloorX(), $eyes->getFloorZ(), $packet);
    }

    /**
     * How strong a bow drawn for $ticks shoots, 0 to 1, full after a second.
     */
    public static function getBowPower(int $ticks) : float{
        $charge = $ticks / 20;
        return min(1.0, ($charge * $charge + $charge * 2) / 3);
    }

    /**
     * @return array{Inventory, int}|null
     */
    private function findArrow() : ?array{
        $offHand = $this->player->getOffHandInventory();

        if($offHand->getItem(0)->getId() === ItemIds::ARROW){
            return [$offHand, 0];
        }

        $inventory = $this->player->getInventory();

        for($slot = 0; $slot < $inventory->getSize(); ++$slot){
            if($inventory->getItem($slot)->getId() === ItemIds::ARROW){
                return [$inventory, $slot];
            }
        }

        return null;
    }

    private function shootBow(int $ticks) : void{
        $player = $this->player;
        $bow = $player->getInventory()->getItemInHand();
        $power = self::getBowPower($ticks);
        $found = $this->findArrow();
        $creative = $player->getGameMode()->hasInstantBuild();

        if($bow->getId() !== ItemIds::BOW || $power < 0.1 || ($found === null && !$creative)){
            return;
        }

        $arrow = $found !== null ? $found[0]->getItem($found[1]) : new Item(ItemIds::ARROW);
        $tipped = $arrow->getMeta() > 0;
        $infinite = $creative || ($bow->getEnchantmentLevel(Enchantment::INFINITY) > 0 && !$tipped);

        $eyes = $player->getEyePosition();
        $motion = Projectile::aimFromRotation($player->getYaw(), $player->getPitch(), $power * 3.0, 1.0);
        $shot = new Arrow($player->getWorld(), new Vector3($eyes->x, $eyes->y - 0.1, $eyes->z), $motion, $player, $infinite ? Arrow::PICKUP_CREATIVE : Arrow::PICKUP_ANY);

        $shot->setCritical($power >= 1.0);

        if($tipped){
            $shot->setPotion($arrow->getMeta() - 1);
        }

        $powerLevel = $bow->getEnchantmentLevel(Enchantment::POWER);

        if($powerLevel > 0){
            $shot->setBaseDamage($shot->getBaseDamage() + $powerLevel * 0.5 + 0.5);
        }

        $shot->setPunch($bow->getEnchantmentLevel(Enchantment::PUNCH));
        $shot->setBurning($bow->getEnchantmentLevel(Enchantment::FLAME) > 0);

        $packet = LevelSoundEventPacket::create(LevelSoundEvent::BOW, $eyes->x, $eyes->y, $eyes->z);
        $packet->entityType = "minecraft:player";
        $player->getWorld()->broadcastPacket($eyes->getFloorX(), $eyes->getFloorZ(), $packet);

        if($creative){
            return;
        }

        if(!$infinite && $found !== null){
            [$inventory, $slot] = $found;
            $inventory->setItem($slot, $arrow->getCount() > 1 ? (clone $arrow)->setCount($arrow->getCount() - 1) : Item::air());
        }

        $player->wearHeld(1);
    }

    /**
     * Chorus fruit sends the player somewhere close they can stand.
     */
    private function chorusTeleport() : void{
        $player = $this->player;
        $world = $player->getWorld();
        $position = $player->getPosition();

        for($try = 0; $try < self::CHORUS_TRIES; ++$try){
            $x = (int) floor($position->x) + mt_rand(-self::CHORUS_RANGE, self::CHORUS_RANGE);
            $z = (int) floor($position->z) + mt_rand(-self::CHORUS_RANGE, self::CHORUS_RANGE);

            if(!$world->isChunkLoaded($x >> 4, $z >> 4)){
                continue;
            }

            for($y = min((int) floor($position->y) + self::CHORUS_RANGE, Chunk::MAX_Y - 1); $y > (int) floor($position->y) - self::CHORUS_RANGE && $y > Chunk::MIN_Y; --$y){
                if(BlockCollision::isSolid($world->getBlock($x, $y - 1, $z)) && !BlockCollision::isSolid($world->getBlock($x, $y, $z)) && !BlockCollision::isSolid($world->getBlock($x, $y + 1, $z))){
                    $this->playSound(LevelSoundEvent::TELEPORT);
                    $player->teleport(new Vector3($x + 0.5, (float) $y, $z + 0.5));
                    $this->playSound(LevelSoundEvent::TELEPORT);
                    return;
                }
            }
        }
    }

    private function playSound(string $sound) : void{
        $position = $this->player->getPosition();
        $this->player->getWorld()->broadcastPacket($position->getFloorX(), $position->getFloorZ(), LevelSoundEventPacket::create($sound, $position->x, $position->y, $position->z));
    }

    private function getTick() : int{
        return $this->player->getNetworkSession()->getServer()->getCurrentTick();
    }
}