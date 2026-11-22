<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\block\BlockIds;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandException;
use raspberry\command\CommandSender;
use raspberry\command\ParameterBuilder;

use raspberry\command\argument\Argument;
use raspberry\command\argument\BoolArgument;
use raspberry\command\argument\KeywordArgument;
use raspberry\command\argument\PositionArgument;
use raspberry\command\argument\RelativeValue;
use raspberry\command\argument\RelativeValueArgument;
use raspberry\command\argument\TargetArgument;

use raspberry\lang\Translation;

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

class TeleportCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("teleport");
        $this->setDescription("commands.tp.description");
        $this->setPermission("raspberry.command.teleport");
        $this->setAliases(["tp"]);

        $victim = new TargetArgument("victim");
        $to = new PositionArgument("destination");
        $toPlayer = new TargetArgument("destination");
        $yaw = new RelativeValueArgument("yRot", true);
        $pitch = new RelativeValueArgument("xRot", true);
        $facing = new KeywordArgument("facing", "TeleportFacing", ["facing"]);
        $lookAtPosition = new PositionArgument("lookAtPosition");
        $lookAtEntity = new TargetArgument("lookAtEntity");

        $this->addWay(0, true, $to);
        $this->addWay(1, true, $to, $yaw, $pitch);
        $this->addWay(2, true, $to, $facing, $lookAtPosition);
        $this->addWay(3, true, $to, $facing, $lookAtEntity);
        $this->addWay(4, true, $victim, $to, $yaw, $pitch);
        $this->addWay(5, true, $victim, $to);
        $this->addWay(6, true, $victim, $to, $facing, $lookAtPosition);
        $this->addWay(7, true, $victim, $to, $facing, $lookAtEntity);
        $this->addWay(8, false, $toPlayer);
        $this->addWay(9, true, $victim, $toPlayer);
    }

    private function addWay(int $index, bool $checks, Argument ...$arguments) : void{
        if($checks){
            $arguments[] = new BoolArgument("checkForBlocks", true);
            $arguments[] = new BoolArgument("forceProvidedPositionOnDimensionChange", true);
        }

        $builder = new ParameterBuilder($index);

        foreach($arguments as $position => $argument){
            $builder->addArgument($position, $argument);
        }

        $this->addParameterBuilder($builder);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        $victims = $this->targetsOrSelf($sender, $args, "victim");
        $destination = $args["destination"];
        $target = null;

        if($destination instanceof Vector3){
            $world = $sender instanceof Player ? $sender->getWorld() : null;
        }else{
            $target = self::single($destination);
            $world = $target->getWorld();
            $destination = $target->getPosition();
        }

        $lookAt = null;

        if(isset($args["lookAtPosition"])){
            $lookAt = $args["lookAtPosition"];
        }elseif(isset($args["lookAtEntity"])){
            $lookAt = self::single($args["lookAtEntity"])->getEyePosition();
        }

        foreach($victims as $victim){
            if($victim->isDead()){
                continue;
            }

            $victimWorld = $world ?? $victim->getWorld();

            if(($args["checkForBlocks"] ?? false) && !self::isFree($victimWorld, $destination)){
                $this->failure(new Translation("commands.tp.safeTeleportFail", [$victim->getName(), $target?->getName() ?? self::coordinates($destination)]));
                continue;
            }

            /** @var RelativeValue|null $yRot */
            $yRot = $args["yRot"] ?? null;
            /** @var RelativeValue|null $xRot */
            $xRot = $args["xRot"] ?? null;
            $yaw = $yRot?->from($victim->getYaw());
            $pitch = $xRot === null ? null : max(-90.0, min(90.0, $xRot->from($victim->getPitch())));

            if($lookAt !== null){
                [$yaw, $pitch] = self::facing($destination->add(0, $victim->getEyeHeight(), 0), $lookAt);
            }

            $victim->teleport($destination, $yaw, $pitch, $victimWorld);

            if($target !== null){
                $this->success(new Translation("commands.tp.success", [$victim->getName(), $target->getName()]));
            }else{
                $this->success(new Translation("commands.tp.success.coordinates", [$victim->getName(), self::number($destination->x), self::number($destination->y), self::number($destination->z)]));
            }
        }
    }

    /**
     * @param list<Player> $players
     */
    private static function single(array $players) : Player{
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

    /**
     * @return array{float, float}
     */
    private static function facing(Vector3 $eyes, Vector3 $target) : array{
        $x = $target->x - $eyes->x;
        $y = $target->y - $eyes->y;
        $z = $target->z - $eyes->z;
        return [rad2deg(atan2(-$x, $z)), rad2deg(-atan2($y, sqrt($x * $x + $z * $z)))];
    }

    private static function isFree(World $world, Vector3 $position) : bool{
        $x = $position->getFloorX();
        $y = $position->getFloorY();
        $z = $position->getFloorZ();
        return $world->getBlock($x, $y, $z)->getName() === BlockIds::AIR && $world->getBlock($x, $y + 1, $z)->getName() === BlockIds::AIR;
    }
}