<?php

declare(strict_types=1);

namespace raspberry\build;

use raspberry\plugin\PluginDescription;
use raspberry\plugin\PluginException;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

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
use function getcwd;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function ksort;
use function preg_replace;
use function random_bytes;
use function str_starts_with;
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
        throw new RuntimeException("PHAR creation is disabled. Run composer make-plugin, or php -d phar.readonly=0 build/make-plugin.php");
    }

    $autoload = $root . "/vendor/autoload.php";

    if(!is_file($autoload)){
        throw new RuntimeException("Dependencies are missing. Run composer install first.");
    }

    require_once $autoload;

    $source = $argv[1] ?? throw new RuntimeException("Usage: composer make-plugin -- <plugin folder> [output folder]");
    $source = Path::makeAbsolute($source, getcwd() ?: $root);

    if(!is_dir($source)){
        throw new RuntimeException("There is no folder {$source}");
    }

    if(!is_file(Path::join($source, "plugin.yml"))){
        throw new RuntimeException("{$source} has no plugin.yml");
    }

    if(!is_dir(Path::join($source, "src"))){
        throw new RuntimeException("{$source} has no src/ folder");
    }

    //checked the same way the server checks it, so a broken plugin.yml never gets built
    try{
        $data = Yaml::parseFile(Path::join($source, "plugin.yml"));
    }catch(ParseException $exception){
        throw new RuntimeException("plugin.yml is not valid YAML: " . $exception->getMessage());
    }

    if(!is_array($data)){
        throw new RuntimeException("plugin.yml is empty");
    }

    try{
        $description = new PluginDescription($data);
    }catch(PluginException $exception){
        throw new RuntimeException("plugin.yml: " . $exception->getMessage());
    }

    $output = isset($argv[2]) ? Path::makeAbsolute($argv[2], getcwd() ?: $root) : dirname($source);

    if(!is_dir($output)){
        throw new RuntimeException("There is no folder {$output}");
    }

    /** @var array<string, string> $files */
    $files = ["plugin.yml" => Path::join($source, "plugin.yml")];

    foreach(["src", "resources"] as $directory){
        $path = Path::join($source, $directory);

        if(!is_dir($path)){
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            static function(SplFileInfo $file) : bool{
                if($file->isLink()){
                    throw new RuntimeException("Plugin files must be regular files and folders: " . $file->getPathname());
                }

                return !$file->isDir() || !in_array($file->getFilename(), [".git", ".svn", ".hg", ".idea", ".vscode"], true);
            }
        ));

        foreach($iterator as $file){
            if($file->isFile()){
                $files[Path::makeRelative($file->getPathname(), $source)] = $file->getPathname();
            }
        }
    }

    foreach(["LICENSE", "README.md"] as $file){
        if(is_file(Path::join($source, $file))){
            $files[$file] = Path::join($source, $file);
        }
    }

    ksort($files);

    $name = preg_replace('/[^A-Za-z0-9_.-]/', "_", $description->getName()) ?? "plugin";
    $outputPath = Path::join($output, $name . ".phar");

    if(str_starts_with(Path::canonicalize($outputPath), Path::canonicalize($source) . "/")){
        throw new RuntimeException("The .phar cannot go inside the plugin folder it is built from");
    }

    $temporaryPath = Path::join($output, ".{$name}-build-" . bin2hex(random_bytes(6)) . ".phar");

    $phar = new Phar($temporaryPath);
    $phar->startBuffering();
    $phar->setSignatureAlgorithm(Phar::SHA256);
    $phar->setMetadata([
        "name" => $description->getName(),
        "version" => $description->getVersion(),
        "main" => $description->getMain(),
        "api" => $description->getApi()
    ]);

    $phar->setStub("<?php __HALT_COMPILER();");

    $phar->buildFromIterator(new ArrayIterator($files));
    $phar->stopBuffering();

    unset($phar);

    (new Filesystem())->rename($temporaryPath, $outputPath, true);
    $temporaryPath = null;

    fwrite(STDOUT, "Built {$description->getFullName()}: {$outputPath} (" . count($files) . " files)" . PHP_EOL);
}catch(Throwable $exception){
    unset($phar);

    if($temporaryPath !== null && is_file($temporaryPath)){
        @unlink($temporaryPath);
    }

    fwrite(STDERR, "[BUILD ERROR] " . $exception->getMessage() . PHP_EOL);

    exit(1);
}