<?php

declare(strict_types=1);

namespace raspberry\player;

use raspberry\block\BlockCollision;

use raspberry\entity\effect\Effect;
use raspberry\entity\effect\EffectInstance;
use raspberry\entity\object\ThrownPotion;

use raspberry\item\Food;
use raspberry\item\Item;
use raspberry\item\ItemIds;
use raspberry\item\Potion;

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

    public const RELEASE = 0;
    public const CONSUME = 1;

    /** how long drinking a potion or milk takes */
    private const DRINK_TICKS = 32;

    /** the client and server clocks drift a little */
    private const TICK_TOLERANCE = 4;

    private const THROW_SPEED = 0.5;

    /** an ominous bottle gives bad omen for 100 minutes */
    private const BAD_OMEN_TICKS = 120000;

    private const CHORUS_RANGE = 8;
    private const CHORUS_TRIES = 16;

    /** suspicious stew meta => effect, ticks */
    private const STEW_EFFECTS = [
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

        if($id === ItemIds::SPLASH_POTION || $id === ItemIds::LINGERING_POTION){
            $this->throwPotion($held);
            return;
        }

        $ticks = $this->getUseTicks($held);

        if($ticks !== null){
            $this->using = [$player->getInventory()->getHeldSlot(), $id, $this->getTick(), $ticks];
        }
    }

    /**
     * The client let go of the item, or finished eating it.
     */
    public function release(int $action) : void{
        $using = $this->using;
        $this->using = null;

        if($action !== self::CONSUME || $using === null){
            return;
        }

        $player = $this->player;
        $held = $player->getInventory()->getItemInHand();
        [$slot, $id, $start, $ticks] = $using;

        if($player->isDead() || $player->getInventory()->getHeldSlot() !== $slot || $held->getId() !== $id || $this->getTick() - $start < $ticks - self::TICK_TOLERANCE){
            $player->getNetworkSession()->getInventoryManager()?->sendSlot($player->getInventory(), $player->getInventory()->getHeldSlot());
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

        //thrown a little upward of where the player looks
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