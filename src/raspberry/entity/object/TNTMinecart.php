<?php

declare(strict_types=1);

namespace raspberry\entity\object;

use raspberry\entity\EntityIds;

use raspberry\event\entity\ExplosionPrimeEvent;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\EntityMetadata;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\player\Player;

use raspberry\world\Explosion;
use raspberry\world\GameRules;
use raspberry\world\World;

class TNTMinecart extends Cart{

    public const int FUSE = 80;

    public const float EXPLOSION_RADIUS = 3.0;

    private const float VOLATILE_SPEED = 0.1;

    private int $fuse = -1;

    public static function fromSave(World $world, CompoundTag $tag) : ?static{
        $cart = parent::fromSave($world, $tag);

        if($cart !== null){
            $cart->fuse = $tag->getInt("Fuse", -1) ?? -1;
        }

        return $cart;
    }

    public function save() : ?CompoundTag{
        $tag = parent::save();
        $tag?->setInt("Fuse", $this->fuse);
        return $tag;
    }

    public function getNetworkTypeId() : string{
        return EntityIds::TNT_MINECART;
    }

    public function getItemId() : string{
        return ItemIds::TNT_MINECART;
    }

    public function isLit() : bool{
        return $this->fuse >= 0;
    }

    public function getFuse() : int{
        return $this->fuse;
    }

    protected function addMetadata(EntityMetadata $metadata) : void{
        if($this->fuse >= 0){
            $metadata->setFlag(EntityMetadata::FLAG_IGNITED);
            $metadata->setInt(EntityMetadata::KEY_FUSE_TIME, $this->fuse);
        }
    }

    public function light(int $fuse = self::FUSE) : bool{
        if($this->closed || $this->fuse >= 0 || !$this->canExplode()){
            return false;
        }

        $this->fuse = $fuse;
        $this->broadcastMetadata();
        $this->world->broadcastPacket($this->position->getFloorX(), $this->position->getFloorZ(), LevelSoundEventPacket::create(LevelSoundEvent::FUSE, $this->position->x, $this->position->y, $this->position->z));

        return true;
    }

    public function getInteractText(Player $player) : string{
        return $this->fuse < 0 && self::isIgniter($player->getInventory()->getItemInHand()) ? "action.interact.creeper" : "";
    }

    public function interact(Player $player) : void{
        $held = $player->getInventory()->getItemInHand();

        if(!self::isIgniter($held) || !$this->light()){
            return;
        }

        $this->world->broadcastPacket($this->position->getFloorX(), $this->position->getFloorZ(), LevelSoundEventPacket::create(LevelSoundEvent::IGNITE, $this->position->x, $this->position->y, $this->position->z));

        if($player->getGameMode()->hasInstantBuild()){
            return;
        }

        if($held->getId() === ItemIds::FIRE_CHARGE){
            $player->getInventory()->setItemInHand($held->getCount() > 1 ? $held->setCount($held->getCount() - 1) : Item::air());
        }else{
            $player->wearHeld(1);
        }
    }

    public function tick(int $currentTick) : void{
        parent::tick($currentTick);

        if($this->closed || $this->fuse < 0){
            return;
        }

        if(!$this->canExplode()){
            $this->fuse = -1;
            $this->broadcastMetadata();
        }elseif(--$this->fuse <= 0){
            $this->explode();
        }
    }

    public function destroy(bool $drop) : void{
        if(!$this->closed && $this->canExplode() && $this->motion->x * $this->motion->x + $this->motion->z * $this->motion->z >= self::VOLATILE_SPEED * self::VOLATILE_SPEED){
            $this->explode();
            return;
        }

        parent::destroy($drop);
    }

    public function hurtByExplosion(float $amount) : void{
        if($this->canExplode()){
            $this->explode();
        }else{
            parent::hurtByExplosion($amount);
        }
    }

    protected function onActivatorRail(bool $powered) : void{
        if($powered){
            $this->light();
        }
    }

    protected function burn() : void{
        if($this->canExplode()){
            $this->explode();
        }else{
            parent::burn();
        }
    }

    public function explode() : void{
        if($this->closed){
            return;
        }

        $this->close();
        $event = new ExplosionPrimeEvent($this, self::EXPLOSION_RADIUS);
        $event->call();

        if($event->isCancelled()){
            return;
        }

        $explosion = new Explosion($this->world, $this->position, $event->getRadius(), $this);
        $explosion->setBlockBreaking($event->isBlockBreaking());
        $explosion->explode();
    }

    private function canExplode() : bool{
        return $this->world->getGameRules()->getBool(GameRules::TNT_EXPLODES);
    }

    private static function isIgniter(Item $item) : bool{
        return $item->getId() === ItemIds::FLINT_AND_STEEL || $item->getId() === ItemIds::FIRE_CHARGE;
    }
}