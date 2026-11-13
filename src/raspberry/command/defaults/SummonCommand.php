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

use raspberry\entity\EntityFactory;

use raspberry\player\Player;

use raspberry\world\Chunk;

use function mt_rand;
use function str_starts_with;
use function substr;

class SummonCommand extends Command{

    public function __construct(){
        parent::__construct("summon", "commands.summon.description", "raspberry.command.summon");

        $names = [];

        foreach(EntityFactory::getRegistered() as $name){
            $names[] = substr($name, 10);
            $names[] = $name;
        }

        $this->addOverload(
            CommandParameter::enum("entityType", new CommandEnum("EntityType", $names)),
            CommandParameter::position("spawnPos", true)
        );
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        $name = str_starts_with($args["entityType"], "minecraft:") ? $args["entityType"] : "minecraft:" . $args["entityType"];

        if(isset($args["spawnPos"])){
            $position = $args["spawnPos"];
        }elseif($sender instanceof Player){
            $position = $sender->getPosition();
        }else{
            throw new CommandException("commands.summon.failed");
        }

        if($position->y < Chunk::MIN_Y || $position->y > Chunk::MAX_Y){
            throw new CommandException("commands.summon.outOfWorld");
        }

        $world = $sender instanceof Player ? $sender->getWorld() : Server::getInstance()->getWorldManager()->getDefaultWorld();
        $world->loadChunk($position->getChunkX(), $position->getChunkZ());

        if(EntityFactory::create($name, $world, $position, (float) mt_rand(0, 359)) === null){
            throw new CommandException("commands.summon.failed");
        }

        $output->success("commands.summon.success");
    }
}