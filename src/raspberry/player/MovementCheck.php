<?php

declare(strict_types=1);

namespace raspberry\player;

use raspberry\block\BlockCollision;
use raspberry\block\BlockIds;

use raspberry\entity\effect\Effect;

use raspberry\item\enchantment\Enchantment;

use raspberry\math\Vector3;

use raspberry\world\Chunk;

use function floor;
use function max;
use function min;
use function sqrt;

class MovementCheck{

    public const int OK = 0;

    public const int CORRECT = 1;

    public const int FLYING = 2;

    private const float BASE_SPEED = 0.7;

    private const float BURST = 2.0;

    private const float JUMP_HEIGHT = 1.25;
    private const float RISE_LEEWAY = 0.5;

    private const int FLOAT_TICKS = 80;

    public const int GRACE_TICKS = 5;

    private Player $player;

    private float $allowance = self::BURST;

    private float $groundY;

    private int $floatTicks = 0;

    private int $graceTicks = self::GRACE_TICKS;

    private float $extraRise = 0.0;

    private float $extraSpeed = 0.0;

    /** how long a bounce keeps its extra rise when the client still says it is on the ground */
    private int $bounceTicks = 0;

    private int $iceTicks = 1000;

    private int $lastTick = -1;

    public function __construct(Player $player){
        $this->player = $player;
        $this->groundY = $player->getPosition()->y;
    }

    /**
     * After a teleport, respawn or a change of game mode: the next moves start from scratch,
     * the first $grace ticks of them unchecked.
     */
    public function reset(int $grace = self::GRACE_TICKS) : void{
        $this->allowance = self::BURST;
        $this->groundY = $this->player->getPosition()->y;
        $this->floatTicks = 0;
        $this->graceTicks = $grace;
        $this->extraRise = 0.0;
        $this->extraSpeed = 0.0;
        $this->bounceTicks = 0;
    }

    /**
     * Something pushed the player: knockback, an explosion, a bounce.
     */
    public function onPushed(Vector3 $motion) : void{
        $horizontal = sqrt($motion->x * $motion->x + $motion->z * $motion->z);
        $this->extraSpeed = max($this->extraSpeed, $horizontal * 10);
        $this->extraRise = max($this->extraRise, max(0.0, $motion->y) * 8);
        $this->floatTicks = 0;
    }

    /**
     * The player bounced off a slime block or a bed and goes back up about $height blocks.
     */
    public function onBounce(float $height) : void{
        $this->extraRise = max($this->extraRise, $height);
        $this->bounceTicks = 2;
        $this->floatTicks = 0;
    }

    /**
     * @param int  $tick     the client's input tick
     * @param bool $onGround the client stands on something
     * @param bool $gliding  the client glides with an elytra
     */
    public function check(Vector3 $from, Vector3 $to, int $tick, bool $onGround, bool $gliding, bool $allowFlying) : int{
        $ticks = $this->lastTick < 0 ? 1 : max(1, min(20, $tick - $this->lastTick));
        $this->lastTick = $tick;

        $player = $this->player;
        $mode = $player->getGameMode();

        if($mode->allowsFlying() || $player->isFlying() || $gliding){
            $this->reset(0);
            return self::OK;
        }

        if($this->graceTicks > 0){
            $this->graceTicks -= $ticks;
            $this->groundY = min($this->groundY, $to->y);
            return self::OK;
        }

        $surroundings = $this->surroundings($to);

        if($onGround || $surroundings["liquid"] || $surroundings["climbable"] || $surroundings["web"]){
            $this->groundY = $to->y;

            if($this->bounceTicks <= 0){
                $this->extraRise = 0.0;
            }
        }

        $this->bounceTicks -= $ticks;

        if($surroundings["ice"]){
            $this->iceTicks = 0;
        }else{
            $this->iceTicks += $ticks;
        }

        $effects = $player->getEffects();

        $dx = $to->x - $from->x;
        $dz = $to->z - $from->z;
        $moved = sqrt($dx * $dx + $dz * $dz);
        $limit = self::BASE_SPEED * (1 + 0.2 * $effects->getLevel(Effect::SPEED));

        if($this->iceTicks < 40){
            $limit *= 2.5;
        }

        if($surroundings["liquid"]){
            $boots = $player->getArmorInventory()->getBoots();
            $limit *= 1 + 0.5 * $boots->getEnchantmentLevel(Enchantment::DEPTH_STRIDER);
        }

        if($surroundings["soul"]){
            $limit *= 1 + 0.4 * $player->getArmorInventory()->getBoots()->getEnchantmentLevel(Enchantment::SOUL_SPEED);
        }

        $this->allowance = min(self::BURST, $this->allowance + $limit * $ticks + $this->extraSpeed) - $moved;
        $this->extraSpeed *= 0.8;

        if($this->allowance < 0){
            $this->allowance = 0.0;
            return self::CORRECT;
        }

        $rise = $to->y - $this->groundY;
        $maxRise = self::JUMP_HEIGHT + 0.75 * $effects->getLevel(Effect::JUMP_BOOST) + self::RISE_LEEWAY + $this->extraRise;

        if($rise > $maxRise && !$effects->has(Effect::LEVITATION)){
            return self::CORRECT;
        }

        if($mode->hasCollision() && $this->collides($to) && !$this->collides($from)){
            return self::CORRECT;
        }

        $falls = $to->y - $from->y < -0.01;

        if($onGround || $falls || $surroundings["liquid"] || $surroundings["climbable"] || $surroundings["web"] || $effects->has(Effect::LEVITATION) || $effects->has(Effect::SLOW_FALLING) || $this->standsNear($to)){
            $this->floatTicks = 0;
        }elseif(($this->floatTicks += $ticks) > self::FLOAT_TICKS){
            $this->floatTicks = 0;
            return $allowFlying ? self::OK : self::FLYING;
        }

        return self::OK;
    }

