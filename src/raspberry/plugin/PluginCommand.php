<?php

declare(strict_types=1);

namespace raspberry\plugin;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandSender;
use raspberry\command\CommandWords;
use raspberry\command\InvalidCommandSyntaxException;
use raspberry\command\ParameterBuilder;

use raspberry\command\argument\RawTextArgument;

use function array_map;

class PluginCommand extends CommandBuilder implements PluginOwned{

    private string $commandName;

    private PluginBase $plugin;

    private CommandExecutor $executor;

    /** @var array{description: string, permission: string|null, aliases: list<string>, usage: string} */
    private array $properties;

    /**
     * @param list<string> $aliases
     */
    public function __construct(string $name, PluginBase $plugin, string $description = "", ?string $permission = null, array $aliases = [], string $usage = ""){
        $this->commandName = $name;
        $this->plugin = $plugin;
        $this->executor = $plugin;
        $this->properties = ["description" => $description, "permission" => $permission, "aliases" => $aliases, "usage" => $usage];

        parent::__construct();
    }

    protected function configure() : void{
        $this->setName($this->commandName);
        $this->setDescription($this->properties["description"]);
        $this->setPermission($this->properties["permission"]);
        $this->setAliases($this->properties["aliases"]);

        $builder = new ParameterBuilder(0);
        $builder->addArgument(0, new RawTextArgument("args", true));
        $this->addParameterBuilder($builder);
    }

    public function getUsage(?CommandSender $sender = null) : array{
        return $this->properties["usage"] !== "" ? [$this->properties["usage"]] : parent::getUsage($sender);
    }

    public function getPlugin() : PluginBase{
        return $this->plugin;
    }

    public function getOwningPlugin() : PluginBase{
        return $this->plugin;
    }

    public function getExecutor() : CommandExecutor{
        return $this->executor;
    }

    public function setExecutor(CommandExecutor $executor) : void{
        $this->executor = $executor;
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        $words = CommandWords::split($args["args"] ?? "");

        if(!$this->executor->onCommand($sender, $this, array_map(fn(string $word) => CommandWords::unquote($word), $words))){
            throw new InvalidCommandSyntaxException($words === [] ? -1 : 0);
        }
    }
}