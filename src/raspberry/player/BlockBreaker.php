<?php

declare(strict_types=1);

namespace raspberry\player;

use raspberry\block\BlockBreakInfo;
use raspberry\block\BlockIds;
use raspberry\block\BlockProperties;
use raspberry\block\BlockState;
use raspberry\block\StairsShape;

use raspberry\item\Item;
use raspberry\item\ToolInfo;
use raspberry\item\enchantment\Enchantment;

use raspberry\math\Facing;
use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\Packet;
use raspberry\network\mcpe\protocol\AnimatePacket;
use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\world\Chunk;

use function floor;
use function intdiv;
use function max;
use function mt_rand;

/**
 * A player breaking blocks. The client says when it starts, stops and finishes breaking; the
 * server checks the block is in reach and that the player took long enough for the block and
 * the tool in hand, then breaks it, drops its items and wears the tool.
 */
class BlockBreaker{

    /** how far from the eyes blocks can be broken */
    public const REACH = 8.0;
    public const CREATIVE_REACH = 14.0;

    /** finishing this share of the break time is accepted (the client's ticks can bunch up) */
    public const TIME_TOLERANCE = 0.8;

    /** exhaustion per block broken */
    public const EXHAUSTION = 0.005;

    private const HIT_SOUND_TICKS = 4;

    /** how often other players see the arm swing while breaking */
    private const SWING_TICKS = 5;

    private Player $player;

    /** @var array{int, int, int}|null the block being broken */
    private ?array $target = null;

    /** input ticks when breaking started, and how many it should take */
    private int $startTick = 0;
    private int $breakTicks = 0;

    /** counts the client's input packets, one per client tick */
    private int $inputTicks = 0;

    public function __construct(Player $player){
        $this->player = $player;
    }

    public function isBreaking(int $x, int $y, int $z) : bool{
        return $this->target === [$x, $y, $z];
    }

    /**
     * Called for every PlayerAuthInput.
     */
    public function onInputTick() : void{
        ++$this->inputTicks;

        if($this->target !== null && ($this->inputTicks - $this->startTick) % self::HIT_SOUND_TICKS === 0){
            [$x, $y, $z] = $this->target;
            $block = $this->player->getWorld()->getBlock($x, $y, $z);

            $this->broadcast(LevelSoundEventPacket::create(LevelSoundEvent::HIT, $x + 0.5, $y + 0.5, $z + 0.5, $this->getNetworkId($block)), $x, $z);
        }

        if($this->target !== null && ($this->inputTicks - $this->startTick) % self::SWING_TICKS === 0){
            $this->player->swingArm(AnimatePacket::SWING_MINE);
        }
    }

    public function start(int $x, int $y, int $z) : void{
        $this->abort();

        $block = $this->getBreakableBlock($x, $y, $z);

        if($block === null){
            return;
        }

        $this->target = [$x, $y, $z];
        $this->startTick = $this->inputTicks;
        $this->player->swingArm(AnimatePacket::SWING_MINE);

        if($this->player->getGameMode()->hasInstantBuild()){
            $this->breakTicks = 0;
            return;
        }

        $this->breakTicks = BlockBreakInfo::get($block->getName())->getBreakTicks($this->player->getInventory()->getItemInHand(), $block->getName());

        if($this->breakTicks > 0){
            $this->broadcast(LevelEventPacket::create(LevelEventPacket::START_BLOCK_CRACKING, $x, $y, $z, intdiv(65535, $this->breakTicks)), $x, $z);
        }
    }

    public function abort() : void{
        if($this->target === null){
            return;
        }

        [$x, $y, $z] = $this->target;
        $this->target = null;

        if($this->breakTicks > 0){
            $this->broadcast(LevelEventPacket::create(LevelEventPacket::STOP_BLOCK_CRACKING, $x, $y, $z), $x, $z);
        }
    }

    /**
     * The client says the block broke. It is broken when the player was breaking that block
     * long enough, else the client gets the block back.
     */
    public function finish(int $x, int $y, int $z) : void{
        $world = $this->player->getWorld();

        if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y || !$world->isChunkLoaded($x >> 4, $z >> 4)){
            return;
        }

        if($world->getBlock($x, $y, $z)->getName() === BlockIds::AIR){
            $this->abort();
            return; //already broken, the client sent more than one finish
        }

        $creative = $this->player->getGameMode()->hasInstantBuild();
        $breaking = $this->target === [$x, $y, $z];
        $elapsed = $this->inputTicks - $this->startTick;

        if(!$breaking && $creative){
            $this->start($x, $y, $z);
            $breaking = $this->target === [$x, $y, $z];
        }

        $reason = match(true){
            !$breaking => "it was not being broken",
            !$creative && $elapsed < (int) floor($this->breakTicks * self::TIME_TOLERANCE) => "it took {$elapsed} ticks, it needs {$this->breakTicks}",
            default => null
        };

        $this->abort();

        if($reason !== null){
            $this->player->getNetworkSession()->getServer()->getLogger()->debug("{$this->player->getName()} could not break the block at {$x} {$y} {$z}: {$reason}");
            $this->player->getNetworkSession()->sendBlock($x, $y, $z, $world->getBlock($x, $y, $z));
            return;
        }

