<?php

declare(strict_types=1);

namespace raspberry\build;

use raspberry\ServerInfo;

use Phar;
use PharData;
use RuntimeException;
use Throwable;

use function count;
use function dirname;
use function fwrite;
use function hash_file;
use function is_dir;
use function is_file;
use function mkdir;
use function unlink;

use const PHP_EOL;
use const STDERR;
use const STDOUT;

if(PHP_SAPI !== "cli"){
    exit("Run this script from the command line.\n");
}

$root = dirname(__DIR__);

try{
    require_once $root . "/src/raspberry/ServerInfo.php";

    $folder = "RaspberryX-" . ServerInfo::VERSION;

    /** @var array<string, string> path in the zip => path on disk */
    $files = [];

    foreach(["RaspberryX.phar", "start.cmd", "start.sh", "README.md", "COPYING", "COPYING.LESSER"] as $required){
        if(!is_file($root . "/" . $required)){
            throw new RuntimeException("Required release file is missing: {$required}" . ($required === "RaspberryX.phar" ? ", run composer make-server first" : ""));
        }

        $files[$folder . "/" . $required] = $root . "/" . $required;
    }

    $libraries = 0;

    foreach(["Windows" => "raspberry_native.dll", "Linux" => "libraspberry_native.so"] as $system => $library){
        $path = $root . "/bin/native/" . $library;

        if(!is_file($path)){
            fwrite(STDERR, "[WARNING] bin/native/{$library} is missing, the release will not run on {$system}. Build it there with composer make-native" . PHP_EOL);
            continue;
        }

        $files[$folder . "/bin/native/" . $library] = $path;
        $libraries++;
    }

    if($libraries === 0){
        throw new RuntimeException("No native library in bin/native, run composer make-native first");
    }

    $output = $root . "/release";

    if(!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)){
        throw new RuntimeException("Could not make {$output}");
    }

    $zipPath = $output . "/" . $folder . ".zip";

    if(is_file($zipPath) && !unlink($zipPath)){
        throw new RuntimeException("Could not replace {$zipPath}, is it open somewhere?");
    }

    $zip = new PharData($zipPath, 0, null, Phar::ZIP);

    foreach($files as $name => $path){
        $zip->addFile($path, $name);
    }

    unset($zip);

    fwrite(STDOUT, "Built {$zipPath} (" . count($files) . " files)" . PHP_EOL . "SHA-256: " . hash_file("sha256", $zipPath) . PHP_EOL);
}catch(Throwable $exception){
    fwrite(STDERR, "[BUILD ERROR] " . $exception->getMessage() . PHP_EOL);

    exit(1);
}