<?php

declare(strict_types=1);

namespace raspberry\permission;

use raspberry\utils\Config;

use function array_search;
use function is_string;
use function strtolower;

class OperatorList{

    private Config $config;

    public function __construct(string $path){
        $this->config = new Config($path, Config::JSON);
    }

    public function isOperator(string $xuid) : bool{
        return $xuid !== "" && $this->config->has($xuid);
    }

    public function add(string $xuid, string $name) : void{
        $this->config->set($xuid, $name);
        $this->config->save();
    }

    public function remove(string $xuid) : void{
        $this->config->remove($xuid);
        $this->config->save();
    }

    /**
     * Finds an operator's XUID by the name saved with it, ignoring case.
     */
    public function getXuidByName(string $name) : ?string{
        $names = [];

        foreach($this->getAll() as $xuid => $operatorName){
            $names[$xuid] = strtolower($operatorName);
        }

        $xuid = array_search(strtolower($name), $names, true);

        return $xuid === false ? null : (string) $xuid;
    }

    /**
     * @return array<string, string> XUID => last known name
     */
    public function getAll() : array{
        $operators = [];

        foreach($this->config->getAll() as $xuid => $name){
            $operators[(string) $xuid] = is_string($name) ? $name : "";
        }

        return $operators;
    }
}