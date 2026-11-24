<?php

declare(strict_types=1);

namespace raspberry\block;

use Closure;

use raspberry\Server;

use raspberry\math\Vector3;

use raspberry\world\Chunk;
use raspberry\world\World;

use function preg_match;

class LightningRods{

    public const int RANGE = 64;

    public const int POWER_TICKS = 8;

    private const string NAME = '/^minecraft:(waxed_)?((exposed|weathered|oxidized)_)?lightning_rod$/D';

    private const array NAMES = [
        BlockIds::LIGHTNING_ROD, BlockIds::EXPOSED_LIGHTNING_ROD, BlockIds::WEATHERED_LIGHTNING_ROD, BlockIds::OXIDIZED_LIGHTNING_ROD,
        BlockIds::WAXED_LIGHTNING_ROD, BlockIds::WAXED_EXPOSED_LIGHTNING_ROD, BlockIds::WAXED_WEATHERED_LIGHTNING_ROD, BlockIds::WAXED_OXIDIZED_LIGHTNING_ROD
    ];

    /** @var list<int>|null */
    private static ?array $states = null;

    private World $world;

    private Closure $schedule;

    /** @var array<string, array<string, array{int, int, int}>> */
    private array $rods = [];

    /**
     * @param Closure(int, int, int) : void $schedule
     */
    public function __construct(World $world, Closure $schedule){
        $this->world = $world;
        $this->schedule = $schedule;
    }

    public static function isRod(string $name) : bool{
        return preg_match(self::NAME, $name) === 1;
    }

    public static function isPowered(BlockState $rod) : bool{
        return $rod->getProperty(BlockProperties::POWERED_BIT) === true;
    }

    public static function getFacing(BlockState $rod) : int{
        return (int) $rod->getProperty(BlockProperties::FACING_DIRECTION);
    }

    public function findIn(Chunk $chunk) : void{
        if(self::$states === null){
            self::$states = [];
            $registry = Server::getInstance()->getBlockStateRegistry();

            foreach(self::NAMES as $name){
                for($facing = 0; $facing < 6; ++$facing){
                    foreach([false, true] as $powered){
                        self::$states[] = BlockStateIds::get($registry->get($name, [BlockProperties::FACING_DIRECTION => $facing, BlockProperties::POWERED_BIT => $powered]));
                    }
                }
            }
        }

        $key = "{$chunk->getX()}:{$chunk->getZ()}";
        unset($this->rods[$key]);

        foreach($chunk->findBlocks(self::$states) as [$x, $y, $z]){
            $x += $chunk->getX() << 4;
            $z += $chunk->getZ() << 4;
            $this->rods[$key]["{$x}:{$y}:{$z}"] = [$x, $y, $z];
        }
    }

    public function forget(int $chunkX, int $chunkZ) : void{
        unset($this->rods["{$chunkX}:{$chunkZ}"]);
    }

    public function onBlockChanged(int $x, int $y, int $z, BlockState $old, BlockState $new) : void{
        if(self::isRod($new->getName())){
            $this->rods[($x >> 4) . ":" . ($z >> 4)]["{$x}:{$y}:{$z}"] = [$x, $y, $z];
        }elseif(self::isRod($old->getName())){
            unset($this->rods[($x >> 4) . ":" . ($z >> 4)]["{$x}:{$y}:{$z}"]);
        }
    }

    public function findTarget(Vector3 $near) : ?Vector3{
        $best = null;
        $bestDistance = self::RANGE * self::RANGE + 1;

        foreach($this->rods as $rods){
            foreach($rods as [$x, $y, $z]){
                $distance = ($x + 0.5 - $near->x) ** 2 + ($y + 0.5 - $near->y) ** 2 + ($z + 0.5 - $near->z) ** 2;

                if($distance < $bestDistance && $this->isExposed($x, $y, $z)){
                    $best = [$x, $y, $z];
                    $bestDistance = $distance;
                }
            }
        }

        return $best === null ? null : new Vector3($best[0] + 0.5, $best[1] + 1, $best[2] + 0.5);
    }

    public function strike(int $x, int $y, int $z) : void{
        if(!$this->world->isChunkLoaded($x >> 4, $z >> 4)){
            return;
        }

        $rod = $this->world->getBlock($x, $y, $z);

        if(self::isRod($rod->getName())){
            $this->world->setBlock($x, $y, $z, $rod->withProperty(BlockProperties::POWERED_BIT, true));
            ($this->schedule)($x, $y, $z);
        }
    }

    public function unpower(int $x, int $y, int $z, BlockState $rod) : void{
        if(self::isRod($rod->getName()) && self::isPowered($rod)){
            $this->world->setBlock($x, $y, $z, $rod->withProperty(BlockProperties::POWERED_BIT, false));
        }
    }

    private function isExposed(int $x, int $y, int $z) : bool{
        if(!$this->world->isChunkLoaded($x >> 4, $z >> 4) || !self::isRod($this->world->getBlock($x, $y, $z)->getName())){
            return false;
        }

        for($above = $y + 1; $above <= Chunk::MAX_Y; ++$above){
            if($this->world->getBlock($x, $above, $z)->getName() !== BlockIds::AIR){
                return false;
            }
        }

        return true;
    }
}