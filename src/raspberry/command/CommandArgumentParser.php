<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket as Packet;

use raspberry\player\Player;

use raspberry\Server;

use function array_filter;
use function array_map;
use function cos;
use function count;
use function deg2rad;
use function implode;
use function in_array;
use function is_array;
use function json_decode;
use function ltrim;
use function preg_match;
use function preg_match_all;
use function preg_replace_callback;
use function sin;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;

use const PREG_SET_ORDER;

class CommandArgumentParser{

    private const OPERATORS = ["=", "+=", "-=", "*=", "/=", "%=", "<", ">", "><"];

    private const COMPARE_OPERATORS = ["<", "<=", "=", ">=", ">"];

    private Server $server;

    private TargetSelector $targetSelector;

    public function __construct(Server $server){
        $this->server = $server;
        $this->targetSelector = new TargetSelector($server);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidCommandSyntaxException when no overload fits
     * @throws CommandException when a selector matches no players
     */
    public function parse(CommandSender $sender, Command $command, string $input) : array{
        $failure = null;

        foreach($command->getOverloads() as $overload){
            try{
                $args = $this->readOverload($sender, $overload, new CommandReader($input));
            }catch(InvalidCommandSyntaxException){
                continue;
            }

            try{
                return $this->resolveSelectors($sender, $overload, $args);
            }catch(CommandException $exception){
                $failure ??= $exception;
            }
        }

        throw $failure ?? new InvalidCommandSyntaxException();
    }

    /**
     * @param list<CommandParameter> $overload
     *
     * @return array<string, mixed>
     */
    private function readOverload(CommandSender $sender, array $overload, CommandReader $reader) : array{
        $args = [];

        foreach($overload as $parameter){
            if($reader->atEnd()){
                if($parameter->isOptional()){
                    break;
                }

                throw new InvalidCommandSyntaxException();
            }

            $args[$parameter->getName()] = $this->readArgument($sender, $parameter, $reader);
        }

        if(!$reader->atEnd()){
            throw new InvalidCommandSyntaxException();
        }

        return $args;
    }

    private function readArgument(CommandSender $sender, CommandParameter $parameter, CommandReader $reader) : mixed{
        $enum = $parameter->getEnum();

        if($enum !== null){
            $value = $enum->match($reader->readWord()) ?? throw new InvalidCommandSyntaxException();

            return $enum->getName() === CommandEnum::BOOLEAN ? $value === "true" : $value;
        }

        $postfix = $parameter->getPostfix();

        if($postfix !== null){
            $word = $reader->readWord();
            $length = strlen($word) - strlen($postfix);

            if($length < 1 || strtolower(substr($word, $length)) !== strtolower($postfix)){
                throw new InvalidCommandSyntaxException();
            }

            return self::toInt(substr($word, 0, $length));
        }

        return match($parameter->getType()){
            Packet::ARG_TYPE_INT => self::toInt($reader->readWord()),
            Packet::ARG_TYPE_FLOAT => self::toFloat($reader->readWord()),
            Packet::ARG_TYPE_WILDCARD_INT => ($word = $reader->readWord()) === "*" ? "*" : self::toInt($word),
            Packet::ARG_TYPE_OPERATOR => self::oneOf($reader->readWord(), self::OPERATORS),
            Packet::ARG_TYPE_COMPARE_OPERATOR => self::oneOf($reader->readWord(), self::COMPARE_OPERATORS),
            Packet::ARG_TYPE_TARGET => self::readTarget($reader->readTarget(), false),
            Packet::ARG_TYPE_WILDCARD_TARGET => self::readTarget($reader->readTarget(), true),
            Packet::ARG_TYPE_FULL_INTEGER_RANGE => self::toIntegerRange($reader->readWord()),
            Packet::ARG_TYPE_EQUIPMENT_SLOT => self::matching($reader->readWord(), '/^slot\.[a-z_]+(\.[a-z_]+)*$/D'),
            Packet::ARG_TYPE_INT_POSITION => $this->readPosition($sender, $reader, true),
            Packet::ARG_TYPE_POSITION => $this->readPosition($sender, $reader, false),
            Packet::ARG_TYPE_MESSAGE, Packet::ARG_TYPE_RAWTEXT => self::notEmpty($reader->readRemaining()),
            Packet::ARG_TYPE_COMMAND => self::notEmpty(ltrim($reader->readRemaining(), "/")),
            Packet::ARG_TYPE_JSON => self::toJson($reader->readBracketed("{[")),
            Packet::ARG_TYPE_BLOCK_STATES => self::toBlockStates($reader->readBracketed("[")),
            default => $reader->readWord() //value, filepath, string
        };
    }

    /**
     * @param list<CommandParameter> $overload
     * @param array<string, mixed>   $args
     *
     * @return array<string, mixed>
     */
    private function resolveSelectors(CommandSender $sender, array $overload, array $args) : array{
        foreach($overload as $parameter){
            $name = $parameter->getName();

            if(!isset($args[$name]) || $parameter->getEnum() !== null){
                continue;
            }

            $type = $parameter->getType();

            if($type === Packet::ARG_TYPE_TARGET || $type === Packet::ARG_TYPE_WILDCARD_TARGET){
                $players = $args[$name] === "*" ? $this->server->getInGamePlayers() : $this->selectPlayers($sender, $args[$name]);

                if($players === []){
                    throw new CommandException($this->server->getLanguage()->translate("command.noTargets"));
                }

                $args[$name] = $players;
            }elseif($type === Packet::ARG_TYPE_MESSAGE){
                $args[$name] = $this->expandSelectors($sender, $args[$name]);
            }
        }

        return $args;
    }

    /**
     * @return list<Player>
     */
    private function selectPlayers(CommandSender $sender, string $target) : array{
        if(str_starts_with($target, "@")){
            return $this->targetSelector->select($sender, $target);
        }

        $player = $this->server->getPlayerByPrefix($target);

        return $player === null ? [] : [$player];
    }

    /**
     * "hi @a" becomes "hi Alice, Bob", like vanilla /say. Selectors that match nobody, or have a wrong
     * argument, stay as typed.
     */
    private function expandSelectors(CommandSender $sender, string $message) : string{
        return preg_replace_callback('/@(initiator|a|e|p|r|s)(?![a-z0-9_])(\[[^\]]*\])?/i', function(array $match) use ($sender) : string{
            try{
                $players = $this->selectPlayers($sender, $match[0]);
            }catch(CommandException){
                return $match[0];
            }

            return $players === [] ? $match[0] : implode(", ", array_map(fn(Player $player) => $player->getName(), $players));
        }, $message) ?? $message;
    }

    /**
     * Reads x y z. Each can be absolute (10), relative to the sender (~ or ~-2), or all three can be
     * local (^left ^up ^forward, from where the sender looks).
     */
    private function readPosition(CommandSender $sender, CommandReader $reader, bool $block) : Vector3{
        $parts = [$reader->readWord(), $reader->readWord(), $reader->readWord()];
        $local = count(array_filter($parts, fn(string $part) => str_starts_with($part, "^")));

        if($local !== 0 && $local !== 3){
            throw new InvalidCommandSyntaxException(); //^ cannot be mixed with other coordinates
        }

        $origin = $sender instanceof Player ? $sender->getPosition() : Vector3::zero();

        if($local === 3){
            [$left, $up, $forward] = array_map(fn(string $part) => self::toNumber(substr($part, 1), true), $parts);
            $position = self::localToWorld($origin, $sender instanceof Player ? $sender->getYaw() : 0.0, $sender instanceof Player ? $sender->getPitch() : 0.0, $left, $up, $forward);
        }else{
            $axes = [$origin->x, $origin->y, $origin->z];
            $values = [];

            foreach($parts as $axis => $part){
                if(str_starts_with($part, "~")){
                    $values[] = $axes[$axis] + self::toNumber(substr($part, 1), true);
                }elseif($block){
                    $values[] = (float) self::toInt($part);
                }else{
                    //like vanilla, a whole x or z is the centre of the block: 10 becomes 10.5
                    $values[] = self::toNumber($part, false) + ($axis !== 1 && preg_match('/^[+-]?\d+$/D', $part) === 1 ? 0.5 : 0.0);
                }
            }

            $position = new Vector3(...$values);
        }

        return $block ? new Vector3($position->getFloorX(), $position->getFloorY(), $position->getFloorZ()) : $position;
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

    private static function readTarget(string $word, bool $wildcard) : string{
        if($word === "*"){
            return $wildcard ? "*" : throw new InvalidCommandSyntaxException();
        }

        if(str_starts_with($word, "@") && preg_match(TargetSelector::PATTERN, $word) !== 1){
            throw new InvalidCommandSyntaxException(); //unknown selector
        }

        return $word;
    }

    private static function toInt(string $word) : int{
        if(preg_match('/^[+-]?\d{1,18}$/D', $word) !== 1){
            throw new InvalidCommandSyntaxException();
        }

        return (int) $word;
    }

    private static function toFloat(string $word) : float{
        return self::toNumber($word, false);
    }

    /**
     * @param bool $allowEmpty true for the part after ~ or ^, where nothing means 0
     */
    private static function toNumber(string $word, bool $allowEmpty) : float{
        if($word === "" && $allowEmpty){
            return 0.0;
        }

        if(preg_match('/^[+-]?(\d{1,18}(\.\d*)?|\.\d+)$/D', $word) !== 1){
            throw new InvalidCommandSyntaxException();
        }

        return (float) $word;
    }

    /**
     * @return array{min: int|null, max: int|null}
     */
    private static function toIntegerRange(string $word) : array{
        if(preg_match('/^[+-]?\d{1,18}$/D', $word) === 1){
            return ["min" => (int) $word, "max" => (int) $word];
        }

        if(preg_match('/^([+-]?\d{1,18})?\.\.([+-]?\d{1,18})?$/D', $word, $match) !== 1 || $word === ".."){
            throw new InvalidCommandSyntaxException();
        }

        return [
            "min" => ($match[1] ?? "") === "" ? null : (int) $match[1],
            "max" => ($match[2] ?? "") === "" ? null : (int) $match[2]
        ];
    }

    /**
     * @return array<mixed>
     */
    private static function toJson(string $text) : array{
        $data = json_decode($text, true, 64);

        return is_array($data) ? $data : throw new InvalidCommandSyntaxException();
    }

    /**
     * ["facing_direction"=1,"open_bit"=true,"color"="red"] to [name => value].
     *
     * @return array<string, bool|int|float|string>
     */
    private static function toBlockStates(string $text) : array{
        $inner = trim(substr($text, 1, -1));

        if($inner === ""){
            return [];
        }

        $entry = '\s*"((?:[^"\\\\]|\\\\.)*)"\s*[=:]\s*("(?:[^"\\\\]|\\\\.)*"|[^,\]\s]+)\s*';

        if(preg_match('/^' . $entry . '(,' . $entry . ')*$/Ds', $inner) !== 1){
            throw new InvalidCommandSyntaxException();
        }

        preg_match_all('/' . $entry . '(?:,|$)/s', $inner, $matches, PREG_SET_ORDER);

        $states = [];

        foreach($matches as $match){
            $value = $match[2];

            $states[$match[1]] = match(true){
                $value === "true" => true,
                $value === "false" => false,
                str_starts_with($value, '"') => substr($value, 1, -1),
                preg_match('/^[+-]?\d{1,18}$/D', $value) === 1 => (int) $value,
                preg_match('/^[+-]?(\d+\.\d*|\.\d+)$/D', $value) === 1 => (float) $value,
                default => throw new InvalidCommandSyntaxException()
            };
        }

        return $states;
    }

    /**
     * @param list<string> $allowed
     */
    private static function oneOf(string $word, array $allowed) : string{
        return in_array($word, $allowed, true) ? $word : throw new InvalidCommandSyntaxException();
    }

    private static function matching(string $word, string $pattern) : string{
        return preg_match($pattern, $word) === 1 ? $word : throw new InvalidCommandSyntaxException();
    }

    private static function notEmpty(string $text) : string{
        return $text !== "" ? $text : throw new InvalidCommandSyntaxException();
    }
}