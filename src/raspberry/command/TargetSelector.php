<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\entity\Human;

use raspberry\math\Vector3;

use raspberry\player\GameMode;
use raspberry\player\Player;

use raspberry\Server;

use raspberry\world\World;

use Closure;

use function abs;
use function array_filter;
use function array_slice;
use function count;
use function fmod;
use function in_array;
use function is_numeric;
use function max;
use function min;
use function preg_match;
use function shuffle;
use function str_starts_with;
use function strpos;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function usort;

class TargetSelector{

    /** @a every player, @e every entity, @p nearest player, @r random player, @s / @initiator the sender */
    public const PATTERN = '/^@(a|e|p|r|s|initiator)(\[.*\])?$/Dsi';

    private const ARGUMENTS = ["x", "y", "z", "r", "rm", "dx", "dy", "dz", "rx", "rxm", "ry", "rym", "c", "name", "type", "family", "m", "tag", "l", "lm"];

    private const NOT_SUPPORTED_YET = ["scores", "hasitem", "haspermission", "has_property"];

    /** arguments that may be given more than once, as long as at most one is not negated */
    private const REPEATABLE = ["name", "type", "family", "m", "tag"];

    private const PLAYER_FAMILIES = ["player", "mob"];

    private Server $server;

    public function __construct(Server $server){
        $this->server = $server;
    }

    /**
     * @return list<Player> may be empty
     *
     * @throws CommandException
     */
    public function select(CommandSender $sender, string $selector) : array{
        if(preg_match(self::PATTERN, $selector, $match) !== 1){
            throw new InvalidCommandSyntaxException();
        }

        $type = strtolower($match[1]);
        $arguments = $this->parseArguments(isset($match[2]) ? substr($match[2], 1, -1) : "");
        $this->checkValues($arguments);

        if($type === "s" || $type === "initiator"){
            if(!$sender instanceof Player){
                throw new CommandException($this->translate("command.notPlayer"));
            }

            $candidates = [$sender];
        }else{
            $candidates = $this->server->getInGamePlayers();
        }

        [$world, $origin] = $this->getOrigin($sender, $arguments);
        $positional = $type === "p" || $this->hasAny($arguments, ["x", "y", "z", "r", "rm", "dx", "dy", "dz"]);

        $selected = [];

        foreach($candidates as $player){
            if($positional && $player->getWorld() !== $world){
                continue;
            }

            if($this->matches($player, $arguments, $origin)){
                $selected[] = $player;
            }
        }

        return $this->limit($type, $selected, $arguments, $origin);
    }

    /**
     * @param array<string, list<array{string, bool}>> $arguments
     *
     * @return array{World, Vector3}
     */
    private function getOrigin(CommandSender $sender, array $arguments) : array{
        if($sender instanceof Player){
            $world = $sender->getWorld();
            $base = $sender->getPosition();
        }else{
            $world = $this->server->getWorldManager()->getDefaultWorld();
            $base = $world->getSpawnPosition();
        }

        $axes = [$base->x, $base->y, $base->z];

        foreach(["x", "y", "z"] as $index => $axis){
            if(isset($arguments[$axis])){
                $value = $this->single($arguments, $axis);
                $axes[$index] = str_starts_with($value, "~") ? $axes[$index] + $this->number($axis, substr($value, 1), true) : $this->number($axis, $value, false);
            }
        }

        return [$world, new Vector3($axes[0], $axes[1], $axes[2])];
    }

    /**
     * @param array<string, list<array{string, bool}>> $arguments
     */
    private function matches(Player $player, array $arguments, Vector3 $origin) : bool{
        $position = $player->getPosition();
        $distance = $position->distance($origin);

        if(isset($arguments["r"]) && $distance > $this->number("r", $this->single($arguments, "r"), false)){
            return false;
        }

        if(isset($arguments["rm"]) && $distance < $this->number("rm", $this->single($arguments, "rm"), false)){
            return false;
        }

        if($this->hasAny($arguments, ["dx", "dy", "dz"]) && !$this->inVolume($position, $origin, $arguments)){
            return false;
        }

        if(!$this->inRange("rx", "rxm", $player->getPitch(), $arguments) || !$this->inRange("ry", "rym", self::normalizeYaw($player->getYaw()), $arguments)){
            return false;
        }

        if(!$this->inRange("l", "lm", 0.0, $arguments)){
            return false;
        }

        return $this->test($arguments, "name", fn(string $value) => strtolower($value) === strtolower($player->getName()))
            && $this->test($arguments, "type", fn(string $value) => in_array(strtolower($value), ["player", "minecraft:player"], true))
            && $this->test($arguments, "family", fn(string $value) => in_array(strtolower($value), self::PLAYER_FAMILIES, true))
            && $this->test($arguments, "m", fn(string $value) => $this->gameMode($value) === $player->getGameMode())
            && $this->test($arguments, "tag", fn(string $value) => $value === ""); //no tags yet: tag= matches, tag=x does not
    }

