<?php

declare(strict_types=1);

namespace raspberry\event\block;

use raspberry\block\BlockState;

use raspberry\math\Vector3;

use raspberry\player\Player;

use raspberry\world\World;

/**
 * Fire is about to appear in a block: it spread, lava or lightning lit it, or a player used
 * flint and steel or a fire charge.
 */
class BlockIgniteEvent extends BlockChangeEvent{

    public const CAUSE_SPREAD = "spread";
    public const CAUSE_LAVA = "lava";
    public const CAUSE_LIGHTNING = "lightning";
    public const CAUSE_FLINT_AND_STEEL = "flint_and_steel";
    public const CAUSE_FIRE_CHARGE = "fire_charge";
    public const CAUSE_PLUGIN = "plugin";

    private string $cause;

    private ?Player $player;

    private ?Vector3 $source;

    public function __construct(World $world, int $x, int $y, int $z, BlockState $newState, string $cause = self::CAUSE_PLUGIN, ?Player $player = null, ?Vector3 $source = null){
        parent::__construct($world, $x, $y, $z, $newState);
        $this->cause = $cause;
        $this->player = $player;
        $this->source = $source;
    }

    /**
     * Sets the fire unless a listener cancels it.
     */
    public static function ignite(World $world, int $x, int $y, int $z, BlockState $fire, string $cause, ?Player $player = null, ?Vector3 $source = null) : bool{
        if(!self::hasHandlers()){
            $world->setBlock($x, $y, $z, $fire);
            return true;
        }

        $event = new self($world, $x, $y, $z, $fire, $cause, $player, $source);
        $event->call();

        if($event->isCancelled()){
            return false;
        }

        $world->setBlock($x, $y, $z, $event->getNewState());

        return true;
    }

    /**
     * One of the CAUSE_ constants.
     */
    public function getCause() : string{
        return $this->cause;
    }

    /**
     * The player who lit it, for flint and steel and fire charges.
     */
    public function getPlayer() : ?Player{
        return $this->player;
    }

    /**
     * The fire or lava it came from, when it spread.
     */
    public function getSource() : ?Vector3{
        return $this->source;
    }
}