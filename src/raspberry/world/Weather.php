<?php

declare(strict_types=1);

namespace raspberry\world;

use raspberry\Server;

use raspberry\block\BlockCollision;
use raspberry\block\BlockIds;
use raspberry\block\BlockProperties;
use raspberry\block\Cauldrons;
use raspberry\block\growth\Nearby;

use raspberry\entity\Living;
use raspberry\entity\object\LightningBolt;

use raspberry\event\world\LightningStrikeEvent;
use raspberry\event\world\WeatherChangeEvent;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\Packet;
use raspberry\network\mcpe\protocol\types\LevelEvent;

use raspberry\world\biome\Biome;
use raspberry\world\format\io\WorldMetadata;

use function abs;
use function array_values;
use function ceil;
use function count;
use function in_array;
use function max;
use function min;
use function mt_rand;
use function str_ends_with;

class Weather{

    //how long clear and rainy spells last, in ticks
    private const array RAIN_DELAY = [12000, 180000];
    private const array RAIN_DURATION = [12000, 24000];
    private const array THUNDER_DELAY = [12000, 180000];
    private const array THUNDER_DURATION = [3600, 15600];

    public const array COMMAND_DURATION = [6000, 18000];

    private const float FADE = 0.01;

    private const int LIGHTNING_CHANCE = 100000;

    private const int PRECIPITATION_CHANCE = 16;

    private const int FREEZE_LIGHT = 10;

    private const int MAX_SNOW_LAYERS = 8;

    private const array NO_SNOW_ON = [BlockIds::ICE, "minecraft:packed_ice", "minecraft:barrier"];

    private World $world;

    private WorldMetadata $metadata;

    private float $rainLevel;

    private float $thunderLevel;

    private bool $sentRain;

    private bool $sentThunder;

    public function __construct(World $world, WorldMetadata $metadata){
        $this->world = $world;
        $this->metadata = $metadata;
        $this->rainLevel = $metadata->raining ? 1.0 : 0.0;
        $this->thunderLevel = $metadata->thundering ? 1.0 : 0.0;
        $this->sentRain = $metadata->raining;
        $this->sentThunder = $metadata->raining && $metadata->thundering;
    }

    /** how hard it rains, 0 to 1 */
    public function getRainLevel() : float{
        return $this->rainLevel;
    }

    /** how stormy it is, 0 to 1: thunder needs rain */
    public function getThunderLevel() : float{
        return $this->thunderLevel * $this->rainLevel;
    }

    public function isRaining() : bool{
        return $this->rainLevel > 0.2;
    }

    public function isThundering() : bool{
        return $this->getThunderLevel() > 0.9;
    }

    public function setClear(int $duration) : void{
        $this->set($duration, 0, false, false);
    }

    public function setRain(int $duration) : void{
        $this->set(0, $duration, true, false);
    }

    public function setThunder(int $duration) : void{
        $this->set(0, $duration, true, true);
    }

    private function set(int $clearTime, int $weatherTime, bool $raining, bool $thundering) : void{
        $this->metadata->clearWeatherTime = $clearTime;
        $this->metadata->rainTime = $weatherTime;
        $this->metadata->thunderTime = $weatherTime;
        $this->metadata->raining = $raining;
        $this->metadata->thundering = $thundering;
        $this->sendChanges();
    }

    /**
     * @param list<Chunk> $chunks
     */
    public function tick(array $chunks) : void{
        if($this->world->getGameRules()->getBool(GameRules::DO_WEATHER_CYCLE)){
            $this->cycle();
        }

        $this->rainLevel = max(0.0, min(1.0, $this->rainLevel + ($this->metadata->raining ? self::FADE : -self::FADE)));
        $this->thunderLevel = max(0.0, min(1.0, $this->thunderLevel + ($this->metadata->thundering ? self::FADE : -self::FADE)));
        $this->sendChanges();

        $storm = $this->isRaining() && $this->isThundering();

        foreach($chunks as $chunk){
            if($storm && mt_rand(0, self::LIGHTNING_CHANCE - 1) === 0){
                $this->strikeIn($chunk);
            }

            if(mt_rand(0, self::PRECIPITATION_CHANCE - 1) === 0){
                $this->precipitateIn($chunk);
            }
        }
    }

    private function cycle() : void{
        $data = $this->metadata;

        if($data->clearWeatherTime > 0){
            --$data->clearWeatherTime;
            $data->thunderTime = $data->thundering ? 0 : 1;
            $data->rainTime = $data->raining ? 0 : 1;
            $data->thundering = false;
            $data->raining = false;

            return;
        }

        if($data->thunderTime > 0){
            if(--$data->thunderTime === 0){
                $data->thundering = !$data->thundering;
            }
        }else{
            $data->thunderTime = $data->thundering ? mt_rand(...self::THUNDER_DURATION) : mt_rand(...self::THUNDER_DELAY);
        }

        if($data->rainTime > 0){
            if(--$data->rainTime === 0){
                $data->raining = !$data->raining;
            }
        }else{
            $data->rainTime = $data->raining ? mt_rand(...self::RAIN_DURATION) : mt_rand(...self::RAIN_DELAY);
        }
    }