    /**
     * Applies c and the selector's own limit: @p and @r pick one unless c says otherwise.
     *
     * @param list<Player>                             $players
     * @param array<string, list<array{string, bool}>> $arguments
     *
     * @return list<Player>
     */
    private function limit(string $type, array $players, array $arguments, Vector3 $origin) : array{
        $count = null;

        if(isset($arguments["c"])){
            $value = $this->single($arguments, "c");

            if(preg_match('/^[+-]?\d{1,9}$/D', $value) !== 1 || (int) $value === 0){
                throw new CommandException($this->translate("command.selector.invalid", ["argument" => "c", "value" => $value]));
            }

            $count = (int) $value;
        }elseif($type === "p" || $type === "r"){
            $count = 1;
        }

        if($type === "r"){
            shuffle($players);
        }elseif($count !== null){
            usort($players, fn(Player $a, Player $b) => ($a->getPosition()->distance($origin) <=> $b->getPosition()->distance($origin)) * ($count < 0 ? -1 : 1));
        }

        return $count === null ? $players : array_slice($players, 0, abs($count));
    }

    /**
     * dx dy dz: the box from the origin to origin + d, the way the game does it (a whole block bigger
     * on the far side). Players are in it when their hitbox touches it.
     *
     * @param array<string, list<array{string, bool}>> $arguments
     */
    private function inVolume(Vector3 $position, Vector3 $origin, array $arguments) : bool{
        $size = [];

        foreach(["dx", "dy", "dz"] as $axis){
            $size[] = isset($arguments[$axis]) ? $this->number($axis, $this->single($arguments, $axis), false) : 0.0;
        }

        $from = [$origin->x, $origin->y, $origin->z];
        $half = Human::WIDTH / 2;
        $box = [
            [$position->x - $half, $position->x + $half],
            [$position->y, $position->y + Human::HEIGHT],
            [$position->z - $half, $position->z + $half]
        ];

        foreach([0, 1, 2] as $axis){
            $min = min($from[$axis], $from[$axis] + $size[$axis]);
            $max = max($from[$axis], $from[$axis] + $size[$axis]) + 1;

            if($box[$axis][1] < $min || $box[$axis][0] > $max){
                return false;
            }
        }

        return true;
    }

    /**
     * $maxKey is the highest allowed value (e.g. rx), $minKey the lowest (e.g. rxm).
     *
     * @param array<string, list<array{string, bool}>> $arguments
     */
    private function inRange(string $maxKey, string $minKey, float $value, array $arguments) : bool{
        if(isset($arguments[$maxKey]) && $value > $this->number($maxKey, $this->single($arguments, $maxKey), false)){
            return false;
        }

        return !isset($arguments[$minKey]) || $value >= $this->number($minKey, $this->single($arguments, $minKey), false);
    }

    /**
     * name=Steve must match, name=!Steve must not; several negations may be combined.
     *
     * @param array<string, list<array{string, bool}>> $arguments
     * @param Closure(string) : bool                    $matches
     */
    private function test(array $arguments, string $key, Closure $matches) : bool{
        foreach($arguments[$key] ?? [] as [$value, $negated]){
            if($matches($value) === $negated){
                return false;
            }
        }

        return true;
    }

    private function gameMode(string $value) : GameMode{
        $value = strtolower($value);

        if($value === "d" || $value === "default" || $value === "5"){
            return $this->server->getDefaultGameMode();
        }

        return GameMode::fromString($value) ?? throw new CommandException($this->translate("command.selector.invalid", ["argument" => "m", "value" => $value]));
    }

