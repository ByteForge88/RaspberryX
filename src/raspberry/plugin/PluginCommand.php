<?php

declare(strict_types=1);

namespace raspberry\plugin;

use raspberry\command\Command;
use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandReader;
use raspberry\command\CommandSender;

/**
 * A command from plugin.yml. It takes any words after its name and hands them to its executor,
 * the plugin unless setExecutor() gave it another one.
 */
class PluginCommand extends Command{

    private PluginBase $plugin;

    private CommandExecutor $executor;

    private string $usage;

    /**
     * @param list<string> $aliases
     */
    public function __construct(string $name, PluginBase $plugin, string $description = "", ?string $permission = null, array $aliases = [], string $usage = ""){
        parent::__construct($name, $description, $permission, $aliases);
        $this->plugin = $plugin;
        $this->executor = $plugin;
        $this->usage = $usage;

        $this->addOverload(CommandParameter::rawText("args", true));
    }

    public function getPlugin() : PluginBase{
        return $this->plugin;
    }

    public function getExecutor() : CommandExecutor{
        return $this->executor;
    }

    public function setExecutor(CommandExecutor $executor) : void{
        $this->executor = $executor;
    }

    /**
     * @return list<string>
     */
    public function getUsage() : array{
        return $this->usage === "" ? parent::getUsage() : [$this->usage];
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        if(!$this->plugin->isEnabled()){
            return;
        }

        $reader = new CommandReader($args["args"] ?? "");
        $words = [];

        while(!$reader->atEnd()){
            $words[] = $reader->readWord();
        }

        if($this->executor->onCommand($sender, $this, $words)){
            $output->addSuccess();
        }elseif($this->usage !== ""){
            $output->errorText($this->usage);
        }
    }
}