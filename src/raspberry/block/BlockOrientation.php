<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\math\Facing;

use function preg_match;
use function substr;

class BlockOrientation{

    /** blocks that only go on the side of another block */
    private const WALL_ONLY = '/^(\w*wall_sign|wall_banner|ladder|\w+_coral_wall_fan|tripwire_hook|cocoa)$/D';

    /** blocks that cannot hang under a block */
    private const NOT_ON_CEILING = '/^(\w*torch(_\w+)?|\w*standing_sign|standing_banner|\w+_skull|\w+_head|\w*_door)$/D';

    /** blocks with facing_direction that point out of the clicked face (skulls: 1 standing) */
    private const FACING_CLICKED = '/^(\w*wall_sign|wall_banner|ladder|\w+_button|(glow_)?frame|(\w+_)?lightning_rod|\w+_skull|\w+_head)$/D';

    private function __construct(){
    }

    public static function orient(BlockState $state, int $face, int $facing, int $rotation, float $clickY, int $towardPlayer, BlockState $against) : ?BlockState{
        $name = substr($state->getName(), 10);

        if(
            (preg_match(self::WALL_ONLY, $name) === 1 && !Facing::isHorizontal($face)) ||
            ($face === Facing::DOWN && preg_match(self::NOT_ON_CEILING, $name) === 1) ||
            ($face === Facing::UP && preg_match('/_hanging_sign$/', $name) === 1)
        ){
            return null;
        }

        $top = $face === Facing::DOWN || ($clickY > 0.5 && $face !== Facing::UP);

        foreach($state->getProperties() as $property => $current){
            $value = match($property){
                BlockProperties::PILLAR_AXIS => Facing::axis($face),
                BlockProperties::MINECRAFT_VERTICAL_HALF => $top ? "top" : "bottom",
                BlockProperties::UPSIDE_DOWN_BIT => $top,
                BlockProperties::WEIRDO_DIRECTION => self::toWeirdoDirection($facing),
                BlockProperties::MINECRAFT_CARDINAL_DIRECTION => Facing::name(self::cardinalDirection($name, $facing)),
                BlockProperties::DIRECTION => self::direction($name, $face, $facing),
                BlockProperties::FACING_DIRECTION => self::facingDirection($name, $face, $facing, $towardPlayer, $against),
                BlockProperties::MINECRAFT_FACING_DIRECTION => Facing::name(Facing::opposite($towardPlayer)),
                BlockProperties::MINECRAFT_BLOCK_FACE => Facing::name($face),
                BlockProperties::TORCH_FACING_DIRECTION => $face === Facing::UP ? "top" : Facing::name(Facing::opposite($face)),
                BlockProperties::LEVER_DIRECTION => Facing::isHorizontal($face) ? Facing::name($face) : Facing::name($face) . (Facing::axis($facing) === "x" ? "_east_west" : "_north_south"),
                BlockProperties::GROUND_SIGN_DIRECTION => ($rotation + 8) % 16,
                BlockProperties::RAIL_DIRECTION => Facing::axis($facing) === "z" ? 0 : 1,
                BlockProperties::HANGING => preg_match('/^suspicious_/', $name) === 1 ? $current : $face === Facing::DOWN,
                BlockProperties::CORAL_DIRECTION => match($face){
                    Facing::WEST => 0,
                    Facing::EAST => 1,
                    Facing::NORTH => 2,
                    default => 3
                },
                BlockProperties::ATTACHMENT => match($face){
                    Facing::UP => "standing",
                    Facing::DOWN => "hanging",
                    default => "side"
                },
                BlockProperties::ORIENTATION => Facing::name(Facing::opposite($facing)) . "_up",
                default => $current
            };

            $state = $state->withProperty($property, $value);
        }

        return $state;
    }

    /**
     * Stairs and trapdoors number directions as east 0, west 1, south 2, north 3.
     */
    public static function toWeirdoDirection(int $face) : int{
        return match($face){
            Facing::EAST => 0,
            Facing::WEST => 1,
            Facing::SOUTH => 2,
            default => 3
        };
    }

    public static function fromWeirdoDirection(int $direction) : int{
        return [Facing::EAST, Facing::WEST, Facing::SOUTH, Facing::NORTH][$direction & 3];
    }

    private static function cardinalDirection(string $name, int $facing) : int{
        return match(true){
            preg_match('/_door$/', $name) === 1 => Facing::rotateRight($facing),
            preg_match('/fence_gate$|^straw_bed$/', $name) === 1 => $facing,
            preg_match('/anvil$/', $name) === 1 => Facing::rotateLeft($facing),
            default => Facing::opposite($facing)
        };
    }

    /**
     * The old 0-3 "direction" property.
     */
    private static function direction(string $name, int $face, int $facing) : int{
        return match(true){
            preg_match('/trapdoor$/', $name) === 1 => self::toWeirdoDirection(Facing::opposite($facing)),
            $name === "bed" => Facing::toLegacyDirection($facing),
            $name === "cocoa" => Facing::toLegacyDirection(Facing::opposite($face)),
            $name === "tripwire_hook" || (($name === "grindstone" || $name === "bell") && Facing::isHorizontal($face)) => Facing::toLegacyDirection($face),
            default => Facing::toLegacyDirection(Facing::opposite($facing))
        };
    }

    private static function facingDirection(string $name, int $face, int $facing, int $towardPlayer, BlockState $against) : int{
        return match(true){
            preg_match(self::FACING_CLICKED, $name) === 1 => $face,
            preg_match('/_hanging_sign$/', $name) === 1 => $face === Facing::DOWN ? Facing::opposite($facing) : $face,
            preg_match('/glazed_terracotta$/', $name) === 1 => Facing::opposite($facing),
            $name === "hopper" => $face === Facing::DOWN ? Facing::DOWN : Facing::opposite($face),
            $name === "piston" || $name === "sticky_piston" => Facing::isHorizontal($towardPlayer) ? Facing::opposite($towardPlayer) : $towardPlayer,
            $name === "end_rod" => self::endRodFacing($face, $against),
            preg_match('/_stem$|_arm_collision$/', $name) === 1 => 0,
            default => $towardPlayer
        };
    }

    private static function endRodFacing(int $face, BlockState $against) : int{
        $pointing = $face;

        if($against->getName() === BlockIds::END_ROD){
            $other = (int) $against->getProperty(BlockProperties::FACING_DIRECTION);

            if((Facing::isHorizontal($other) ? Facing::opposite($other) : $other) === $face){
                $pointing = Facing::opposite($face);
            }
        }

        return Facing::isHorizontal($pointing) ? Facing::opposite($pointing) : $pointing;
    }
}