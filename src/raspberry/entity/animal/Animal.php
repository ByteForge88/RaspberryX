<?php

declare(strict_types=1);

namespace raspberry\entity\animal;

use raspberry\entity\Mob;
use raspberry\entity\EntityFactory;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\ActorEventPacket;
use raspberry\network\mcpe\protocol\types\EntityMetadata;

use raspberry\player\Player;

use function in_array;
use function intdiv;
use function mt_rand;

abstract class Animal extends Mob{

    /** a baby takes 20 minutes to grow up */
    public const int BABY_AGE = -24000;

    /** five minutes until a parent can have the next baby */
    public const int BREED_COOLDOWN = 6000;

    public const int LOVE_TICKS = 600;

    /** below zero: ticks until a baby is grown. Above: ticks until an adult can breed again. */
    protected int $age = 0;

    protected int $loveTicks = 0;

    /** who fed the animal, they get the experience when the baby comes */
    protected ?Player $feeder = null;

    /**
     * @return list<string>
     */
    abstract public function getBreedItems() : array;

    public function getXpReward() : int{
        return mt_rand(1, 3);
    }

    public function getEngineFlags() : int{
        return parent::getEngineFlags() | ($this->loveTicks > 0 ? self::AI_IN_LOVE : 0);
    }

    public function createMetadata() : EntityMetadata{
        $metadata = parent::createMetadata();
        $metadata->setFlag(EntityMetadata::FLAG_IN_LOVE, $this->loveTicks > 0);

        return $metadata;
    }

    public function getAge() : int{
        return $this->age;
    }

    public function setBaby(bool $baby) : void{
        if($baby && $this->age >= 0){
            $this->age = self::BABY_AGE;
        }elseif(!$baby && $this->age < 0){
            $this->age = 0;
        }

        parent::setBaby($baby);
    }

    public function isInLove() : bool{
        return $this->loveTicks > 0;
    }

    public function canFallInLove() : bool{
        return !$this->baby && $this->age === 0 && $this->loveTicks <= 0;
    }

    public function setInLove(bool $inLove, ?Player $feeder = null) : void{
        $this->loveTicks = $inLove ? self::LOVE_TICKS : 0;
        $this->feeder = $inLove ? $feeder : null;
        $this->world->getMobEngine()->update($this);
        $this->sendMetadata();
    }

    public function tick(int $currentTick) : void{
        parent::tick($currentTick);

        if($this->closed || !$this->isAlive()){
            return;
        }

        if($this->age < 0){
            if(++$this->age === 0){
                $this->setBaby(false);
            }
        }elseif($this->age > 0){
            --$this->age;
        }

        if($this->loveTicks > 0 && --$this->loveTicks === 0){
            $this->setInLove(false);
        }
    }

    /**
     * Brings a baby closer to being grown by this many ticks.
     */
    public function growUp(int $ticks) : void{
        if($this->age < 0){
            $this->age += $ticks;

            if($this->age >= 0){
                $this->age = 0;
                $this->setBaby(false);
            }
        }
    }

    private function wantsFood(Player $player) : bool{
        return in_array($player->getInventory()->getItemInHand()->getId(), $this->getBreedItems(), true) && ($this->baby || $this->canFallInLove());
    }

    public function getInteractText(Player $player) : string{
        return $this->wantsFood($player) ? "action.interact.feed" : "";
    }

    public function onInteract(Player $player) : bool{
        if(!$this->wantsFood($player)){
            return false;
        }

        if($this->baby){
            $this->growUp(intdiv(-$this->age, 10));
            $this->broadcastEvent(ActorEventPacket::BABY_ANIMAL_FEED);
        }else{
            $this->setInLove(true, $player);
            $this->broadcastEvent(ActorEventPacket::LOVE_PARTICLES);
        }

        self::useUpHeld($player);

        return true;
    }

    public function onEngineEvent(int $kind, float $value, int $x, int $y, int $z, int $other) : void{
        if($kind === self::EVENT_BRED){
            $partner = $this->world->getMobEngine()->getMob($other);

            if($partner instanceof self && $partner->isAlive()){
                $this->breedWith($partner);
            }

            return;
        }

        parent::onEngineEvent($kind, $value, $x, $y, $z, $other);
    }

    public function breedWith(Animal $partner) : void{
        $feeder = $this->feeder ?? $partner->feeder;

        foreach([$this, $partner] as $parent){
            $parent->age = self::BREED_COOLDOWN;
            $parent->setInLove(false);
        }

        $baby = EntityFactory::create($this->getNetworkTypeId(), $this->world, $this->position, $this->yaw);

        if($baby instanceof self){
            $baby->inherit($this, $partner);
            $baby->setBaby(true);
        }

        if($feeder !== null && $feeder->isOnline()){
            $feeder->addXp(mt_rand(1, 7));
        }
    }

    protected function inherit(Animal $first, Animal $second) : void{

    }

    public function save() : ?CompoundTag{
        $tag = parent::save();
        $tag?->setInt("Age", $this->age);
        $tag?->setInt("InLove", $this->loveTicks);

        return $tag;
    }

    public function load(CompoundTag $tag) : void{
        parent::load($tag);

        $this->age = $tag->getInt("Age", 0);
        $this->loveTicks = $tag->getInt("InLove", 0);
        $this->baby = $this->age < 0;
    }
}