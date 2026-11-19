<?php

declare(strict_types=1);

namespace raspberry\world;

use raspberry\block\Beds;

use raspberry\network\mcpe\protocol\LevelEventGenericPacket;
use raspberry\network\mcpe\protocol\types\LevelEvent;
use raspberry\network\mcpe\serializer\NetworkNbtWriter;

use raspberry\player\GameMode;
use raspberry\player\Player;

use function ceil;
use function max;
use function strlen;
use function substr;

/**
 * Keeps track of who is asleep in a world and skips the night once enough of them are.
 */
class Sleeping{

    /** how long the sleepers have to be in bed before the night goes by */
    public const int SKIP_TICKS = 100;

    private World $world;

    /** what the players last saw on the sleeping indicator */
    private int $shownSleeping = 0;

    private int $shownPlayers = 0;

    public function __construct(World $world){
        $this->world = $world;
    }

    public function tick() : void{
        $players = $this->world->getPlayers();

        if($players === [] && $this->shownSleeping === 0){
            return;
        }

        $counted = 0;
        $sleeping = 0;
        $deepAsleep = 0;
        $canSleep = Beds::canSleepNow($this->world);

        foreach($players as $player){
            $bed = $player->getSleepingPosition();

            if($bed !== null && (!$canSleep || !Beds::isBedHead($this->world, $bed->getFloorX(), $bed->getFloorY(), $bed->getFloorZ()))){
                $player->wake();
                $bed = null;
            }

            if($player->getGameMode() === GameMode::SPECTATOR){
                continue;
            }

            ++$counted;

            if($bed !== null){
                ++$sleeping;

                if($player->getSleepTicks() >= self::SKIP_TICKS){
                    ++$deepAsleep;
                }
            }
        }

        $this->showIndicator($sleeping, $counted);

        if($sleeping > 0 && $deepAsleep >= $this->getNeeded($counted)){
            $this->skipNight();
        }
    }

    /**
     * How many players have to be asleep to skip the night, from the playerssleepingpercentage
     * game rule. Above 100 percent nobody can skip it.
     */
    public function getNeeded(int $players) : int{
        $percentage = $this->world->getGameRules()->getInt(GameRules::PLAYERS_SLEEPING_PERCENTAGE);

        if($percentage > 100){
            return PHP_INT_MAX;
        }

        return max(1, (int) ceil($players * max(0, $percentage) / 100));
    }

    /**
     * Tells the players who are up that someone went to bed, and how many more need to.
     */
    public function onSleep(Player $sleeper) : void{
        $counted = 0;
        $sleeping = 0;

        foreach($this->world->getPlayers() as $player){
            if($player->getGameMode() !== GameMode::SPECTATOR){
                ++$counted;
                $sleeping += $player->isSleeping() ? 1 : 0;
            }
        }

        $needed = $this->getNeeded($counted);

        if($needed === PHP_INT_MAX){
            return;
        }

        $left = max(0, $needed - $sleeping);

        if($left === 0){
            return;
        }

        foreach($this->world->getPlayers() as $player){
            if($player !== $sleeper && !$player->isSleeping()){
                $player->sendTranslation("%chat.type.sleeping", [$sleeper->getName(), (string) $left]);
            }
        }
    }

    /**
     * Morning comes, the weather clears and everyone gets up.
     */
    public function skipNight() : void{
        $rules = $this->world->getGameRules();

        if($rules->getBool(GameRules::DO_DAYLIGHT_CYCLE)){
            $time = $this->world->getTime();
            $this->world->setTime($time + World::DAY_LENGTH - $time % World::DAY_LENGTH);
        }

        $weather = $this->world->getWeather();

        if($rules->getBool(GameRules::DO_WEATHER_CYCLE) && ($weather->isRaining() || $weather->isThundering())){
            $weather->setClear(0);
        }

        foreach($this->world->getPlayers() as $player){
            $player->wake();
        }

        $this->showIndicator(0, $this->shownPlayers);
    }

    private function showIndicator(int $sleeping, int $players) : void{
        if($sleeping === $this->shownSleeping && ($sleeping === 0 || $players === $this->shownPlayers)){
            return;
        }

        $this->shownSleeping = $sleeping;
        $this->shownPlayers = $players;

        $writer = new NetworkNbtWriter();
        $writer->startCompound();
        $writer->writeInt("ableToSleep", $players);
        $writer->writeInt("overworldPlayerCount", $players);
        $writer->writeInt("sleepingPlayerCount", $sleeping);
        $writer->endCompound();

        $buffer = $writer->getBuffer();
        $packet = LevelEventGenericPacket::create(LevelEvent::SLEEPING_PLAYERS, substr($buffer, 2, strlen($buffer) - 3));

        foreach($this->world->getPlayers() as $player){
            $player->getNetworkSession()->sendDataPacket($packet);
        }
    }
}