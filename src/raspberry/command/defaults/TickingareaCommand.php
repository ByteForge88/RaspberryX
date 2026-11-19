<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\Server;

use raspberry\command\Command;
use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\math\Vector3;

use raspberry\player\Player;

use raspberry\world\TickingArea;
use raspberry\world\TickingAreas;
use raspberry\world\World;

use function implode;
use function max;
use function min;
use function str_replace;

class TickingareaCommand extends Command{

    public const MAX_COORDINATE = 30000000;

    public function __construct(){
        parent::__construct("tickingarea", "commands.tickingarea.description", "raspberry.command.tickingarea");

        $add = CommandParameter::enum("mode", new CommandEnum("TickingAreaModeAdd", ["add"]));
        $remove = CommandParameter::enum("mode", new CommandEnum("TickingAreaModeRemove", ["remove"]));
        $preload = CommandParameter::enum("mode", new CommandEnum("TickingAreaModePreload", ["preload"]));

        $this->addOverload($add, CommandParameter::enum("circle", new CommandEnum("AddTickingAreaType", ["circle"])), CommandParameter::position("center"), CommandParameter::int("radius"), CommandParameter::string("name", true), CommandParameter::bool("preload", true));
        $this->addOverload($add, CommandParameter::position("from"), CommandParameter::position("to"), CommandParameter::string("name", true), CommandParameter::bool("preload", true));
        $this->addOverload($remove, CommandParameter::position("position"));
        $this->addOverload($remove, CommandParameter::string("name"));
        $this->addOverload(CommandParameter::enum("mode", new CommandEnum("TickingAreaModeRemoveAll", ["remove_all"])));
        $this->addOverload(CommandParameter::enum("mode", new CommandEnum("TickingAreaModeList", ["list"])), CommandParameter::enum("all-dimensions", new CommandEnum("AllDimensions", ["all-dimensions"]), true));
        $this->addOverload($preload, CommandParameter::position("position"), CommandParameter::bool("preload", true));
        $this->addOverload($preload, CommandParameter::string("name"), CommandParameter::bool("preload", true));
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        $world = $sender instanceof Player ? $sender->getWorld() : Server::getInstance()->getWorldManager()->getDefaultWorld();
        $areas = $world->getTickingAreas();

        if($args["mode"] === "remove_all" && $areas->count() === 0){
            throw $this->error("none");
        }

        match($args["mode"]){
            "add" => $this->add($world, $areas, $output, $args),
            "remove" => $this->remove($areas, $output, $this->find($areas, $args)),
            "remove_all" => $this->remove($areas, $output, $areas->getAll()),
            "list" => $this->list($world, $output, isset($args["all-dimensions"])),
            default => $this->preload($world, $areas, $output, $this->find($areas, $args), $args["preload"] ?? null)
        };
    }

    /**
     * @param array<string, mixed> $args
     */
    private function add(World $world, TickingAreas $areas, CommandOutput $output, array $args) : void{
        if($areas->isFull()){
            throw $this->error("full", ["max" => TickingAreas::MAX_AREAS]);
        }

        $name = $args["name"] ?? $areas->nextName();

        if($areas->get($name) !== null){
            throw $this->error("taken", ["name" => $name]);
        }

        $preload = $args["preload"] ?? false;

        if(isset($args["circle"])){
            $radius = $args["radius"];

            if($radius < 0 || $radius > TickingAreas::MAX_RADIUS){
                throw $this->error("radius", ["max" => TickingAreas::MAX_RADIUS]);
            }

            [$x, $z] = self::clamp($args["center"]);
            $area = TickingArea::circle($name, $x, $z, $radius, $preload);
        }else{
            [$fromX, $fromZ] = self::clamp($args["from"]);
            [$toX, $toZ] = self::clamp($args["to"]);
            $area = TickingArea::box($name, $fromX, $fromZ, $toX, $toZ, $preload);
        }

        if($area->getChunkCount() > TickingAreas::MAX_CHUNKS){
            throw $this->error("tooLarge", ["max" => TickingAreas::MAX_CHUNKS, "chunks" => $area->getChunkCount()]);
        }

        $areas->add($area);

        if($preload){
            foreach($area->getChunks() as [$chunkX, $chunkZ]){
                $world->loadChunk($chunkX, $chunkZ);
            }
        }

        $output->successText($this->describe($area, "added.{shape}"));
        $output->successText($this->message("inuse", ["count" => $areas->count(), "max" => TickingAreas::MAX_AREAS]));
    }