        $this->breakBlock($x, $y, $z);
    }

    /**
     * The block if this player may break it now, else null (and the client gets it back).
     */
    private function getBreakableBlock(int $x, int $y, int $z) : ?BlockState{
        $player = $this->player;
        $world = $player->getWorld();

        if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y || !$world->isChunkLoaded($x >> 4, $z >> 4)){
            return null;
        }

        $block = $world->getBlock($x, $y, $z);
        $gameMode = $player->getGameMode();
        $reach = $gameMode->hasInstantBuild() ? self::CREATIVE_REACH : self::REACH;

        $allowed = $block->getName() !== BlockIds::AIR &&
            !$player->isDead() &&
            $gameMode->allowsBuilding() &&
            $player->getEyePosition()->distance(new Vector3($x + 0.5, $y + 0.5, $z + 0.5)) <= $reach &&
            ($gameMode->hasInstantBuild() ? ToolInfo::getType($player->getInventory()->getItemInHand()->getId()) !== "sword" : BlockBreakInfo::get($block->getName())->isBreakable());

        if(!$allowed){
            if($block->getName() !== BlockIds::AIR){
                $player->getNetworkSession()->sendBlock($x, $y, $z, $block);
            }

            return null;
        }

        return $block;
    }

    private function breakBlock(int $x, int $y, int $z) : void{
        $player = $this->player;
        $world = $player->getWorld();
        $block = $world->getBlock($x, $y, $z);
        $info = BlockBreakInfo::get($block->getName());
        $held = $player->getInventory()->getItemInHand();
        $entity = $world->getBlockEntity($x, $y, $z);

        $world->setBlock($x, $y, $z, $this->air());
        $particles = LevelEventPacket::create(LevelEventPacket::PARTICLES_DESTROY_BLOCK, $x + 0.5, $y + 0.5, $z + 0.5, $this->getNetworkId($block));
        $this->broadcast($particles, $x, $z);

        //in survival the client plays the break sound itself, in creative it waits for the server
        if($player->getGameMode()->hasInstantBuild()){
            $player->getNetworkSession()->sendDataPacket($particles);
        }

        //the other half of a door, tall plant or bed goes with it
        $other = $this->getOtherHalf($block, $x, $y, $z);

        if($other !== null){
            [$ox, $oy, $oz] = $other;

            if($oy >= Chunk::MIN_Y && $oy <= Chunk::MAX_Y && $world->isChunkLoaded($ox >> 4, $oz >> 4) && $world->getBlock($ox, $oy, $oz)->getName() === $block->getName()){
                $world->setBlock($ox, $oy, $oz, $this->air());
            }
        }

        if($block->hasProperty(BlockProperties::MINECRAFT_CORNER)){
            StairsShape::updateAround($world, $x, $y, $z);
        }

        foreach($entity?->onBreak($world) ?? [] as $content){
            $world->dropItem(new Vector3($x + 0.5, $y + 0.5, $z + 0.5), $content);
        }

        if($player->getGameMode()->hasInstantBuild()){
            return;
        }

        $drops = $info->getDrops($block, $held);

        foreach($entity?->getDrops($drops) ?? $drops as $drop){
            $world->dropItem(new Vector3($x + 0.5, $y + 0.5, $z + 0.5), $drop);
        }

        $player->exhaust(self::EXHAUSTION);

        if(!$info->breaksInstantly()){
            $this->wearTool(ToolInfo::getType($held->getId()) === "sword" ? 2 : 1);
        }
    }

    /**
     * @return array{int, int, int}|null
     */
    private function getOtherHalf(BlockState $block, int $x, int $y, int $z) : ?array{
        if($block->hasProperty(BlockProperties::UPPER_BLOCK_BIT)){
            return [$x, $block->getProperty(BlockProperties::UPPER_BLOCK_BIT) === true ? $y - 1 : $y + 1, $z];
        }

        if($block->hasProperty(BlockProperties::HEAD_PIECE_BIT)){
            $facing = $block->hasProperty(BlockProperties::DIRECTION) ?
                Facing::fromLegacyDirection((int) $block->getProperty(BlockProperties::DIRECTION)) :
                Facing::fromName((string) $block->getProperty(BlockProperties::MINECRAFT_CARDINAL_DIRECTION));
            [$dx, , $dz] = Facing::offset($facing);

            return $block->getProperty(BlockProperties::HEAD_PIECE_BIT) === true ? [$x - $dx, $y, $z - $dz] : [$x + $dx, $y, $z + $dz];
        }

        return null;
    }

    /**
     * Tools lose durability when used; Unbreaking makes that less likely. A tool that runs out breaks.
     */
    private function wearTool(int $amount) : void{
        $inventory = $this->player->getInventory();
        $item = $inventory->getItemInHand();

        if(!$item->hasDurability()){
            return;
        }

        $unbreaking = $item->getEnchantmentLevel(Enchantment::UNBREAKING);

        if($unbreaking > 0 && mt_rand(0, $unbreaking) !== 0){
            return;
        }

        $damage = $item->getDamage() + $amount;

        if($damage >= $item->getMaxDurability()){
            $inventory->setItemInHand(Item::air());
            $position = $this->player->getPosition();
            $this->player->getNetworkSession()->sendDataPacket(LevelSoundEventPacket::create(LevelSoundEvent::BREAK, $position->x, $position->y, $position->z));
            return;
        }

        $inventory->setItemInHand($item->setDamage(max(0, $damage)));
    }

    private function air() : BlockState{
        return $this->player->getNetworkSession()->getServer()->getBlockStateRegistry()->getDefaultState(BlockIds::AIR);
    }

    private function getNetworkId(BlockState $block) : int{
        return $this->player->getNetworkSession()->getServer()->getBlockTranslator()->getNetworkId($block);
    }

    /**
     * Sends a packet to the other players who can see the block.
     */
    private function broadcast(Packet $packet, int $x, int $z) : void{
        $this->player->getWorld()->broadcastPacket($x, $z, $packet, $this->player);
    }
}