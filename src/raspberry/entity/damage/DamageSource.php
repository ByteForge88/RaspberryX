<?php

declare(strict_types=1);

namespace raspberry\entity\damage;

use raspberry\entity\Entity;

use raspberry\player\Player;

use InvalidArgumentException;

use function str_replace;

class DamageSource{

    public const CAUSE_CONTACT = 0;
    public const CAUSE_ENTITY_ATTACK = 1;
    public const CAUSE_PROJECTILE = 2;
    public const CAUSE_SUFFOCATION = 3;
    public const CAUSE_FALL = 4;
    public const CAUSE_FIRE = 5;
    public const CAUSE_FIRE_TICK = 6;
    public const CAUSE_LAVA = 7;
    public const CAUSE_DROWNING = 8;
    public const CAUSE_BLOCK_EXPLOSION = 9;
    public const CAUSE_ENTITY_EXPLOSION = 10;
    public const CAUSE_VOID = 11;
    public const CAUSE_SUICIDE = 12;
    public const CAUSE_MAGIC = 13;
    public const CAUSE_CUSTOM = 14;
    public const CAUSE_STARVATION = 15;
    public const CAUSE_FALLING_BLOCK = 16;
    public const CAUSE_WITHER = 17;

    private int $cause;

    private float $amount;

    private ?Entity $attacker;

    public function __construct(int $cause, float $amount, ?Entity $attacker = null){
        if($cause < self::CAUSE_CONTACT || $cause > self::CAUSE_WITHER){
            throw new InvalidArgumentException("Unknown damage cause {$cause}");
        }

        if($amount < 0){
            throw new InvalidArgumentException("Damage cannot be negative, got {$amount}");
        }

        $this->cause = $cause;
        $this->amount = $amount;
        $this->attacker = $attacker;
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

    public function bypassesGameMode() : bool{
        return $this->cause === self::CAUSE_VOID || $this->cause === self::CAUSE_SUICIDE;
    }

    public function isFire() : bool{
        return $this->cause === self::CAUSE_FIRE || $this->cause === self::CAUSE_FIRE_TICK || $this->cause === self::CAUSE_LAVA;
    }

    public function isReducedByArmor() : bool{
        return match($this->cause){
            self::CAUSE_CONTACT,
            self::CAUSE_ENTITY_ATTACK,
            self::CAUSE_PROJECTILE,
            self::CAUSE_FIRE,
            self::CAUSE_LAVA,
            self::CAUSE_BLOCK_EXPLOSION,
            self::CAUSE_ENTITY_EXPLOSION => true,
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