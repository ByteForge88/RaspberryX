<?php

declare(strict_types=1);

namespace raspberry\entity;

use raspberry\entity\animal\Chicken;
use raspberry\entity\animal\Cow;
use raspberry\entity\animal\Pig;
use raspberry\entity\animal\Sheep;

use raspberry\math\Vector3;

use raspberry\nbt\tag\CompoundTag;

use raspberry\world\World;

use Closure;

use function array_keys;
use function str_ends_with;
use function strlen;
use function substr;

class EntityFactory{

    private const SPAWN_EGG = "_spawn_egg";

    /** @var array<string, Closure(World, Vector3, float) : Mob>|null */
    private static ?array $mobs = null;

    private function __construct(){
    }

    /**
     * @return array<string, Closure(World, Vector3, float) : Mob>
     */
    private static function mobs() : array{
        return self::$mobs ??= [
            EntityIds::CHICKEN => fn(World $world, Vector3 $position, float $yaw) : Mob => new Chicken($world, $position, $yaw),
            EntityIds::COW => fn(World $world, Vector3 $position, float $yaw) : Mob => new Cow($world, $position, $yaw),
            EntityIds::PIG => fn(World $world, Vector3 $position, float $yaw) : Mob => new Pig($world, $position, $yaw),
            EntityIds::SHEEP => fn(World $world, Vector3 $position, float $yaw) : Mob => new Sheep($world, $position, $yaw)
        ];
    }

    /**
     * @param Closure(World, Vector3, float) : Mob $create
     */
    public static function register(string $networkTypeId, Closure $create) : void{
        self::mobs();
        self::$mobs[$networkTypeId] = $create;
    }

    public static function isRegistered(string $networkTypeId) : bool{
        return isset(self::mobs()[$networkTypeId]);
    }

    /**
     * @return list<string>
     */
    public static function getRegistered() : array{
        return array_keys(self::mobs());
    }

    public static function create(string $networkTypeId, World $world, Vector3 $position, float $yaw = 0.0) : ?Mob{
        $create = self::mobs()[$networkTypeId] ?? null;

        return $create === null ? null : $create($world, $position, $yaw);
    }

    public static function load(World $world, CompoundTag $tag) : ?Mob{
        $name = $tag->getString("identifier", "");
        [$x, $y, $z] = [$tag->getDouble("X"), $tag->getDouble("Y"), $tag->getDouble("Z")];

        if($x === null || $y === null || $z === null || !self::isRegistered($name)){
            return null;
        }

        $mob = self::create($name, $world, new Vector3($x, $y, $z), $tag->getFloat("Yaw", 0.0));
        $mob?->load($tag);

        return $mob;
    }

    public static function getSpawnEggType(string $itemId) : ?string{
        if(!str_ends_with($itemId, self::SPAWN_EGG)){
            return null;
        }

        $name = substr($itemId, 0, -strlen(self::SPAWN_EGG));
        return self::isRegistered($name) ? $name : null;
    }
}