    private function sendChanges() : void{
        $raining = $this->metadata->raining;
        $thundering = $raining && $this->metadata->thundering;

        if($raining !== $this->sentRain || $thundering !== $this->sentThunder){
            $event = new WeatherChangeEvent($this->world, $this->sentRain, $this->sentThunder, $raining, $thundering);
            $event->call();

            if($event->isCancelled()){
                $this->metadata->raining = $this->sentRain;
                $this->metadata->thundering = $this->sentThunder;
                return;
            }
        }

        if($raining !== $this->sentRain){
            $this->sentRain = $raining;
            $this->broadcast(LevelEventPacket::create($raining ? LevelEvent::START_RAINING : LevelEvent::STOP_RAINING, 0, 0, 0, $raining ? 65535 : 0));
        }

        if($thundering !== $this->sentThunder){
            $this->sentThunder = $thundering;
            $this->broadcast(LevelEventPacket::create($thundering ? LevelEvent::START_THUNDERSTORM : LevelEvent::STOP_THUNDERSTORM, 0, 0, 0, $thundering ? 65535 : 0));
        }
    }

    private function broadcast(Packet $packet) : void{
        foreach($this->world->getPlayers() as $player){
            $player->getNetworkSession()->sendDataPacket($packet);
        }
    }

    public function isRainingAt(int $x, int $y, int $z) : bool{
        if(!$this->isRaining() || !$this->world->isChunkLoaded($x >> 4, $z >> 4)){
            return false;
        }

        $bottom = $this->world->getLightEngine()->getSkyBottom($x, $z);
        return $bottom !== null && $y >= $bottom && $this->world->getBiome($x, $y, $z)->canRainAt($y);
    }

    private function strikeIn(Chunk $chunk) : void{
        $x = ($chunk->getX() << 4) + mt_rand(0, 15);
        $z = ($chunk->getZ() << 4) + mt_rand(0, 15);
        $y = $this->world->getLightEngine()->getSkyBottom($x, $z);

        if($y === null){
            return;
        }

        $rod = $this->world->getRedstoneUpdater()->getLightningRods()->findTarget(new Vector3($x + 0.5, $y, $z + 0.5));
        $targets = [];

        foreach($rod === null ? $this->world->getEntities() : [] as $entity){
            $position = $entity->getPosition();

            if($entity instanceof Living && !$entity->isClosed() && $entity->isAlive() && $position->y >= $y - 3 && abs($position->x - $x - 0.5) <= 3.5 && abs($position->z - $z - 0.5) <= 3.5 && $this->world->canSeeSky($position->getFloorX(), $position->getFloorY(), $position->getFloorZ())){
                $targets[] = $position;
            }
        }

        $target = $rod ?? ($targets === [] ? new Vector3($x + 0.5, $y, $z + 0.5) : array_values($targets)[mt_rand(0, count($targets) - 1)]);

        if(!$this->isRainingAt($target->getFloorX(), $target->getFloorY(), $target->getFloorZ())){
            return;
        }

        $event = new LightningStrikeEvent($this->world, $target);
        $event->call();

        if(!$event->isCancelled()){
            new LightningBolt($this->world, $event->getPosition());
        }
    }

    private function precipitateIn(Chunk $chunk) : void{
        $world = $this->world;
        $x = ($chunk->getX() << 4) + mt_rand(0, 15);
        $z = ($chunk->getZ() << 4) + mt_rand(0, 15);
        $y = $world->getLightEngine()->getSkyBottom($x, $z);

        if($y === null || $y <= Chunk::MIN_Y || $y > Chunk::MAX_Y){
            return;
        }

        $biome = $world->getBiome($x, $y, $z);
        $below = Nearby::block($world, $x, $y - 1, $z);

        if($biome->getTemperatureAt($y - 1) < Biome::SNOW_TEMPERATURE && $below !== null && Nearby::isWaterSource($below) && $world->getBlockLight($x, $y - 1, $z) < self::FREEZE_LIGHT){
            $world->setBlock($x, $y - 1, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::ICE));
            return;
        }

        if(!$this->isRaining() || !$biome->hasPrecipitation() || $below === null){
            return;
        }

        $cold = $biome->canSnowAt($y);

        if($cold && $world->getBlockLight($x, $y, $z) < self::FREEZE_LIGHT){
            $this->snowOn($x, $y, $z, $biome->getMaxSnow());
        }

        if($below->getName() === BlockIds::CAULDRON){
            Cauldrons::catchPrecipitation($world, $x, $y - 1, $z, $below, $cold);
        }
    }

    private function snowOn(int $x, int $y, int $z, float $maxSnow) : void{
        $world = $this->world;
        $here = $world->getBlock($x, $y, $z);
        $layers = (int) max(1, min(self::MAX_SNOW_LAYERS, ceil($maxSnow * self::MAX_SNOW_LAYERS)));

        if($here->getName() === BlockIds::SNOW_LAYER){
            $height = $here->getProperty(BlockProperties::HEIGHT);

            if($height + 1 < $layers){
                $world->setBlock($x, $y, $z, $here->withProperty(BlockProperties::HEIGHT, $height + 1));
            }

            return;
        }

        if($here->getName() !== BlockIds::AIR){
            return;
        }

        $ground = $world->getBlock($x, $y - 1, $z);
        $groundName = $ground->getName();

        if(!str_ends_with($groundName, "leaves") && (!BlockCollision::isFullCube($ground) || in_array($groundName, self::NO_SNOW_ON, true))){
            return;
        }

        $world->setBlock($x, $y, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::SNOW_LAYER));
    }
}