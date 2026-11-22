<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\Server;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandException;
use raspberry\command\CommandSender;
use raspberry\command\ParameterBuilder;

use raspberry\command\argument\EntityTypeArgument;
use raspberry\command\argument\PositionArgument;

use raspberry\entity\EntityFactory;

use raspberry\lang\Translation;

use raspberry\player\Player;

use raspberry\world\Chunk;

use function mt_rand;

class SummonCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("summon");
        $this->setDescription("commands.summon.description");
        $this->setPermission("raspberry.command.summon");

        $builder = new ParameterBuilder(0);
        $builder->addArgument(0, new EntityTypeArgument("entityType"));
        $builder->addArgument(1, new PositionArgument("spawnPos", true));
        $this->addParameterBuilder($builder);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
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

        if(EntityFactory::create($args["entityType"], $world, $position, (float) mt_rand(0, 359)) === null){
            throw new CommandException("commands.summon.failed");
        }

        $this->success(new Translation("commands.summon.success"));
    }
}