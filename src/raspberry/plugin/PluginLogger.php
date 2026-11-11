<?php

declare(strict_types=1);

namespace raspberry\plugin;

use raspberry\utils\Logger;

/**
 * The server's log with the plugin's name in front: "[MyPlugin] message".
 */
class PluginLogger{

    private Logger $logger;

    private string $prefix;

    public function __construct(Logger $logger, string $name){
        $this->logger = $logger;
        $this->prefix = "[{$name}] ";
    }

    public function isDebug() : bool{
        return $this->logger->isDebug();
    }

    public function debug(string $message) : void{
        $this->logger->debug($this->prefix . $message);
    }

    public function info(string $message) : void{
        $this->logger->info($this->prefix . $message);
    }

    public function notice(string $message) : void{
        $this->logger->notice($this->prefix . $message);
    }

    public function warning(string $message) : void{
        $this->logger->warning($this->prefix . $message);
    }

    public function error(string $message) : void{
        $this->logger->error($this->prefix . $message);
    }

    public function critical(string $message) : void{
        $this->logger->critical($this->prefix . $message);
    }

    /**
     * @param string $level
     */
    public function log(string $level, string $message) : void{
        $this->logger->log($level, $this->prefix . $message);
    }
}