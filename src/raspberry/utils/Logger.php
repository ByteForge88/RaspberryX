<?php

declare(strict_types=1);

namespace raspberry\utils;

use function date;
use function fclose;
use function fopen;
use function function_exists;
use function fwrite;
use function sapi_windows_vt100_support;
use function stream_isatty;
use function str_replace;

final class Logger{

    public const DEBUG = "DEBUG";
    public const INFO = "INFO";
    public const NOTICE = "NOTICE";
    public const WARNING = "WARNING";
    public const ERROR = "ERROR";
    public const CRITICAL = "CRITICAL";
    public const INGAME = "IN-GAME";

    private const COLORS = [
        self::DEBUG => "\e[90m",
        self::INFO => "",
        self::NOTICE => "\e[96m",
        self::WARNING => "\e[93m",
        self::ERROR => "\e[91m",
        self::CRITICAL => "\e[1;91m",
        self::INGAME => "\e[0;36m"
    ];

    private const RESET = "\e[0m";

    private bool $debug = false;

    private bool $colors;

    /** @var resource|null */
    private $file = null;

    public function __construct(?string $logFile = null){
        $this->colors = self::enableColors();

        if($logFile !== null){
            $handle = @fopen($logFile, "ab");
            $this->file = $handle === false ? null : $handle;
        }
    }

    public function setDebug(bool $debug) : void{
        $this->debug = $debug;
    }

    public function isDebug() : bool{
        return $this->debug;
    }

    public function debug(string $message) : void{
        if($this->debug){
            $this->log(self::DEBUG, $message);
        }
    }

    public function info(string $message) : void{
        $this->log(self::INFO, $message);
    }

    public function notice(string $message) : void{
        $this->log(self::NOTICE, $message);
    }

    public function warning(string $message) : void{
        $this->log(self::WARNING, $message);
    }

    public function error(string $message) : void{
        $this->log(self::ERROR, $message);
    }

    public function critical(string $message) : void{
        $this->log(self::CRITICAL, $message);
    }

    /**
     * $message may contain Minecraft colour codes (TextFormat): they are shown as colours in the
     * console when it supports them, and removed everywhere else.
     */
    public function log(string $level, string $message) : void{
        $prefix = "\e[0;35m" . "[" . date("H:i:s") . "]\e[0m [{$level}]: ";

        if($this->colors){
            $color = self::COLORS[$level] ?? "";

            $text = str_replace(self::RESET, self::RESET . $color, TextFormat::toAnsi($message));

            fwrite(STDOUT, $color . $prefix . $text . self::RESET . PHP_EOL);
        }else{
            fwrite(STDOUT, $prefix . TextFormat::clean($message) . PHP_EOL);
        }

        if($this->file !== null){
            fwrite($this->file, "\e[0;35m" . "[" . date("H:i:s") . "]\e[0m [{$level}]: " . TextFormat::clean($message) . PHP_EOL);
        }
    }

    public function close() : void{
        if($this->file !== null){
            fclose($this->file);
            $this->file = null;
        }
    }

    private static function enableColors() : bool{
        if(!stream_isatty(STDOUT)){
            return false;
        }

        if(PHP_OS_FAMILY === "Windows"){
            return function_exists("sapi_windows_vt100_support") && sapi_windows_vt100_support(STDOUT, true);
        }

        return true;
    }
}