    /**
     * Splits "r=10,name=!\"Byte Forge\"" into [key => [[value, negated], ...]].
     *
     * @return array<string, list<array{string, bool}>>
     */
    private function parseArguments(string $text) : array{
        $arguments = [];

        if(trim($text) === ""){
            return $arguments;
        }

        foreach($this->splitTopLevel($text) as $part){
            $separator = strpos($part, "=");

            if($separator === false){
                throw new CommandException($this->translate("command.selector.invalid", ["argument" => trim($part), "value" => ""]));
            }

            $key = strtolower(trim(substr($part, 0, $separator)));
            $value = trim(substr($part, $separator + 1));
            $negated = str_starts_with($value, "!");

            if($negated){
                $value = trim(substr($value, 1));
            }

            if(strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"'){
                $value = substr($value, 1, -1);
            }

            if(in_array($key, self::NOT_SUPPORTED_YET, true)){
                throw new CommandException($this->translate("command.selector.unsupported", ["argument" => $key]));
            }

            if(!in_array($key, self::ARGUMENTS, true)){
                throw new CommandException($this->translate("command.selector.unknown", ["argument" => $key]));
            }

            if($negated && !in_array($key, self::REPEATABLE, true)){
                throw new CommandException($this->translate("command.selector.invalid", ["argument" => $key, "value" => "!" . $value]));
            }

            $arguments[$key][] = [$value, $negated];
        }

        foreach($arguments as $key => $values){
            $positives = count(array_filter($values, fn(array $entry) => !$entry[1]));

            if(count($values) > 1 && (!in_array($key, self::REPEATABLE, true) || ($positives > 1 && $key !== "family"))){
                throw new CommandException($this->translate("command.selector.duplicate", ["argument" => $key]));
            }
        }

        return $arguments;
    }

    /**
     * Checks every value up front, so a typo is reported even when no player is online.
     *
     * @param array<string, list<array{string, bool}>> $arguments
     */
    private function checkValues(array $arguments) : void{
        foreach(["r", "rm", "dx", "dy", "dz", "rx", "rxm", "ry", "rym", "l", "lm"] as $key){
            if(isset($arguments[$key])){
                $this->number($key, $this->single($arguments, $key), false);
            }
        }

        foreach($arguments["m"] ?? [] as [$value]){
            $this->gameMode($value);
        }
    }

    /**
     * Splits on commas that are not inside quotes or brackets.
     *
     * @return list<string>
     */
    private function splitTopLevel(string $text) : array{
        $parts = [];
        $current = "";
        $depth = 0;
        $inString = false;

        for($i = 0, $length = strlen($text); $i < $length; ++$i){
            $char = $text[$i];

            if($inString){
                if($char === "\\" && $i + 1 < $length){
                    $current .= $char . $text[++$i];
                    continue;
                }

                $inString = $char !== '"';
            }elseif($char === '"'){
                $inString = true;
            }elseif($char === "{" || $char === "["){
                ++$depth;
            }elseif($char === "}" || $char === "]"){
                --$depth;
            }elseif($char === "," && $depth === 0){
                $parts[] = $current;
                $current = "";
                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }

    /**
     * @param array<string, list<array{string, bool}>> $arguments
     */
    private function single(array $arguments, string $key) : string{
        return $arguments[$key][0][0];
    }

    /**
     * @param array<string, list<array{string, bool}>> $arguments
     * @param list<string>                             $keys
     */
    private function hasAny(array $arguments, array $keys) : bool{
        foreach($keys as $key){
            if(isset($arguments[$key])){
                return true;
            }
        }

        return false;
    }

    private function number(string $argument, string $value, bool $allowEmpty) : float{
        if($value === "" && $allowEmpty){
            return 0.0;
        }

        if(!is_numeric($value)){
            throw new CommandException($this->translate("command.selector.invalid", ["argument" => $argument, "value" => $value]));
        }

        return (float) $value;
    }

    /**
     * Yaw as the game shows it, from -180 to 180.
     */
    private static function normalizeYaw(float $yaw) : float{
        $yaw = fmod($yaw + 180.0, 360.0);

        return ($yaw < 0 ? $yaw + 360.0 : $yaw) - 180.0;
    }

    /**
     * @param array<string, string|int|float> $params
     */
    private function translate(string $key, array $params = []) : string{
        return $this->server->getLanguage()->translate($key, $params);
    }
}