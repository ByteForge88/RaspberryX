<?php

declare(strict_types=1);

namespace raspberry\permission;

interface Permissible{

    /**
     * The level this holder is at: public for players, operator for ops, console for the console.
     */
    public function getPermissionLevel() : PermissionLevel;

    public function hasPermission(string $name) : bool;

    /**
     * Grants ($value true) or denies ($value false) one permission for this holder only, whatever its default.
     */
    public function setPermission(string $name, bool $value) : void;

    /**
     * Removes a grant or denial from setPermission(), so the permission's default applies again.
     */
    public function unsetPermission(string $name) : void;

}