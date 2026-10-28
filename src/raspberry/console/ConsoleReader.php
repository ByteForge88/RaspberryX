<?php

declare(strict_types=1);

namespace raspberry\console;

use raspberry\utils\Logger;

use React\EventLoop\Loop;

use Closure;

use function fclose;
use function feof;
use function fread;
use function is_resource;
use function preg_match;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function register_shutdown_function;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use function trim;

use const PHP_BINARY;

/**
 * Reads commands typed into the console without blocking the server.
 *
 * Windows cannot wait on STDIN without blocking, so a small child PHP process does the blocking
 * read and forwards each line over a local TCP socket, which the event loop can wait on everywhere.
 */
class ConsoleReader{

    /** code run by the child process; argv[1] is the port to connect to */
    private const CHILD_CODE = <<<'PHP'
        $socket = stream_socket_client('tcp://127.0.0.1:' . (int) $argv[1], $errno, $error, 5);
        if($socket === false){
            exit(1);
        }
        while(($line = fgets(STDIN)) !== false){
            if(@fwrite($socket, rtrim($line, chr(13) . chr(10)) . chr(10)) === false){
                break;
            }
        }
        PHP;

    private const MAX_LINE_LENGTH = 65536;

    private Logger $logger;

    /** @var Closure(string) : void */
    private Closure $onLine;

    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $socket = null;

    private string $buffer = "";

    /**
     * @param Closure(string) : void $onLine called with every non-empty line typed into the console
     */
    public function __construct(Logger $logger, Closure $onLine){
        $this->logger = $logger;
        $this->onLine = $onLine;
    }

    public function start() : void{
        $server = @stream_socket_server("tcp://127.0.0.1:0", $errno, $error);

        if($server === false){
            $this->logger->warning("Console commands are unavailable: cannot open a local socket ({$error})");
            return;
        }

        $address = (string) stream_socket_get_name($server, false);
        $port = substr($address, strrpos($address, ":") + 1);

        $process = proc_open([PHP_BINARY, "-n", "-r", self::CHILD_CODE, $port], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);

        if($process === false){
            fclose($server);
            $this->logger->warning("Console commands are unavailable: cannot start the console reader process");
            return;
        }

        $this->process = $process;

        $socket = @stream_socket_accept($server, 5);
        fclose($server);

        if($socket === false){
            $this->close();
            $this->logger->warning("Console commands are unavailable: the console reader process did not connect");
            return;
        }

        $this->socket = $socket;
        stream_set_blocking($socket, false);

        Loop::get()->addReadStream($socket, function() : void{
            $this->read();
        });

        register_shutdown_function(function() : void{
            $this->close();
        });
    }

    public function close() : void{
        if($this->socket !== null){
            Loop::get()->removeReadStream($this->socket);

            if(is_resource($this->socket)){
                fclose($this->socket);
            }

            $this->socket = null;
        }

        if($this->process !== null){
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }

    private function read() : void{
        $data = $this->socket === null ? false : fread($this->socket, 8192);

        if($data === false || ($data === "" && feof($this->socket))){
            $this->logger->debug("Console input closed");
            $this->close();
            return;
        }

        $this->buffer .= $data;

        while(($end = strpos($this->buffer, "\n")) !== false){
            $line = trim(substr($this->buffer, 0, $end));
            $this->buffer = substr($this->buffer, $end + 1);

            if($line === ""){
                continue;
            }

            if(preg_match("//u", $line) !== 1){
                $this->logger->warning("Ignored a console line that is not valid UTF-8");
                continue;
            }

            ($this->onLine)($line);
        }

        if(strlen($this->buffer) > self::MAX_LINE_LENGTH){
            $this->buffer = "";
        }
    }
}