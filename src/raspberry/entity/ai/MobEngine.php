<?php

declare(strict_types=1);

namespace raspberry\entity\ai;

use raspberry\entity\Mob;

use raspberry\math\Vector3;

use raspberry\native\Native;

use raspberry\player\GameMode;

use raspberry\world\Chunk;
use raspberry\world\World;

use FFI;

use function array_values;
use function count;
use function mt_rand;
use function pack;
use function strlen;
use function unpack;

class MobEngine{

    private const COMMAND_ADD = 1;
    private const COMMAND_REMOVE = 2;
    private const COMMAND_UPDATE = 3;
    private const COMMAND_TELEPORT = 4;
    private const COMMAND_MOTION = 5;
    private const COMMAND_HURT = 6;
    private const COMMAND_PLAYERS = 7;

    private const PLAYER_SPECTATOR = 1;

    private const MOVE_SIZE = 48;
    private const EVENT_SIZE = 36;

    private FFI $ffi;

    private World $world;

    private int $handle;

    /** @var array<int, Mob> */
    private array $mobs = [];

    /** @var array<int, Mob> */
    private array $added = [];

    /** @var array<int, Mob> */
    private array $updated = [];

    private string $commands = "";

    private bool $ticking = false;

    private bool $closed = false;

    public function __construct(World $world){
        $this->ffi = Native::get();
        $this->world = $world;
        $this->handle = $this->ffi->rx_mobs_new(mt_rand());
    }

    public function close() : void{
        if(!$this->closed){
            $this->closed = true;
            $this->ffi->rx_mobs_free($this->handle);
        }
    }

    public function setChunk(int $chunkX, int $chunkZ, ?Chunk $chunk) : void{
        $this->ffi->rx_mobs_chunk($this->handle, $chunkX, $chunkZ, $chunk?->getHandle() ?? 0);
    }

    public function add(Mob $mob) : void{
        $this->mobs[$mob->getId()] = $mob;
        $this->added[$mob->getId()] = $mob;
    }

    public function remove(Mob $mob) : void{
        $id = $mob->getId();

        if(!isset($this->mobs[$id])){
            return;
        }

        if(!isset($this->added[$id])){
            $this->commands .= pack("CP", self::COMMAND_REMOVE, $id);
        }

        unset($this->mobs[$id], $this->added[$id], $this->updated[$id]);
    }

    /**
     * The mob's size, speed or flags changed.
     */
    public function update(Mob $mob) : void{
        if(isset($this->mobs[$mob->getId()])){
            $this->updated[$mob->getId()] = $mob;
        }
    }

    public function teleport(Mob $mob) : void{
        if(isset($this->mobs[$mob->getId()]) && !isset($this->added[$mob->getId()])){
            $position = $mob->getPosition();
            $this->commands .= pack("CPeeeg", self::COMMAND_TELEPORT, $mob->getId(), $position->x, $position->y, $position->z, $mob->getYaw());
        }
    }

    public function setMotion(Mob $mob, Vector3 $motion) : void{
        if(isset($this->mobs[$mob->getId()])){
            $this->commands .= pack("CPggg", self::COMMAND_MOTION, $mob->getId(), $motion->x, $motion->y, $motion->z);
        }
    }

    public function hurt(Mob $mob) : void{
        if(isset($this->mobs[$mob->getId()])){
            $this->commands .= pack("CP", self::COMMAND_HURT, $mob->getId());
        }
    }

    public function tick() : void{
        if($this->closed || $this->ticking || ($this->mobs === [] && $this->commands === "")){
            return;
        }

        $input = "";

        foreach($this->added as $mob){
            $position = $mob->getPosition();
            $behaviors = array_values($mob->getBehaviors());

            $input .= pack("CPVeeeggggVC", self::COMMAND_ADD, $mob->getId(), $mob->getKind(), $position->x, $position->y, $position->z, $mob->getYaw(), $mob->getWidth(), $mob->getHeight(), $mob->getMovementSpeed(), $mob->getEngineFlags(), count($behaviors));

            foreach($behaviors as $behavior){
                $input .= $behavior->encode();
            }
        }
        $input .= $this->commands;

        foreach($this->updated as $id => $mob){
            if(!isset($this->added[$id])){
                $input .= pack("CPgggV", self::COMMAND_UPDATE, $id, $mob->getWidth(), $mob->getHeight(), $mob->getMovementSpeed(), $mob->getEngineFlags());
            }
        }

        $players = "";
        $playerCount = 0;

        foreach($this->world->getPlayers() as $player){
            if(!$player->isSpawned() || $player->isDead()){
                continue;
            }

            $position = $player->getPosition();
            $flags = $player->getGameMode() === GameMode::SPECTATOR ? self::PLAYER_SPECTATOR : 0;

            $players .= pack("PeeegVP", $player->getId(), $position->x, $position->y, $position->z, $player->getEyeHeight(), $flags, TemptItems::mask($player->getInventory()->getItemInHand()->getId()));
            ++$playerCount;
        }

        $input .= pack("CV", self::COMMAND_PLAYERS, $playerCount) . $players;

        $work = $this->ffi->rx_mobs_tick($this->handle, $input, strlen($input));

        if($work === 0){
            return;
        }

        $this->added = [];
        $this->updated = [];
        $this->commands = "";
        $this->ticking = true;

        Native::await($work, function(string $result) : void{
            $this->ticking = false;

            if(!$this->closed){
                $this->handleResult($result);
            }
        });
    }

    private function handleResult(string $result) : void{
        $offset = 4;

        for($left = unpack("V", $result)[1]; $left > 0; --$left, $offset += self::MOVE_SIZE){
            $move = unpack("Pid/ex/ey/ez/gyaw/ghead/gpitch/Vstate", $result, $offset);

            ($this->mobs[$move["id"]] ?? null)?->onEngineMove($move["x"], $move["y"], $move["z"], $move["yaw"], $move["head"], $move["pitch"], $move["state"]);
        }

        $left = unpack("V", $result, $offset)[1];

        for($offset += 4; $left > 0; --$left, $offset += self::EVENT_SIZE){
            $event = unpack("Pid/Vkind/gvalue/lx/ly/lz/Pother", $result, $offset);
            $mob = $this->mobs[$event["id"]] ?? null;

            if($mob !== null && $mob->isAlive()){
                $mob->onEngineEvent($event["kind"], $event["value"], $event["x"], $event["y"], $event["z"], $event["other"]);
            }
        }

        $unknown = unpack("V", $result, $offset)[1];

        if($unknown > 0){
            MobBlocks::teach(array_values(unpack("V{$unknown}", $result, $offset + 4)));
        }
    }

    public function getMob(int $id) : ?Mob{
        return $this->mobs[$id] ?? null;
    }
}