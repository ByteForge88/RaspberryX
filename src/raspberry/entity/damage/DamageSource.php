<?php

declare(strict_types=1);

namespace raspberry\entity\damage;

use raspberry\entity\Entity;

use raspberry\player\Player;

use InvalidArgumentException;

use function str_replace;

class DamageSource{

    public const int CAUSE_CONTACT = 0;
    public const int CAUSE_ENTITY_ATTACK = 1;
    public const int CAUSE_PROJECTILE = 2;
    public const int CAUSE_SUFFOCATION = 3;
    public const int CAUSE_FALL = 4;
    public const int CAUSE_FIRE = 5;
    public const int CAUSE_FIRE_TICK = 6;
    public const int CAUSE_LAVA = 7;
    public const int CAUSE_DROWNING = 8;
    public const int CAUSE_BLOCK_EXPLOSION = 9;
    public const int CAUSE_ENTITY_EXPLOSION = 10;
    public const int CAUSE_VOID = 11;
    public const int CAUSE_SUICIDE = 12;
    public const int CAUSE_MAGIC = 13;
    public const int CAUSE_CUSTOM = 14;
    public const int CAUSE_STARVATION = 15;
    public const int CAUSE_FALLING_BLOCK = 16;
    public const int CAUSE_WITHER = 17;
    public const int CAUSE_THORNS = 18;
    public const int CAUSE_MAGMA = 19;
    public const int CAUSE_LIGHTNING = 20;
    public const int CAUSE_SWEET_BERRY = 21;
    public const int CAUSE_STALACTITE = 22;
    public const int CAUSE_STALAGMITE = 23;

    private int $cause;

    private float $amount;

    private ?Entity $attacker;

    private float $armorEffectiveness;

    public function __construct(int $cause, float $amount, ?Entity $attacker = null, float $armorEffectiveness = 1.0){
        if($cause < self::CAUSE_CONTACT || $cause > self::CAUSE_STALAGMITE){
            throw new InvalidArgumentException("Unknown damage cause {$cause}");
        }

        if($amount < 0){
            throw new InvalidArgumentException("Damage cannot be negative, got {$amount}");
        }

        if($armorEffectiveness < 0.0 || $armorEffectiveness > 1.0){
            throw new InvalidArgumentException("Armor effectiveness must be between 0 and 1, got {$armorEffectiveness}");
        }

        $this->cause = $cause;
        $this->amount = $amount;
        $this->attacker = $attacker;
        $this->armorEffectiveness = $armorEffectiveness;
    }

    public function getCause() : int{
        return $this->cause;
    }

    public function getAmount() : float{
        return $this->amount;
    }

    public function setAmount(float $amount) : void{
        if($amount < 0){
            throw new InvalidArgumentException("Damage cannot be negative, got {$amount}");
        }

        $this->amount = $amount;
    }

    public function getAttacker() : ?Entity{
        return $this->attacker;
    }

    public function getArmorEffectiveness() : float{
        return $this->armorEffectiveness;
    }

    public function bypassesGameMode() : bool{
        return $this->cause === self::CAUSE_VOID || $this->cause === self::CAUSE_SUICIDE;
    }

    public function isFire() : bool{
        return $this->cause === self::CAUSE_FIRE || $this->cause === self::CAUSE_FIRE_TICK || $this->cause === self::CAUSE_LAVA || $this->cause === self::CAUSE_MAGMA;
    }

    public function isReducedByArmor() : bool{
        return match($this->cause){
            self::CAUSE_CONTACT,
            self::CAUSE_ENTITY_ATTACK,
            self::CAUSE_PROJECTILE,
            self::CAUSE_FIRE,
            self::CAUSE_LAVA,
            self::CAUSE_BLOCK_EXPLOSION,
            self::CAUSE_ENTITY_EXPLOSION,
            self::CAUSE_THORNS,
            self::CAUSE_MAGMA,
            self::CAUSE_LIGHTNING,
            self::CAUSE_SWEET_BERRY => true,
            default => false
        };
    }

    public function getDeathMessageKey() : string{
        return match($this->cause){
            self::CAUSE_CONTACT => "death.attack.cactus",
            self::CAUSE_ENTITY_ATTACK => $this->attacker instanceof Player ? "death.attack.player" : "death.attack.mob",
            self::CAUSE_PROJECTILE => "death.attack.arrow",
            self::CAUSE_SUFFOCATION => "death.attack.inWall",
            self::CAUSE_FALL => "death.attack.fall",
            self::CAUSE_FIRE => "death.attack.inFire",
            self::CAUSE_FIRE_TICK => "death.attack.onFire",
            self::CAUSE_LAVA => "death.attack.lava",
            self::CAUSE_DROWNING => "death.attack.drown",
            self::CAUSE_BLOCK_EXPLOSION, self::CAUSE_ENTITY_EXPLOSION => "death.attack.explosion",
            self::CAUSE_VOID => "death.attack.outOfWorld",
            self::CAUSE_MAGIC => "death.attack.magic",
            self::CAUSE_STARVATION => "death.attack.starve",
            self::CAUSE_FALLING_BLOCK => "death.attack.fallingBlock",
            self::CAUSE_WITHER => "death.attack.wither",
            self::CAUSE_THORNS => "death.attack.thorns",
            self::CAUSE_MAGMA => "death.attack.magma",
            self::CAUSE_LIGHTNING => "death.attack.lightningBolt",
            self::CAUSE_SWEET_BERRY => "death.attack.sweetBerry",
            self::CAUSE_STALACTITE => "death.attack.stalactite",
            self::CAUSE_STALAGMITE => "death.attack.stalagmite",
            default => "death.attack.generic"
        };
    }

    public function getAttackerName() : ?string{
        $attacker = $this->attacker;

        if($attacker === null){
            return null;
        }

        if($attacker instanceof Player){
            return $attacker->getName();
        }

        return "%entity." . str_replace("minecraft:", "", $attacker->getNetworkTypeId()) . ".name";
    }
}