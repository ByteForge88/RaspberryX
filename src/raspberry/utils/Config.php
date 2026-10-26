<?php

declare(strict_types=1);

namespace raspberry\utils;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Filesystem\Filesystem;

use InvalidArgumentException;
use OutOfBoundsException;
use RuntimeException;
use UnexpectedValueException;

use function array_key_exists;
use function array_diff_key;
use function array_keys;
use function array_replace;
use function file_exists;
use function file_get_contents;
use function filter_var;
use function getcwd;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function pathinfo;
use function preg_match;
use function preg_split;
use function restore_error_handler;
use function serialize;
use function set_error_handler;
use function str_starts_with;
use function strpbrk;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use function unserialize;

class Config{

    public const DETECT = -1;
    public const PROPERTIES = 0;
    public const CNF = Config::PROPERTIES;
    public const JSON = 1;
    public const YAML = 2;
    public const SERIALIZED = 3;
    public const ENUM = 4;
    public const ENUMERATION = Config::ENUM;

    private string $path;
    private int $type;

    private Filesystem $filesystem;

    /** @var array<string|int, mixed> */
    private array $values = [];

    /**
     * @param array<string|int, mixed> $defaults
     */
    public function __construct(string $path, int $type = self::DETECT, array $defaults = []){
        if(trim($path) === ""){
            throw new InvalidArgumentException("Configuration path cannot be empty");
        }

        $directory = getcwd();

        if($directory === false){
            throw new RuntimeException("Cannot determine the working directory");
        }

        $this->path = Path::makeAbsolute($path, $directory);
        $this->type = $type === self::DETECT ? self::detectType($this->path) : $type;

        if(!in_array($this->type, [
            self::PROPERTIES,
            self::JSON,
            self::YAML,
            self::SERIALIZED,
            self::ENUM
        ], true)){
            throw new InvalidArgumentException("Unsupported configuration type");
        }

        $this->filesystem = new Filesystem();
        $this->values = $this->normalize($defaults);

        if(!file_exists($this->path)){
            $this->save();
            return;
        }

        $contents = file_get_contents($this->path);

        if($contents === false){
            throw new RuntimeException("Cannot read configuration: {$this->path}");
        }

        if($this->type !== self::SERIALIZED && str_starts_with($contents, "\xef\xbb\xbf")){
            $contents = substr($contents, 3);
        }

        $loaded = $this->normalize($this->decode($contents));

        $this->values = array_replace($this->values, $loaded);

        if(array_diff_key($defaults, $loaded) !== []){
            $this->save();
        }
    }

    private static function detectType(string $path) : int{
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match($extension){
            "properties", "cnf" => self::PROPERTIES,
            "js", "json" => self::JSON,
            "yml", "yaml" => self::YAML,
            "sl" => self::SERIALIZED,
            "txt", "list", "enum" => self::ENUM,
            default => throw new InvalidArgumentException("Cannot detect configuration type for '{$path}'")
        };
    }

    public function getPath() : string{
        return $this->path;
    }

    public function getType() : int{
        return $this->type;
    }

    public function has(string|int $key) : bool{
        return array_key_exists($key, $this->values);
    }

    public function get(string|int $key) : mixed{
        if(!$this->has($key)){
            throw new OutOfBoundsException("Unknown configuration property: {$key}");
        }

        return $this->values[$key];
    }

    public function getString(string|int $key) : string{
        $value = $this->get($key);

        if(!is_string($value)){
            throw new UnexpectedValueException("Property '{$key}' must be a string");
        }

        return $value;
    }

