<?php

declare(strict_types=1);

namespace raspberry\build;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Filesystem\Filesystem;

use Phar;
use ArrayIterator;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

use function bin2hex;
use function count;
use function dirname;
use function extension_loaded;
use function fwrite;
use function in_array;
use function is_file;
use function ksort;
use function random_bytes;
use function unlink;

if(PHP_SAPI !== "cli"){
    exit("Run this script from the command line.\n");
}

$root = dirname(__DIR__);
$temporaryPath = null;
$phar = null;

try{
    if(!extension_loaded("phar")){
        throw new RuntimeException("The PHP Phar extension is required");
    }

    if(!Phar::canWrite()){
        throw new RuntimeException("PHAR creation is disabled. Run composer make-server.");
    }

    $autoload = $root . "/vendor/autoload.php";

    if(!is_file($autoload)){
        throw new RuntimeException("Dependencies are missing. Run composer install first.");
    }

    require_once $autoload;

    foreach([
        "src/raspberry/RaspberryX.php",
        "src/raspberry/Server.php",
        "resources/vanilla/block_states.json",
        "composer.json",
        "composer.lock",
        "LICENSE"
    ] as $required){
        if(!is_file(Path::join($root, $required))){
            throw new RuntimeException("Required build file is missing: {$required}");
        }
    }

    /** @var array<string, string> $files */
    $files = [];

    foreach(["src", "vendor", "resources"] as $directory){
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator(Path::join($root, $directory), FilesystemIterator::SKIP_DOTS),
            static function(SplFileInfo $file) : bool{
                if($file->isLink()){
                    throw new RuntimeException("Build inputs must be regular files/directories: " . $file->getPathname());
                }
                return !$file->isDir() || !in_array($file->getFilename(), [".git", ".svn", ".hg"], true);
            }
        ));

        foreach($iterator as $file){
            if($file->isFile()){
                $relative = Path::makeRelative($file->getPathname(), $root);
                $files[$relative] = $file->getPathname();
            }
        }
    }

    foreach(["composer.json", "composer.lock", "LICENSE"] as $file){
        $files[$file] = Path::join($root, $file);
    }

    ksort($files);

    $outputPath = Path::join($root, "RaspberryX.phar");

    $temporaryPath = Path::join($root, ".RaspberryX-build-" . bin2hex(random_bytes(6)) . ".phar");

    $phar = new Phar($temporaryPath);
    $phar->startBuffering();
    $phar->setSignatureAlgorithm(Phar::SHA256);

    $phar->setStub(<<<'PHP'
<?php

declare(strict_types=1);

Phar::mapPhar();

require "phar://" . __FILE__ . "/src/raspberry/RaspberryX.php";

__HALT_COMPILER();
PHP);

    $phar->buildFromIterator(new ArrayIterator($files));
    $phar->stopBuffering();

    unset($phar);

    $filesystem = new Filesystem();
    $filesystem->rename($temporaryPath, $outputPath, true);

    $temporaryPath = null;

    fwrite(STDOUT, "Built {$outputPath} (" . count($files) . " files)" . PHP_EOL);
}catch(Throwable $exception){
    unset($phar);

    if($temporaryPath !== null && is_file($temporaryPath)){
        @unlink($temporaryPath);
    }

    fwrite(STDERR, "[BUILD ERROR] " . $exception->getMessage() . PHP_EOL);

    exit(1);
}