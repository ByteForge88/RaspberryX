<?php

declare(strict_types=1);

namespace raspberry\player;

use raspberry\block\BlockBreakInfo;
use raspberry\block\BlockIds;
use raspberry\block\BlockProperties;
use raspberry\block\BlockState;
use raspberry\block\StairsShape;
use raspberry\block\entity\Lectern;

use raspberry\item\Item;
use raspberry\item\ToolInfo;
use raspberry\item\enchantment\Enchantment;

use raspberry\math\Facing;
use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\Packet;
use raspberry\network\mcpe\protocol\AnimatePacket;
use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelEvent;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\world\Chunk;

use function intdiv;
use function max;
use function min;
use function mt_rand;

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

    /** @var array{int, int, int}|null */
    private ?array $target = null;

    private int $startTick = 0;

    /** redone every tick, so switching tools counts */
    private int $breakTicks = 0;

    /** 0 to 1 */
    private float $progress = 0.0;

    /** the face the particles come off */
    private int $face = Facing::UP;

    /** counts the client's input packets, one per client tick */
    private int $inputTicks = 0;

    /** @var array{int, int, int, int}|null */
    private ?array $tookBook = null;

    public function __construct(Player $player){
        $this->player = $player;
    }

    public function isBreaking(int $x, int $y, int $z) : bool{
        return $this->target === [$x, $y, $z];
    }

    public function onInputTick() : void{
        ++$this->inputTicks;

        if($this->target === null){
            return;
        }

        [$x, $y, $z] = $this->target;
        $world = $this->player->getWorld();
        $block = $world->getBlock($x, $y, $z);

        if($block->getName() === BlockIds::AIR){
            $this->abort();
            return;
        }

        if(!$this->player->getGameMode()->hasInstantBuild()){
            $ticks = BlockBreakInfo::get($block->getName())->getBreakTicks($this->player->getInventory()->getItemInHand(), $block->getName(), $this->player->getEffects()->getBreakSpeedMultiplier());

            if($ticks !== $this->breakTicks){
                $this->breakTicks = $ticks;

                if($ticks > 0){
                    $this->broadcast(LevelEventPacket::create(LevelEvent::UPDATE_BLOCK_CRACKING, $x, $y, $z, intdiv(65535, $ticks)), $x, $z);
                }
            }

            $this->progress = $ticks > 0 ? min(1.0, $this->progress + 1 / $ticks) : 1.0;

            $world->broadcastPacket($x, $z, LevelEventPacket::create(LevelEvent::PARTICLES_CRACK_BLOCK_DOWN + $this->face, $x, $y, $z, $this->getNetworkId($block)));
        }

        if(($this->inputTicks - $this->startTick) % self::HIT_SOUND_TICKS === 0){
            $this->broadcast(LevelSoundEventPacket::create(LevelSoundEvent::HIT, $x + 0.5, $y + 0.5, $z + 0.5, $this->getNetworkId($block)), $x, $z);
        }

        if(($this->inputTicks - $this->startTick) % self::SWING_TICKS === 0){
            $this->player->swingArm(AnimatePacket::SWING_MINE);
        }
    }

    public function setFace(int $face) : void{
        if($face >= Facing::DOWN && $face <= Facing::EAST){
            $this->face = $face;
        }
    }

    public function start(int $x, int $y, int $z, int $face = Facing::UP) : void{
        $this->abort();

        $block = $this->getBreakableBlock($x, $y, $z);

        if($block === null || $this->takeBook($x, $y, $z)){
            return;
        }

        $this->target = [$x, $y, $z];
        $this->startTick = $this->inputTicks;
        $this->progress = 0.0;
        $this->setFace($face);
        $this->player->swingArm(AnimatePacket::SWING_MINE);

        if($this->player->getGameMode()->hasInstantBuild()){
            $this->breakTicks = 0;
            $this->progress = 1.0;
            return;
        }

        $this->breakTicks = BlockBreakInfo::get($block->getName())->getBreakTicks($this->player->getInventory()->getItemInHand(), $block->getName(), $this->player->getEffects()->getBreakSpeedMultiplier());

        if($this->breakTicks > 0){
            $this->broadcast(LevelEventPacket::create(LevelEvent::START_BLOCK_CRACKING, $x, $y, $z, intdiv(65535, $this->breakTicks)), $x, $z);
        }else{
            $this->progress = 1.0;
        }
    }

    public function abort() : void{
        if($this->target === null){
            return;
        }

        [$x, $y, $z] = $this->target;
        $this->target = null;

        if($this->breakTicks > 0){
            $this->broadcast(LevelEventPacket::create(LevelEvent::STOP_BLOCK_CRACKING, $x, $y, $z), $x, $z);
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

        //hitting a lectern takes its book, the lectern stays
        if($this->tookBook !== null && [$this->tookBook[0], $this->tookBook[1], $this->tookBook[2]] === [$x, $y, $z] && $this->inputTicks - $this->tookBook[3] <= 10){
            $this->player->getNetworkSession()->sendBlock($x, $y, $z, $world->getBlock($x, $y, $z));
            return;
        }

        $creative = $this->player->getGameMode()->hasInstantBuild();
        $breaking = $this->target === [$x, $y, $z];

        if(!$breaking && $creative){
            $this->start($x, $y, $z);
            $breaking = $this->target === [$x, $y, $z];
        }

        $reason = match(true){
            !$breaking => "it was not being broken",
            !$creative && $this->progress < self::TIME_TOLERANCE => "it was only " . (int) ($this->progress * 100) . "% broken",
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

    private function takeBook(int $x, int $y, int $z) : bool{
        $world = $this->player->getWorld();
        $lectern = $world->getBlockEntity($x, $y, $z);
        $book = $lectern instanceof Lectern ? $lectern->getBook() : null;

        if($book === null){
            return false;
        }

        $lectern->setBook(null);
        $world->sendBlockEntity($lectern);
        $world->getRedstoneUpdater()->update($x, $y, $z);
        $this->tookBook = [$x, $y, $z, $this->inputTicks];

        foreach($this->player->getInventory()->addItem($book) as $left){
            $world->dropItem(new Vector3($x + 0.5, $y + 1, $z + 0.5), $left);
        }

        return true;
    }

    private function breakBlock(int $x, int $y, int $z) : void{
        $player = $this->player;
        $world = $player->getWorld();
        $block = $world->getBlock($x, $y, $z);
        $info = BlockBreakInfo::get($block->getName());
        $held = $player->getInventory()->getItemInHand();
        $entity = $world->getBlockEntity($x, $y, $z);

        $world->setBlock($x, $y, $z, $this->air());
        $particles = LevelEventPacket::create(LevelEvent::PARTICLES_DESTROY_BLOCK, $x + 0.5, $y + 0.5, $z + 0.5, $this->getNetworkId($block));
        $this->broadcast($particles, $x, $z);

        if($player->getGameMode()->hasInstantBuild()){
            $player->getNetworkSession()->sendDataPacket($particles);
            $player->getNetworkSession()->sendDataPacket(LevelSoundEventPacket::create(LevelSoundEvent::BREAK, $x + 0.5, $y + 0.5, $z + 0.5, $this->getNetworkId($block)));
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