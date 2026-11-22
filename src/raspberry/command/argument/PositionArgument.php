<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandSender;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

use raspberry\player\Player;

use function array_filter;
use function array_map;
use function cos;
use function count;
use function deg2rad;
use function explode;
use function preg_match;
use function sin;
use function str_starts_with;
use function substr;

/**
 * x y z. Each can be absolute (10), relative to the sender (~ or ~-2), or all three can be local
 * (^left ^up ^forward, from where the sender looks). Like vanilla, a whole x or z is the center of
 * the block, so 10 becomes 10.5.
 */
class PositionArgument extends Argument{

    private const string NUMBER = '[+-]?(\d{1,18}(\.\d*)?|\.\d+)';

    public function getNetworkType() : int{
        return AvailableCommandsPacket::ARG_TYPE_POSITION;
    }

    public function getTypeName() : string{
        return "x y z";
    }

    public function getWordCount() : int{
        return 3;
    }

    public function accepts(string $text, CommandSender $sender) : bool{
        $parts = explode(" ", $text);

        if(count($parts) !== 3){
            return false;
        }

        $local = count(array_filter($parts, fn(string $part) => str_starts_with($part, "^")));

        if($local !== 0 && $local !== 3){
            return false;
        }

        foreach($parts as $part){
            if(!$this->isCoordinate($part)){
                return false;
            }
        }

        return true;
    }

    public function read(string $text, CommandSender $sender) : Vector3{
        $parts = explode(" ", $text);
        $origin = $sender instanceof Player ? $sender->getPosition() : Vector3::zero();

        if(str_starts_with($parts[0], "^")){
            [$left, $up, $forward] = array_map(fn(string $part) => (float) substr($part, 1), $parts);

            return $this->finish(self::localToWorld($origin, $sender instanceof Player ? $sender->getYaw() : 0.0, $sender instanceof Player ? $sender->getPitch() : 0.0, $left, $up, $forward));
        }

        $axes = [$origin->x, $origin->y, $origin->z];
        $values = [];

        foreach($parts as $axis => $part){
            if(str_starts_with($part, "~")){
                $values[] = $axes[$axis] + (float) substr($part, 1);
            }else{
                $values[] = $this->absolute($part, $axis);
            }
        }

        return $this->finish(new Vector3(...$values));
    }

    protected function isCoordinate(string $part) : bool{
        if(str_starts_with($part, "~") || str_starts_with($part, "^")){
            $part = substr($part, 1);

            return $part === "" || preg_match('/^' . self::NUMBER . '$/D', $part) === 1;
        }

        return preg_match('/^' . self::NUMBER . '$/D', $part) === 1;
    }

    protected function absolute(string $part, int $axis) : float{
        return (float) $part + ($axis !== 1 && preg_match('/^[+-]?\d+$/D', $part) === 1 ? 0.5 : 0.0);
    }

    protected function finish(Vector3 $position) : Vector3{
        return $position;
    }

    /**
     * ^left ^up ^forward to a world position, the same way the game does it (yaw 0 looks towards +Z).
     */
    private static function localToWorld(Vector3 $origin, float $yaw, float $pitch, float $left, float $up, float $forward) : Vector3{
        $yawCos = cos(deg2rad($yaw + 90));
        $yawSin = sin(deg2rad($yaw + 90));
        $pitchCos = cos(deg2rad(-$pitch));
        $pitchSin = sin(deg2rad(-$pitch));
        $upCos = cos(deg2rad(-$pitch + 90));
        $upSin = sin(deg2rad(-$pitch + 90));

        $f = [$yawCos * $pitchCos, $pitchSin, $yawSin * $pitchCos]; //forward
        $u = [$yawCos * $upCos, $upSin, $yawSin * $upCos]; //up
        $l = [-($f[1] * $u[2] - $f[2] * $u[1]), -($f[2] * $u[0] - $f[0] * $u[2]), -($f[0] * $u[1] - $f[1] * $u[0])]; //left = -(forward x up)

        return $origin->add(
            $f[0] * $forward + $u[0] * $up + $l[0] * $left,
            $f[1] * $forward + $u[1] * $up + $l[1] * $left,
            $f[2] * $forward + $u[2] * $up + $l[2] * $left
        );
    }
}