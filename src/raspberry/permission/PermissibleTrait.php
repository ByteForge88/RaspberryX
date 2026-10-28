<?php

declare(strict_types=1);

namespace raspberry\permission;

trait PermissibleTrait{

    /** @var array<string, bool> permission => granted */
    private array $permissionOverrides = [];

    abstract public function getPermissionLevel() : PermissionLevel;

    abstract protected function getPermissionManager() : PermissionManager;

    public function hasPermission(string $name) : bool{
        return $this->permissionOverrides[$name] ?? $this->getPermissionLevel()->includes($this->getPermissionManager()->getDefault($name));
    }

    public function setPermission(string $name, bool $value) : void{
        $this->permissionOverrides[$name] = $value;
    }

    public function unsetPermission(string $name) : void{
        unset($this->permissionOverrides[$name]);
    }
}