    /**
     * @return array{liquid: bool, climbable: bool, web: bool, ice: bool, soul: bool}
     */
    private function surroundings(Vector3 $position) : array{
        $found = ["liquid" => false, "climbable" => false, "web" => false, "ice" => false, "soul" => false];
        $world = $this->player->getWorld();
        $x = (int) floor($position->x);
        $z = (int) floor($position->z);

        foreach([-1, 0, 1, 2] as $dy){
            $y = (int) floor($position->y) + $dy;

            if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y || !$world->isChunkLoaded($x >> 4, $z >> 4)){
                continue;
            }

            $name = $world->getBlock($x, $y, $z)->getName();

            match(true){
                $name === BlockIds::WATER, $name === BlockIds::FLOWING_WATER, $name === BlockIds::LAVA, $name === BlockIds::FLOWING_LAVA, $name === BlockIds::BUBBLE_COLUMN => $found["liquid"] = $dy >= 0 || $found["liquid"],
                $name === BlockIds::LADDER, $name === BlockIds::VINE, $name === BlockIds::SCAFFOLDING, $name === BlockIds::WEEPING_VINES, $name === BlockIds::TWISTING_VINES, $name === BlockIds::POWDER_SNOW, $name === BlockIds::CAVE_VINES, $name === BlockIds::CAVE_VINES_BODY_WITH_BERRIES, $name === BlockIds::CAVE_VINES_HEAD_WITH_BERRIES => $found["climbable"] = $dy >= 0 || $found["climbable"],
                $name === BlockIds::WEB => $found["web"] = true,
                $name === BlockIds::ICE, $name === BlockIds::PACKED_ICE, $name === BlockIds::BLUE_ICE, $name === BlockIds::FROSTED_ICE => $found["ice"] = $dy === -1 || $found["ice"],
                $name === BlockIds::SOUL_SAND, $name === BlockIds::SOUL_SOIL => $found["soul"] = $dy === -1 || $found["soul"],
                default => null
            };
        }

        return $found;
    }

    private function standsNear(Vector3 $position) : bool{
        $world = $this->player->getWorld();
        $y = (int) floor($position->y - 0.6);

        if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y){
            return false;
        }

        foreach([-0.4, 0.4] as $ox){
            foreach([-0.4, 0.4] as $oz){
                $x = (int) floor($position->x + $ox);
                $z = (int) floor($position->z + $oz);

                if($world->isChunkLoaded($x >> 4, $z >> 4) && BlockCollision::isSolid($world->getBlock($x, $y, $z))){
                    return true;
                }

                if($world->isChunkLoaded($x >> 4, $z >> 4) && BlockCollision::isSolid($world->getBlock($x, (int) floor($position->y - 0.05), $z))){
                    return true;
                }
            }
        }

        return false;
    }

    private function collides(Vector3 $position) : bool{
        $player = $this->player;
        $world = $player->getWorld();
        $height = $player->isSwimming() ? 0.6 : ($player->isSneaking() ? 1.5 : 1.8);
        $half = 0.3 - 0.1;

        for($x = (int) floor($position->x - $half); $x <= (int) floor($position->x + $half); ++$x){
            for($z = (int) floor($position->z - $half); $z <= (int) floor($position->z + $half); ++$z){
                if(!$world->isChunkLoaded($x >> 4, $z >> 4)){
                    continue;
                }

                for($y = (int) floor($position->y + 0.1); $y <= (int) floor($position->y + $height - 0.1); ++$y){
                    if($y >= Chunk::MIN_Y && $y <= Chunk::MAX_Y && BlockCollision::isFullCube($world->getBlock($x, $y, $z))){
                        return true;
                    }
                }
            }
        }

        return false;
    }
}