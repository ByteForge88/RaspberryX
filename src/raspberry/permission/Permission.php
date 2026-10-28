<?php

declare(strict_types=1);

namespace raspberry\permission;

use InvalidArgumentException;

use function preg_match;

class Permission{

    private string $name;

    private string $description;

    private PermissionLevel $default;

    public function __construct(string $name, string $description = "", PermissionLevel $default = PermissionLevel::OPERATOR){
        if(preg_match('/^[a-z0-9_\-]+(\.[a-z0-9_\-]+)*$/D', $name) !== 1){
            throw new InvalidArgumentException("Invalid permission name \"{$name}\": use lowercase letters, digits, '_' and '-', separated by dots");
        }

        $this->name = $name;
        $this->description = $description;
        $this->default = $default;
    }

    public function getName() : string{
        return $this->name;
    }

    public function getDescription() : string{
        return $this->description;
    }

    public function getDefault() : PermissionLevel{
        return $this->default;
    }
}