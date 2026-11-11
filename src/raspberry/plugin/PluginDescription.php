<?php

declare(strict_types=1);

namespace raspberry\plugin;

use function array_map;
use function array_values;
use function explode;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function str_starts_with;
use function strtolower;
use function strtoupper;
use function trim;

class PluginDescription{

    private string $name;

    private string $main;

    private string $version;

    /** @var list<string> */
    private array $api;

    private string $description;

    /** @var list<string> */
    private array $authors;

    private string $website;

    /** @var list<string> */
    private array $depend;

    /** @var list<string> */
    private array $softDepend;

    /** @var list<string> */
    private array $loadBefore;

    private PluginLoadOrder $order;

    private string $srcNamespacePrefix;

    /** @var array<string, array{description: string, usage: string, permission: ?string, aliases: list<string>}> */
    private array $commands = [];

    /** @var array<string, mixed> */
    private array $permissions;

    /**
     * @param array<mixed> $data plugin.yml as read
     *
     * @throws PluginException when something is missing or wrong
     */
    public function __construct(array $data){
        $this->name = self::requireString($data, "name");

        if(preg_match('/^[A-Za-z0-9 _.-]+$/', $this->name) !== 1){
            throw new PluginException("The name \"{$this->name}\" may only have letters, digits, spaces, '_', '.' and '-'");
        }

        $this->main = trim(self::requireString($data, "main"), "\\");

        if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $this->main) !== 1){
            throw new PluginException("main must be a class with a namespace, like myplugin\\Main");
        }

        if(str_starts_with(strtolower($this->main), "raspberry\\")){
            throw new PluginException("main cannot be in the raspberry namespace, that one belongs to the server");
        }

        $this->version = self::requireString($data, "version");
        $this->api = self::stringList($data, "api");

        if($this->api === []){
            throw new PluginException("api is missing: the server API versions the plugin is made for, like 1.0.0");
        }

        $this->description = self::optionalString($data, "description");
        $this->authors = [...(isset($data["author"]) ? [self::optionalString($data, "author")] : []), ...self::stringList($data, "authors")];
        $this->website = self::optionalString($data, "website");
        $this->depend = self::stringList($data, "depend");
        $this->softDepend = self::stringList($data, "softdepend");
        $this->loadBefore = self::stringList($data, "loadbefore");

        $order = strtoupper(self::optionalString($data, "load", PluginLoadOrder::POSTWORLD->value));
        $this->order = PluginLoadOrder::tryFrom($order) ?? throw new PluginException("load must be STARTUP or POSTWORLD, not \"{$order}\"");

        $this->srcNamespacePrefix = trim(self::optionalString($data, "src-namespace-prefix"), "\\");

        $commands = $data["commands"] ?? [];

        if(!is_array($commands)){
            throw new PluginException("commands must be a list of command names");
        }

        foreach($commands as $command => $properties){
            $properties ??= [];

            if(!is_string($command) || !is_array($properties)){
                throw new PluginException("commands must be a list of command names, each with its description, usage, permission and aliases");
            }

            $this->commands[strtolower($command)] = [
                "description" => self::optionalString($properties, "description"),
                "usage" => self::optionalString($properties, "usage"),
                "permission" => isset($properties["permission"]) ? self::optionalString($properties, "permission") : null,
                "aliases" => self::stringList($properties, "aliases")
            ];
        }

        $permissions = $data["permissions"] ?? [];

        if(!is_array($permissions)){
            throw new PluginException("permissions must be a list of permission names");
        }

        $this->permissions = $permissions;
    }

    public function getName() : string{
        return $this->name;
    }

    /**
     * The name and version, like "MyPlugin v1.0.0".
     */
    public function getFullName() : string{
        return $this->name . " v" . $this->version;
    }

    /**
     * @return class-string
     */
    public function getMain() : string{
        /** @var class-string */
        return $this->main;
    }

    public function getVersion() : string{
        return $this->version;
    }

    /**
     * @return list<string>
     */
    public function getApi() : array{
        return $this->api;
    }

    public function getDescription() : string{
        return $this->description;
    }

    /**
     * @return list<string>
     */
    public function getAuthors() : array{
        return $this->authors;
    }

    public function getWebsite() : string{
        return $this->website;
    }

    /**
     * Plugins that must be there and enabled first.
     *
     * @return list<string>
     */
    public function getDepend() : array{
        return $this->depend;
    }

    /**
     * Plugins enabled first when they are there.
     *
     * @return list<string>
     */
    public function getSoftDepend() : array{
        return $this->softDepend;
    }

    /**
     * Plugins this one is enabled before, when they are there.
     *
     * @return list<string>
     */
    public function getLoadBefore() : array{
        return $this->loadBefore;
    }

    public function getOrder() : PluginLoadOrder{
        return $this->order;
    }

    /**
     * The namespace of the classes right in src/, "" when src/ holds the namespace folders.
     */
    public function getSrcNamespacePrefix() : string{
        return $this->srcNamespacePrefix;
    }

    /**
     * @return array<string, array{description: string, usage: string, permission: ?string, aliases: list<string>}>
     */
    public function getCommands() : array{
        return $this->commands;
    }

    /**
     * The permissions section, in the format PermissionManager::registerAll() reads.
     *
     * @return array<string, mixed>
     */
    public function getPermissions() : array{
        return $this->permissions;
    }

    /**
     * Whether one of the plugin's api versions works with $serverApi: the same major version
     * and nothing newer than the server has.
     */
    public function isCompatibleWith(string $serverApi) : bool{
        $server = self::versionParts($serverApi);

        foreach($this->api as $api){
            $wanted = self::versionParts($api);

            if($wanted[0] === $server[0] && ($wanted[1] < $server[1] || ($wanted[1] === $server[1] && $wanted[2] <= $server[2]))){
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{int, int, int}
     */
    private static function versionParts(string $version) : array{
        $parts = array_map(fn(string $part) => (int) $part, explode(".", explode("-", $version, 2)[0]));

        return [$parts[0] ?? 0, $parts[1] ?? 0, $parts[2] ?? 0];
    }

    /**
     * @param array<mixed> $data
     */
    private static function requireString(array $data, string $key) : string{
        $value = $data[$key] ?? null;

        if(is_int($value) || is_float($value)){
            $value = (string) $value;
        }

        if(!is_string($value) || trim($value) === ""){
            throw new PluginException("{$key} is missing");
        }

        return trim($value);
    }

    /**
     * @param array<mixed> $data
     */
    private static function optionalString(array $data, string $key, string $default = "") : string{
        $value = $data[$key] ?? $default;

        if(is_int($value) || is_float($value)){
            return (string) $value;
        }

        if(!is_string($value)){
            throw new PluginException("{$key} must be text");
        }

        return trim($value);
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private static function stringList(array $data, string $key) : array{
        $value = $data[$key] ?? [];

        if(!is_array($value)){
            $value = [$value];
        }

        $list = [];

        foreach(array_values($value) as $item){
            if(is_int($item) || is_float($item)){
                $item = (string) $item;
            }

            if(!is_string($item)){
                throw new PluginException("{$key} must be text or a list of text");
            }

            $list[] = trim($item);
        }

        return $list;
    }
}