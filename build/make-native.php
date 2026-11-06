<?php

declare(strict_types=1);

namespace raspberry\build;

use function copy;
use function dirname;
use function escapeshellarg;
use function fwrite;
use function is_dir;
use function is_file;
use function mkdir;
use function passthru;

use const PHP_OS_FAMILY;
use const STDERR;

if(PHP_SAPI !== "cli"){
    exit("Run this script from the command line.\n");
}

$root = dirname(__DIR__);
$name = match(PHP_OS_FAMILY){
    "Windows" => "raspberry_native.dll",
    "Darwin" => "libraspberry_native.dylib",
    default => "libraspberry_native.so"
};

passthru("cargo build --release --manifest-path " . escapeshellarg($root . "/native/Cargo.toml"), $status);

if($status !== 0){
    fwrite(STDERR, "cargo failed. Is Rust installed? Get it from https://rustup.rs\n");
    exit(1);
}

$built = $root . "/native/target/release/" . $name;
$folder = $root . "/bin/native";

if(!is_file($built)){
    fwrite(STDERR, "cargo finished but {$built} is missing\n");
    exit(1);
}

if(!is_dir($folder) && !mkdir($folder, 0777, true) && !is_dir($folder)){
    fwrite(STDERR, "Could not make {$folder}\n");
    exit(1);
}

if(!copy($built, $folder . "/" . $name)){
    fwrite(STDERR, "Could not copy the library to {$folder}, is the server still running?\n");
    exit(1);
}

echo "Built bin/native/{$name}\n";