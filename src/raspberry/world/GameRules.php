<?php

declare(strict_types=1);

namespace raspberry\world;

use function array_key_exists;
use function is_bool;
use function is_int;

class GameRules{

    public const COMMAND_BLOCK_OUTPUT = "commandblockoutput";
    public const DO_DAYLIGHT_CYCLE = "dodaylightcycle";
    public const DO_ENTITY_DROPS = "doentitydrops";
    public const DO_FIRE_TICK = "dofiretick";
    public const RECIPES_UNLOCK = "recipesunlock";
    public const DO_LIMITED_CRAFTING = "dolimitedcrafting";
    public const DO_MOB_LOOT = "domobloot";
    public const DO_MOB_SPAWNING = "domobspawning";
    public const DO_TILE_DROPS = "dotiledrops";
    public const DO_WEATHER_CYCLE = "doweathercycle";
    public const DROWNING_DAMAGE = "drowningdamage";
    public const FALL_DAMAGE = "falldamage";
    public const FIRE_DAMAGE = "firedamage";
    public const KEEP_INVENTORY = "keepinventory";
    public const MOB_GRIEFING = "mobgriefing";
    public const PVP = "pvp";
    public const SHOW_COORDINATES = "showcoordinates";
    public const SHOW_DAYS_PLAYED = "showdaysplayed";
    public const NATURAL_REGENERATION = "naturalregeneration";
    public const TNT_EXPLODES = "tntexplodes";
    public const SEND_COMMAND_FEEDBACK = "sendcommandfeedback";
    public const DO_INSOMNIA = "doinsomnia";
    public const COMMAND_BLOCKS_ENABLED = "commandblocksenabled";
    public const DO_IMMEDIATE_RESPAWN = "doimmediaterespawn";
    public const SHOW_DEATH_MESSAGES = "showdeathmessages";
    public const SHOW_TAGS = "showtags";
    public const FREEZE_DAMAGE = "freezedamage";
    public const RESPAWN_BLOCKS_EXPLODE = "respawnblocksexplode";
    public const SHOW_BORDER_EFFECT = "showbordereffect";
    public const SHOW_RECIPE_MESSAGES = "showrecipemessages";
    public const PROJECTILES_CAN_BREAK_BLOCKS = "projectilescanbreakblocks";
    public const TNT_EXPLOSION_DROP_DECAY = "tntexplosiondropdecay";

    public const MAX_COMMAND_CHAIN_LENGTH = "maxcommandchainlength";
    public const RANDOM_TICK_SPEED = "randomtickspeed";
    public const FUNCTION_COMMAND_LIMIT = "functioncommandlimit";
    public const SPAWN_RADIUS = "spawnradius";
    public const PLAYERS_SLEEPING_PERCENTAGE = "playerssleepingpercentage";

    /** every rule with its default, in the order the game lists them */
    public const DEFAULTS = [
        self::COMMAND_BLOCK_OUTPUT => true,
        self::DO_DAYLIGHT_CYCLE => true,
        self::DO_ENTITY_DROPS => true,
        self::DO_FIRE_TICK => true,
        self::RECIPES_UNLOCK => true,
        self::DO_LIMITED_CRAFTING => false,
        self::DO_MOB_LOOT => true,
        self::DO_MOB_SPAWNING => true,
        self::DO_TILE_DROPS => true,
        self::DO_WEATHER_CYCLE => true,
        self::DROWNING_DAMAGE => true,
        self::FALL_DAMAGE => true,
        self::FIRE_DAMAGE => true,
        self::KEEP_INVENTORY => false,
        self::MOB_GRIEFING => true,
        self::PVP => true,
        self::SHOW_COORDINATES => false,
        self::SHOW_DAYS_PLAYED => false,
        self::NATURAL_REGENERATION => true,
        self::TNT_EXPLODES => true,
        self::SEND_COMMAND_FEEDBACK => true,
        self::DO_INSOMNIA => true,
        self::COMMAND_BLOCKS_ENABLED => true,
        self::DO_IMMEDIATE_RESPAWN => false,
        self::SHOW_DEATH_MESSAGES => true,
        self::SHOW_TAGS => true,
        self::FREEZE_DAMAGE => true,
        self::RESPAWN_BLOCKS_EXPLODE => true,
        self::SHOW_BORDER_EFFECT => true,
        self::SHOW_RECIPE_MESSAGES => true,
        self::PROJECTILES_CAN_BREAK_BLOCKS => true,
        self::TNT_EXPLOSION_DROP_DECAY => false,
        self::MAX_COMMAND_CHAIN_LENGTH => 65535,
        self::RANDOM_TICK_SPEED => 1,
        self::FUNCTION_COMMAND_LIMIT => 10000,
        self::SPAWN_RADIUS => 5,
        self::PLAYERS_SLEEPING_PERCENTAGE => 100
    ];

    /** @var array<string, bool|int> */
    private array $values;

    /**
     * @param array<string, bool|int> $values
     */
    public function __construct(array $values = []){
        $this->values = self::DEFAULTS;

        foreach($values as $name => $value){
            if(self::isRule($name) && self::fits($name, $value)){
                $this->values[$name] = $value;
            }
        }
    }

    public static function isRule(string $name) : bool{
        return array_key_exists($name, self::DEFAULTS);
    }

    public static function isBoolRule(string $name) : bool{
        return is_bool(self::DEFAULTS[$name] ?? null);
    }

    public static function isIntRule(string $name) : bool{
        return is_int(self::DEFAULTS[$name] ?? null);
    }

    /** whether a value has the type of the rule */
    public static function fits(string $name, bool|int $value) : bool{
        return self::isBoolRule($name) ? is_bool($value) : self::isIntRule($name) && is_int($value);
    }

    public function get(string $name) : bool|int|null{
        return $this->values[$name] ?? null;
    }

    public function getBool(string $name) : bool{
        return $this->values[$name] === true;
    }

    public function getInt(string $name) : int{
        $value = $this->values[$name];

        return is_int($value) ? $value : 0;
    }

    /**
     * False when there is no such rule, or the value is not of its type.
     */
    public function set(string $name, bool|int $value) : bool{
        if(!self::isRule($name) || !self::fits($name, $value)){
            return false;
        }

        $this->values[$name] = $value;
        return true;
    }

    /**
     * @return array<string, bool|int>
     */
    public function getAll() : array{
        return $this->values;
    }

    /**
     * @return array<string, bool|int>
     */
    public function toArray() : array{
        $changed = [];

        foreach($this->values as $name => $value){
            if($value !== self::DEFAULTS[$name]){
                $changed[$name] = $value;
            }
        }

        return $changed;
    }
}