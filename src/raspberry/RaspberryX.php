<?php

declare(strict_types=1);

namespace raspberry;

use raspberry\wizard\SetupWizard;

use Symfony\Component\Filesystem\Path;

use Phar;
use RuntimeException;
use Throwable;

use function dirname;
use function extension_loaded;
use function fwrite;
use function is_file;

if(PHP_SAPI !== "cli"){
    exit("RaspberryX must run from the command line.\n");
}

try{
    if(PHP_VERSION_ID < 80512){
        throw new RuntimeException("RaspberryX requires PHP 8.5.12 or newer");
    }

    if(PHP_INT_SIZE !== 8){
        throw new RuntimeException("RaspberryX requires 64-bit PHP");
    }

    foreach(["phar", "webrtc", "openssl", "json", "zlib"] as $extension){
        if(!extension_loaded($extension)){
            throw new RuntimeException("Required PHP extension is missing: {$extension}");
        }
    }

    $codePath = dirname(__DIR__, 2);
    $autoload = $codePath . "/vendor/autoload.php";

    if(!is_file($autoload)){
        throw new RuntimeException("Composer dependencies are missing. " . "Install them before starting or building RaspberryX.");
    }

    require_once $autoload;

    $pharPath = Phar::running(false);

    $dataPath = $pharPath !== "" ? Path::getDirectory($pharPath) : $codePath;

    if(!(new SetupWizard(Path::join($dataPath, "server.properties")))->run()){
        exit(1);
    }

    $server = new Server($dataPath);
    $server->start();
}catch(Throwable $exception){
    fwrite(STDERR, "[FATAL] " . $exception::class . ": " . $exception->getMessage() . PHP_EOL . $exception->getTraceAsString() . PHP_EOL);

    exit(1);
}