    public function getInt(string|int $key) : int{
        $value = $this->get($key);

        if(is_int($value)){
            return $value;
        }

        if(!is_string($value) || preg_match("/^-?(0|[1-9][0-9]*)$/D", $value) !== 1){
            throw new UnexpectedValueException("Property '{$key}' must be an integer");
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        if($integer === false){
            throw new UnexpectedValueException("Property '{$key}' is outside the supported integer range");
        }

        return $integer;
    }

    public function getBool(string|int $key) : bool{
        $value = $this->get($key);

        if(is_bool($value)){
            return $value;
        }

        if(is_string($value)){
            return match(strtolower($value)){
                "true" => true,
                "false" => false,
                default => throw new UnexpectedValueException("Property '{$key}' must be true or false")
            };
        }

        throw new UnexpectedValueException("Property '{$key}' must be a boolean");
    }

    public function getArray(string|int $key) : array{
        $value = $this->get($key);

        if(!is_array($value)){
            throw new UnexpectedValueException("Property '{$key}' must be an array");
        }

        return $value;
    }

    public function getAll() : array{
        return $this->values;
    }

    public function set(string|int $key, mixed $value) : void{
        $normalized = $this->normalize([$key => $value]);

        $this->values[$key] = $normalized[$key];
    }

    public function remove(string|int $key) : void{
        unset($this->values[$key]);
    }

    public function save() : void{
        $contents = match($this->type){
            self::PROPERTIES => $this->encodeProperties(),
            self::JSON => json_encode(
                $this->values,
                JSON_PRETTY_PRINT |
                JSON_UNESCAPED_SLASHES |
                JSON_UNESCAPED_UNICODE |
                JSON_PRESERVE_ZERO_FRACTION |
                JSON_THROW_ON_ERROR) . "\n",
            self::YAML => Yaml::dump($this->values, 8, 2, Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE),
            self::SERIALIZED => serialize($this->values),
            self::ENUM => $this->values === [] ? "" : implode("\n", array_keys($this->values)) . "\n"
        };

        $this->filesystem->mkdir(Path::getDirectory($this->path));
        $this->filesystem->dumpFile($this->path, $contents);
    }

    private function decode(string $contents) : array{
        $data = match($this->type){
            self::PROPERTIES => $this->decodeLines($contents, false),
            self::ENUM => $this->decodeLines($contents, true),
            self::JSON => json_decode($contents, true, 64, JSON_THROW_ON_ERROR),
            self::YAML => Yaml::parse($contents, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE),
            self::SERIALIZED => $this->decodeSerialized($contents)
        };

        if($this->type === self::YAML && $data === null){
            return [];
        }

        if(!is_array($data)){
            throw new UnexpectedValueException("Configuration root must be a map or list: {$this->path}");
        }

        return $data;
    }

    private function decodeLines(string $contents, bool $enumeration) : array{
        $values = [];

        foreach(preg_split("/\r\n|\n|\r/", $contents) as $index => $line){
            $line = trim($line);

            if($line === "" || str_starts_with($line, "#")){
                continue;
            }

            if($enumeration){
                $values[$line] = true;
                continue;
            }

            $separator = strpos($line, "=");

            if($separator === false){
                throw new UnexpectedValueException("Expected key=value in {$this->path} on line " . ($index + 1));
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));

            if(array_key_exists($key, $values)){
                throw new UnexpectedValueException("Duplicate property '{$key}' in {$this->path}");
            }

            $values[$key] = $value;
        }

        return $values;
    }

    private function decodeSerialized(string $contents) : mixed{
        set_error_handler(static function(int $severity, string $message) : never{
                throw new UnexpectedValueException($message);
            }
        );

        try{
            return unserialize($contents, [
                "allowed_classes" => false,
                "max_depth" => 64
            ]);
        }finally{
            restore_error_handler();
        }
    }

    private function encodeProperties() : string{
        $contents = "# RaspberryX configuration - IN TESTING\n";

        foreach($this->values as $key => $value){
            $contents .= "{$key}={$value}\n";
        }

        return $contents;
    }

    private function normalize(array $values) : array{
        self::validateValue($values);

        foreach($values as $key => $value){
            $name = (string) $key;

            if($this->type === self::PROPERTIES){
                if(preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D', $name) !== 1){
                    throw new InvalidArgumentException("Invalid property name: {$name}");
                }

                if(!is_scalar($value)){
                    throw new InvalidArgumentException("Property '{$name}' must contain a scalar value");
                }

                $text = is_bool($value) ? ($value ? "true" : "false") : (string) $value;

                if(strpbrk($text, "\r\n\0") !== false){
                    throw new InvalidArgumentException("Property '{$name}' cannot contain line breaks or null bytes");
                }

                $values[$key] = trim($text);
            }elseif($this->type === self::ENUM){
                if($name === "" ||
                    trim($name) !== $name ||
                    str_starts_with($name, "#") ||
                    strpbrk($name, "\r\n\0") !== false
                ){
                    throw new InvalidArgumentException("Invalid enumeration entry: {$name}");
                }

                if($value !== true){
                    throw new InvalidArgumentException("Enumeration entries must use entry => true");
                }
            }
        }

        return $values;
    }

    private static function validateValue(mixed $value, int $depth = 0) : void{
        if($depth > 64){
            throw new InvalidArgumentException("Configuration nesting exceeds 64 levels");
        }

        if(is_array($value)){
            foreach($value as $entry){
                self::validateValue($entry, $depth + 1);
            }

            return;
        }

        if($value !== null && !is_scalar($value)){
            throw new InvalidArgumentException("Configuration values cannot contain objects or resources");
        }

        if(is_float($value) && !is_finite($value)){
            throw new InvalidArgumentException("Configuration numbers must be finite");
        }
    }
}