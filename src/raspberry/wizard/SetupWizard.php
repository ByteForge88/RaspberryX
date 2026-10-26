<?php

declare(strict_types=1);

namespace raspberry\wizard;

use raspberry\lang\Language;

use raspberry\Server;

use raspberry\utils\Config;

use raspberry\world\WorldManager;

use Closure;

use function array_keys;
use function array_search;
use function count;
use function fgets;
use function filter_var;
use function fwrite;
use function is_file;
use function preg_match;
use function strlen;
use function strtolower;
use function stream_isatty;
use function substr;
use function trim;

class SetupWizard{

    private Language $language;

    public function __construct(private readonly string $propertiesPath){
        $this->language = new Language(Language::FALLBACK);
    }

    /**
     * @return bool false when the license was declined and the server must not start
     */
    public function run() : bool{
        if(is_file($this->propertiesPath)){
            return true;
        }

        $values = Server::DEFAULT_PROPERTIES;

        if(!stream_isatty(STDIN)){
            $this->say($this->language->translate("wizard.skipped", ["file" => "server.properties"]));
            $this->save($values);

            return true;
        }

        $this->say("");
        $this->say("[*] " . Server::NAME . " " . Server::VERSION);
        $this->say("");

        $values["language"] = $this->askLanguage();
        $this->language = new Language($values["language"]);

        $this->say("");
        $this->say($this->language->translate("wizard.welcome"));
        $this->say("");
        $this->say($this->language->translate("wizard.license", ["license" => Server::LICENSE]));

        if(!$this->askYesNo($this->language->translate("wizard.license.prompt"))){
            $this->say($this->language->translate("wizard.license.declined"));
            return false;
        }

        $this->say("");

        $values["server-name"] = $this->ask("wizard.server.name", (string) $values["server-name"], static fn(string $value) : bool => strlen($value) <= 64);

        $values["server-port"] = (int) $this->ask("wizard.server.port", (string) $values["server-port"], static fn(string $value) : bool => filter_var($value, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 65535]]) !== false);

        $values["max-players"] = (int) $this->ask("wizard.max.players", (string) $values["max-players"], static fn(string $value) : bool => filter_var($value, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 1000]]) !== false);

        $values["world-name"] = $this->ask("wizard.world.name", (string) $values["world-name"], static fn(string $value) : bool => WorldManager::isValidName($value));

        $this->save($values);

        $this->say("");
        $this->say($this->language->translate("wizard.done", ["file" => "server.properties"]));
        $this->say("");

        return true;
    }

    private function askLanguage() : string{
        $languages = Language::getAvailable();
        $codes = array_keys($languages);

        $this->say($this->language->translate("wizard.language"));

        foreach($codes as $index => $code){
            $this->say("  " . ($index + 1) . ") " . $languages[$code] . " [{$code}]");
        }

        $default = (string) (array_search(Language::FALLBACK, $codes, true) + 1);

        $choice = $this->ask("wizard.language.prompt", $default, static fn(string $value) : bool => preg_match('/^\d+$/D', $value) === 1 && (int) $value >= 1 && (int) $value <= count($codes));

        return $codes[(int) $choice - 1];
    }

    /**
     * @param Closure(string): bool $isValid
     */
    private function ask(string $key, string $default, Closure $isValid) : string{
        while(true){
            fwrite(STDOUT, $this->language->translate($key) . " [{$default}]: ");

            $line = fgets(STDIN);

            if($line === false){
                fwrite(STDOUT, PHP_EOL);
                return $default;
            }

            $value = trim($line);

            if($value === ""){
                return $default;
            }

            if($isValid($value)){
                return $value;
            }

            $this->say($this->language->translate("wizard.invalid"));
        }
    }

    private function askYesNo(string $prompt) : bool{
        fwrite(STDOUT, $prompt . " ");

        $first = substr(strtolower(trim((string) fgets(STDIN))), 0, 1);

        return $first !== "" && ($first === "y" || $first === strtolower($this->language->translate("wizard.yes")));
    }

    /**
     * @param array<string, string|int|bool> $values
     */
    private function save(array $values) : void{
        new Config($this->propertiesPath, Config::PROPERTIES, $values);
    }

    private function say(string $message) : void{
        fwrite(STDOUT, $message . PHP_EOL);
    }
}