<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\block\BlockIds;

use raspberry\command\Command;
use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\math\Vector3;

use raspberry\player\Player;

use raspberry\world\World;

use function atan2;
use function count;
use function max;
use function min;
use function number_format;
use function rad2deg;
use function sqrt;
use function str_starts_with;
use function substr;

class TeleportCommand extends Command{

    public function __construct(){
        parent::__construct("teleport", "commands.tp.description", "raspberry.command.teleport", ["tp"]);

        $facing = new CommandEnum("TeleportFacing", ["facing"]);
        $flags = [CommandParameter::bool("checkForBlocks", true), CommandParameter::bool("forceProvidedPositionOnDimensionChange", true)];

        $this->setOverloads([
            [CommandParameter::position("destination"), ...$flags],
            [CommandParameter::position("destination"), CommandParameter::value("yRot", true), CommandParameter::value("xRot", true), ...$flags],
            [CommandParameter::position("destination"), CommandParameter::enum("facing", $facing), CommandParameter::position("lookAtPosition"), ...$flags],
            [CommandParameter::position("destination"), CommandParameter::enum("facing", $facing), CommandParameter::target("lookAtEntity"), ...$flags],
            [CommandParameter::target("victim"), CommandParameter::position("destination"), CommandParameter::value("yRot", true), CommandParameter::value("xRot", true), ...$flags],
            [CommandParameter::target("victim"), CommandParameter::position("destination"), ...$flags],
            [CommandParameter::target("victim"), CommandParameter::position("destination"), CommandParameter::enum("facing", $facing), CommandParameter::position("lookAtPosition"), ...$flags],
            [CommandParameter::target("victim"), CommandParameter::position("destination"), CommandParameter::enum("facing", $facing), CommandParameter::target("lookAtEntity"), ...$flags],
            [CommandParameter::target("destination")],
            [CommandParameter::target("victim"), CommandParameter::target("destination"), ...$flags]
        ]);
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        /** @var list<Player> $victims */
        $victims = $this->playersOrSelf($sender, $args, "victim");

        $destination = $args["destination"];
        $target = null;

        if($destination instanceof Vector3){
            $world = $sender instanceof Player ? $sender->getWorld() : null;
        }else{
            $target = $this->single($destination);
            $world = $target->getWorld();
            $destination = $target->getPosition();
        }

        $lookAt = null;

        if(isset($args["lookAtPosition"])){
            $lookAt = $args["lookAtPosition"];
        }elseif(isset($args["lookAtEntity"])){
            $lookAt = $this->single($args["lookAtEntity"])->getEyePosition();
        }

        foreach($victims as $victim){
            if($victim->isDead()){
                continue;
            }

            $victimWorld = $world ?? $victim->getWorld();

            if(($args["checkForBlocks"] ?? false) && !$this->isFree($victimWorld, $destination)){
                $output->error("commands.tp.safeTeleportFail", [$victim->getName(), $target?->getName() ?? self::coordinates($destination)]);
                continue;
            }

            $yaw = isset($args["yRot"]) ? $this->rotation($args["yRot"], $victim->getYaw()) : null;
            $pitch = isset($args["xRot"]) ? max(-90.0, min(90.0, $this->rotation($args["xRot"], $victim->getPitch()))) : null;

            if($lookAt !== null){
                [$yaw, $pitch] = self::facing($destination->add(0, $victim->getEyeHeight(), 0), $lookAt);
            }

            $victim->teleport($destination, $yaw, $pitch, $victimWorld);

            if($target !== null){
                $output->success("commands.tp.success", [$victim->getName(), $target->getName()]);
            }else{
                $output->success("commands.tp.success.coordinates", [$victim->getName(), self::number($destination->x), self::number($destination->y), self::number($destination->z)]);
            }
        }
    }

    /**
     * @param list<Player> $players
     */
    private function single(array $players) : Player{
        if(count($players) !== 1){
            throw new CommandException("commands.generic.tooManyTargets");
        }

        return $players[0];
    }

    private static function number(float $value) : string{
        return number_format($value, 2, ".", "");
    }

    private static function coordinates(Vector3 $position) : string{
        return self::number($position->x) . ", " . self::number($position->y) . ", " . self::number($position->z);
    }

    private function rotation(string $value, float $current) : float{
        $relative = str_starts_with($value, "~");
        $number = $relative ? substr($value, 1) : $value;

        if($number === ""){
            return $current;
        }

        return ($relative ? $current : 0.0) + (float) $number;
    }

    /**
     * @return array{float, float}
     */
    private static function facing(Vector3 $eyes, Vector3 $target) : array{
        $x = $target->x - $eyes->x;
        $y = $target->y - $eyes->y;
        $z = $target->z - $eyes->z;
        return [rad2deg(atan2(-$x, $z)), rad2deg(-atan2($y, sqrt($x * $x + $z * $z)))];
    }

    private function isFree(World $world, Vector3 $position) : bool{
        $x = $position->getFloorX();
        $y = $position->getFloorY();
        $z = $position->getFloorZ();
        return $world->getBlock($x, $y, $z)->getName() === BlockIds::AIR && $world->getBlock($x, $y + 1, $z)->getName() === BlockIds::AIR;
    }
}