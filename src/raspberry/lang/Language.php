<?php

declare(strict_types=1);

namespace raspberry\lang;

use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

use function array_keys;
use function dirname;
use function file_get_contents;
use function implode;
use function is_dir;
use function is_file;
use function ksort;
use function preg_match;
use function preg_split;
use function scandir;
use function str_starts_with;
use function strpos;
use function strtr;
use function substr;
use function trim;

/**
 * Translated messages from resources/lang/<code>.ini, e.g. en_US.ini.
 *
 * File format: one "key=value" per line, "#" starts a comment.
 * Values use named placeholders: "{player} joined the game".
 * Missing keys fall back to English, then to the key itself.
 */
final class Language{

    public const FALLBACK = "en_US";

    private string $code;

    /** @var array<string, string> */
    private array $messages;

    /** @var array<string, string> */
    private array $fallback;

    public function __construct(string $code){
        if(!self::isAvailable($code)){
            throw new InvalidArgumentException("Unknown language '{$code}', available: " . implode(", ", array_keys(self::getAvailable())));
        }

        $this->code = $code;
        $this->messages = self::load($code);
        $this->fallback = $code === self::FALLBACK ? $this->messages : self::load(self::FALLBACK);
    }

    public function getCode() : string{
        return $this->code;
    }

    public function getName() : string{
        return $this->messages["language.name"] ?? $this->code;
    }

    /**
     * @param array<string, string|int|float> $params placeholder => value
     */
    public function translate(string $key, array $params = []) : string{
        $message = $this->messages[$key] ?? $this->fallback[$key] ?? $key;

        if($params === []){
            return $message;
        }

        $replacements = [];

        foreach($params as $name => $value){
            $replacements["{" . $name . "}"] = (string) $value;
        }

        return strtr($message, $replacements);
    }

    public static function isAvailable(string $code) : bool{
        return preg_match('/^[a-z]{2}_[A-Z]{2}$/D', $code) === 1 && is_file(self::path($code));
    }

    /**
     * @return array<string, string> code => display name, sorted by code
     */
    public static function getAvailable() : array{
        $directory = self::directory();
        $languages = [];

        if(!is_dir($directory)){
            return [];
        }

        foreach(scandir($directory) ?: [] as $file){
            if(preg_match('/^([a-z]{2}_[A-Z]{2})\.ini$/D', $file, $matches) === 1){
                $languages[$matches[1]] = self::load($matches[1])["language.name"] ?? $matches[1];
            }
        }

        ksort($languages);

        return $languages;
    }

    /**
     * @return array<string, string>
     */
    private static function load(string $code) : array{
        $path = self::path($code);
        $contents = file_get_contents($path);

        if($contents === false){
            throw new RuntimeException("Cannot read language file: {$path}");
        }

        $messages = [];

        foreach(preg_split("/\r\n|\n|\r/", $contents) as $number => $line){
            $line = trim($line);

            if($line === "" || str_starts_with($line, "#")){
                continue;
            }

            $separator = strpos($line, "=");

            if($separator === false){
                throw new UnexpectedValueException("Expected key=value in {$path} on line " . ($number + 1));
            }

            $messages[trim(substr($line, 0, $separator))] = trim(substr($line, $separator + 1));
        }

        return $messages;
    }

    private static function directory() : string{
        return dirname(__DIR__, 3) . "/resources/lang";
    }

    private static function path(string $code) : string{
        return self::directory() . "/" . $code . ".ini";
    }
}