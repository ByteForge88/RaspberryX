<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\block\BlockIds;

use raspberry\command\Command;
use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\math\Vector3;

use raspberry\player\Player;

use raspberry\world\World;

use function atan2;
use function count;
use function max;
use function min;
use function rad2deg;
use function round;
use function sqrt;
use function str_starts_with;
use function substr;

class TeleportCommand extends Command{

    public function __construct(){
        parent::__construct("teleport");

        $this->setDescription($this->translate("command.teleport.description"));
        $this->setPermission("raspberry.command.teleport");
        $this->setAliases(["tp"]);

        $facing = new CommandEnum("TeleportFacing", ["facing"]);
        $flags = [CommandParameter::bool("checkForBlocks", true), CommandParameter::bool("forceProvidedPositionOnDimensionChange", true)];

        $overloads = [
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
        ];

        foreach($overloads as $index => $overload){
            foreach($overload as $position => $argument){
                $this->setArgument($position, $argument, $index);
            }
        }
    }

    public function onSent(CommandSender $sender, array $args) : void{
        if(isset($args["victim"])){
            /** @var list<Player> $victims */
            $victims = $args["victim"];
        }elseif($sender instanceof Player){
            $victims = [$sender];
        }else{
            throw new CommandException($this->translate("command.teleport.noPlayer"));
        }

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
                $sender->sendMessage($this->translate("command.teleport.blocked", ["player" => $victim->getName()]));
                continue;
            }

            $yaw = isset($args["yRot"]) ? $this->rotation($args["yRot"], $victim->getYaw()) : null;
            $pitch = isset($args["xRot"]) ? max(-90.0, min(90.0, $this->rotation($args["xRot"], $victim->getPitch()))) : null;

            if($lookAt !== null){
                [$yaw, $pitch] = self::facing($destination->add(0, $victim->getEyeHeight(), 0), $lookAt);
            }

            $victim->teleport($destination, $yaw, $pitch, $victimWorld);

            if($target !== null){
                $sender->sendMessage($this->translate("command.teleport.player", ["player" => $victim->getName(), "target" => $target->getName()]));
            }else{
                $sender->sendMessage($this->translate("command.teleport.position", [
                    "player" => $victim->getName(),
                    "x" => round($destination->x, 2),
                    "y" => round($destination->y, 2),
                    "z" => round($destination->z, 2)
                ]));
            }
        }
    }

    /**
     * @param list<Player> $players
     */
    private function single(array $players) : Player{
        if(count($players) !== 1){
            throw new CommandException($this->translate("command.teleport.tooMany"));
        }

        return $players[0];
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