    /**
     * @param list<TickingArea> $found
     */
    private function remove(TickingAreas $areas, CommandOutput $output, array $found) : void{
        $names = [];

        foreach($found as $area){
            $areas->remove($area);
            $names[] = $area->getName();
        }

        $output->successText($this->message("removed", ["names" => implode(", ", $names)]));
    }

    private function list(World $world, CommandOutput $output, bool $allWorlds) : void{
        $worlds = $allWorlds ? Server::getInstance()->getWorldManager()->getWorlds() : [$world];
        $listed = false;

        foreach($worlds as $listedWorld){
            $all = $listedWorld->getTickingAreas()->getAll();

            if($all === []){
                continue;
            }

            if(!$listed){
                $output->successText($this->message($allWorlds ? "list.all" : "list"));
                $listed = true;
            }

            if($allWorlds){
                $output->successText($this->message("list.world", ["world" => $listedWorld->getName()]));
            }

            foreach($all as $area){
                $output->successText($this->describe($area, $area->isPreload() ? "list.{shape}.preload" : "list.{shape}"));
            }
        }

        if(!$listed){
            throw $this->error("none");
        }
    }

    /**
     * @param list<TickingArea> $found
     */
    private function preload(World $world, TickingAreas $areas, CommandOutput $output, array $found, ?bool $preload) : void{
        foreach($found as $area){
            if($preload === null){
                $output->successText($this->message("preload.query", ["name" => $area->getName(), "preload" => $area->isPreload() ? "true" : "false"]));
                continue;
            }

            $area->setPreload($preload);

            if($preload){
                foreach($area->getChunks() as [$chunkX, $chunkZ]){
                    $world->loadChunk($chunkX, $chunkZ);
                }
            }

            $output->successText($this->message("preload.set", ["name" => $area->getName(), "preload" => $preload ? "true" : "false"]));
        }

        if($preload !== null){
            $areas->changed();
        }
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return list<TickingArea>
     */
    private function find(TickingAreas $areas, array $args) : array{
        if($areas->count() === 0){
            throw $this->error("none");
        }

        if(isset($args["position"])){
            [$x, $z] = self::clamp($args["position"]);
            $found = $areas->getAt($x, $z);

            if($found === []){
                throw $this->error("notfound.position", ["position" => $x . ", " . $args["position"]->getFloorY() . ", " . $z]);
            }

            return $found;
        }

        $area = $areas->get($args["name"]);

        if($area === null){
            throw $this->error("notfound.name", ["name" => $args["name"]]);
        }

        return [$area];
    }

    /**
     * @param array<string, string|int> $params
     */
    private function message(string $key, array $params = []) : string{
        return $this->text("command.tickingarea." . $key, $params);
    }

    /**
     * @param array<string, string|int> $params
     */
    private function error(string $key, array $params = []) : CommandException{
        return CommandException::text($this->message($key, $params));
    }

    private function describe(TickingArea $area, string $key) : string{
        $key = str_replace("{shape}", $area->isCircle() ? "circle" : "box", $key);

        return $this->message($key, [
            "name" => $area->getName(),
            "from" => self::formatCorner($area, false),
            "to" => self::formatCorner($area, true),
            "center" => self::formatCenter($area),
            "radius" => $area->getRadius() ?? 0
        ]);
    }

    /**
     * @return array{int, int}
     */
    private static function clamp(Vector3 $position) : array{
        return [
            max(-self::MAX_COORDINATE, min(self::MAX_COORDINATE, $position->getFloorX())),
            max(-self::MAX_COORDINATE, min(self::MAX_COORDINATE, $position->getFloorZ()))
        ];
    }

    private static function formatCorner(TickingArea $area, bool $max) : string{
        return $max
            ? (($area->getMaxChunkX() << 4) + 15) . ", 0, " . (($area->getMaxChunkZ() << 4) + 15)
            : ($area->getMinChunkX() << 4) . ", 0, " . ($area->getMinChunkZ() << 4);
    }

    private static function formatCenter(TickingArea $area) : string{
        $radius = $area->getRadius() ?? 0;

        return ((($area->getMinChunkX() + $radius) << 4) + 8) . ", 0, " . ((($area->getMinChunkZ() + $radius) << 4) + 8);
    }
}