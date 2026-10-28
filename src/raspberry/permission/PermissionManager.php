<?php

declare(strict_types=1);

namespace raspberry\permission;

use InvalidArgumentException;

use function is_array;
use function is_string;
use function ksort;

class PermissionManager{

    /** @var array<string, Permission> */
    private array $permissions = [];

    public function register(Permission $permission) : void{
        $name = $permission->getName();

        if(isset($this->permissions[$name])){
            throw new InvalidArgumentException("Permission \"{$name}\" is already registered");
        }

        $this->permissions[$name] = $permission;
        ksort($this->permissions);
    }

    /**
     * Registers permissions from a list in the plugin.yml format:
     *
     *   some.permission:
     *     description: Lets the player do something
     *     default: true | op | console
     *
     * @param array<string, mixed> $permissions
     *
     * @return list<Permission> the registered permissions
     */
    public function registerAll(array $permissions) : array{
        $registered = [];

        foreach($permissions as $name => $data){
            if(!is_array($data)){
                throw new InvalidArgumentException("Permission \"{$name}\" must be a list with a default and an optional description");
            }

            $description = $data["description"] ?? "";

            if(!is_string($description)){
                throw new InvalidArgumentException("The description of permission \"{$name}\" must be text");
            }

            $default = isset($data["default"]) ? PermissionLevel::fromValue($data["default"]) : PermissionLevel::OPERATOR;

            $permission = new Permission((string) $name, $description, $default);
            $this->register($permission);
            $registered[] = $permission;
        }

        return $registered;
    }

    public function unregister(string $name) : void{
        unset($this->permissions[$name]);
    }

    public function get(string $name) : ?Permission{
        return $this->permissions[$name] ?? null;
    }

    /**
     * @return array<string, Permission> sorted by name
     */
    public function getAll() : array{
        return $this->permissions;
    }

    /**
     * Who gets $name when nothing overrides it; operator-only when it was never registered.
     */
    public function getDefault(string $name) : PermissionLevel{
        return ($this->permissions[$name] ?? null)?->getDefault() ?? PermissionLevel::OPERATOR;
    }
}