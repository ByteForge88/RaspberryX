<?php

declare(strict_types=1);

namespace raspberry\network\nethernet;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

use RuntimeException;
use UnexpectedValueException;

use function base64_decode;
use function base64_encode;
use function chmod;
use function chunk_split;
use function count;
use function dirname;
use function explode;
use function fclose;
use function file_get_contents;
use function error_clear_last;
use function error_get_last;
use function http_get_last_response_headers;
use function fopen;
use function fwrite;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function openssl_error_string;
use function openssl_pkey_export;
use function openssl_pkey_get_details;
use function openssl_pkey_get_private;
use function openssl_pkey_get_public;
use function openssl_pkey_new;
use function preg_match;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtr;
use function substr;
use function stream_context_create;
use function time;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const OPENSSL_KEYTYPE_EC;

class NetherNetIdentity{

    private const ISSUER = "https://authorization.franchise.minecraft-services.net/";

    private const AUDIENCE = "api://auth-minecraft-services/multiplayer";

    private const KEYS_URL = "https://authorization.franchise.minecraft-services.net/.well-known/keys";

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    private readonly string $privateKey;

    /** @var array<string, mixed> */
    private readonly array $publicJwk;

    /** @var array<string|int, Key> */
    private array $trustedKeys;

    /**
     * Public verification keys downloaded from the Minecraft auth service.
     *
     * @return array<string|int, Key>
     */
    public function getTrustedKeys() : array{
        return $this->trustedKeys;
    }

    public function __construct(string $keyPath){
        $directory = dirname($keyPath);

        if(
            !is_dir($directory) &&
            !mkdir($directory, 0700, true) &&
            !is_dir($directory)
        ){
            throw new RuntimeException("Cannot create identity directory");
        }

        if(!is_file($keyPath)){
            while(openssl_error_string() !== false){}

            $options = [
                "private_key_type" => OPENSSL_KEYTYPE_EC,
                "curve_name" => "secp384r1"
            ];

            $key = openssl_pkey_new($options);

            if($key === false){
                $errors = [];

                while(($error = openssl_error_string()) !== false){
                    $errors[] = $error;
                }

                throw new RuntimeException("Key generation failed: " . implode(" | ", $errors));
            }

            if(!openssl_pkey_export($key, $pem, null, $options)){
                $errors = [];

                while(($error = openssl_error_string()) !== false){
                    $errors[] = $error;
                }

                throw new RuntimeException("Key export failed: " . implode(" | ", $errors));
            }

            $file = @fopen($keyPath, "x");

            if($file === false){
                throw new RuntimeException("Cannot create server key; check the path and permissions");
            }

            try{
                if(fwrite($file, $pem) !== strlen($pem)){
                    throw new RuntimeException("Cannot save server identity");
                }
            }finally{
                fclose($file);
            }

            @chmod($keyPath, 0600);
        }

        $pem = file_get_contents($keyPath);

        if($pem === false){
            throw new RuntimeException("Cannot read server identity");
        }

        $key = openssl_pkey_get_private($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        if($details === false || ($details["ec"]["curve_name"] ?? null) !== "secp384r1"){
            throw new RuntimeException("Expected a P-384 server key");
        }

        $this->privateKey = $pem;
        $this->publicJwk = [
            "kty" => "EC",
            "crv" => "P-384",
            "x" => self::base64Url($details["ec"]["x"]),
            "y" => self::base64Url($details["ec"]["y"])
        ];

        $this->loadTrustedKeys();
    }

    private function loadTrustedKeys() : void{
        $context = stream_context_create([
            "http" => [
                "timeout" => 10,
                "follow_location" => 0,
                "ignore_errors" => true,
                "header" => "Accept: application/json\r\n"
            ],
            "ssl" => [
                "verify_peer" => true,
                "verify_peer_name" => true
            ]
        ]);

        error_clear_last();

        $json = @file_get_contents(
            self::KEYS_URL,
            false,
            $context,
            0,
            262145
        );

        if($json === false){
            $reason = error_get_last()["message"] ?? "unknown error";

            throw new RuntimeException("Cannot retrieve Minecraft authentication keys from " . self::KEYS_URL . ": " . $reason);
        }

        $status = self::responseStatus(http_get_last_response_headers() ?? []);

        if($status !== 200){
            throw new RuntimeException("Cannot retrieve Minecraft authentication keys: " . self::KEYS_URL . " answered HTTP {$status}");
        }

        if(strlen($json) > 262144){
            throw new RuntimeException("Minecraft authentication key response is larger than 256 KiB");
        }

        $document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);

        if(!is_array($document)){
            throw new RuntimeException("Invalid authentication key response");
        }

        $this->trustedKeys = JWK::parseKeySet($document, "RS256");

        if($this->trustedKeys === []){
            throw new RuntimeException("Authentication key set is empty");
        }
    }

    /**
     * Status code of the last "HTTP/x.y NNN" line (0 when none was received).
     *
     * @param list<string> $headers
     */
    private static function responseStatus(array $headers) : int{
        $status = 0;

        foreach($headers as $header){
            if(preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1){
                $status = (int) $matches[1];
            }
        }

        return $status;
    }

    /**
     * @return array{offer: string, claims: \stdClass}
     */
    public function verifyOffer(string $sdp) : array{
        $lines = self::lines($sdp);
        $identity = null;
        $clean = [];
        $inMedia = false;

        foreach($lines as $line){
            if(str_starts_with($line, "m=")){
                $inMedia = true;
            }

            if(str_starts_with($line, "a=identity:")){
                if($identity !== null || $inMedia){
                    throw new UnexpectedValueException("Invalid SDP identity placement");
                }

                $identity = substr($line, strlen("a=identity:"));
                continue;
            }

            $clean[] = $line;
        }

        if($identity === null){
            throw new UnexpectedValueException("Authenticated client identity is required");
        }

        $decoded = base64_decode($identity, true);

        if($decoded === false){
            throw new UnexpectedValueException("Invalid identity encoding");
        }

        $envelope = json_decode($decoded, true, 32, JSON_THROW_ON_ERROR);

        if(
            !is_array($envelope) ||
            ($envelope["idp"]["protocol"] ?? null) !== "default" ||
            !is_string($envelope["assertion"] ?? null)
        ){
            throw new UnexpectedValueException("Invalid identity envelope");
        }

        $assertion = json_decode($envelope["assertion"], true, 32, JSON_THROW_ON_ERROR);

        if(
            !is_array($assertion) ||
            !is_string($assertion["token"] ?? null) ||
            !is_string($assertion["fingerprints"] ?? null)
        ){
            throw new UnexpectedValueException("Invalid identity assertion");
        }

        $claims = JWT::decode($assertion["token"], $this->trustedKeys);

        $audiences = is_string($claims->aud ?? null) ? [$claims->aud] : ($claims->aud ?? []);

        if(
            !is_string($claims->iss ?? null) ||
            rtrim($claims->iss, "/") !== rtrim(self::ISSUER, "/") ||
            !is_array($audiences) ||
            !in_array(self::AUDIENCE, $audiences, true) ||
            !is_numeric($claims->exp ?? null) ||
            $claims->exp <= time()
        ){
            throw new UnexpectedValueException("Client token issuer, audience, or expiry is invalid");
        }

        $parts = explode(".", $assertion["fingerprints"]);

        if(count($parts) !== 3 || $parts[1] !== ""){
            throw new UnexpectedValueException("Expected detached JWS");
        }

        $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true, 16, JSON_THROW_ON_ERROR);

        $algorithm = $header["alg"] ?? null;

        if(
            !in_array($algorithm, ["ES256", "ES384"], true) ||
            isset($header["crit"]) ||
            isset($header["b64"])
        ){
            throw new UnexpectedValueException("Unsupported fingerprint signature header");
        }

        $clientKey = self::clientKey($claims->cpk ?? null, $algorithm);
        $payload = self::fingerprintJson($sdp);

        JWT::decode($parts[0] . "." . self::base64Url($payload) . "." . $parts[2], $clientKey);

        return [
            "offer" => implode("\r\n", $clean) . "\r\n",
            "claims" => $claims
        ];
    }

    public function signAnswer(string $sdp) : string{
        $now = time();

        $token = JWT::encode([
            "iss" => "RaspberryX",
            "iat" => $now,
            "exp" => $now + 3600,
            "cpk" => $this->publicJwk
        ], $this->privateKey, "ES384");

        $fingerprints = json_decode(self::fingerprintJson($sdp), true, 16, JSON_THROW_ON_ERROR);

        $signed = explode(".", JWT::encode($fingerprints, $this->privateKey, "ES384"));

        $envelope = [
            "idp" => [
                "domain" => "RaspberryX",
                "protocol" => "default"
            ],
            "assertion" => json_encode([
                "token" => $token,
                "fingerprints" => $signed[0] . ".." . $signed[2]
            ], self::JSON_FLAGS)
        ];

        $identityLine = "a=identity:" . base64_encode(json_encode($envelope, self::JSON_FLAGS));

        $result = [];
        $inserted = false;

        foreach(self::lines($sdp) as $line){
            if(str_starts_with($line, "a=identity:")){
                throw new UnexpectedValueException("Answer already contains an identity");
            }

            if(!$inserted && str_starts_with($line, "m=")){
                $result[] = $identityLine;
                $inserted = true;
            }

            $result[] = $line;
        }

        if(!$inserted){
            throw new UnexpectedValueException("Answer has no media section");
        }

        return implode("\r\n", $result) . "\r\n";
    }

    private static function clientKey(mixed $cpk, string $algorithm) : Key{
        if($cpk instanceof \stdClass){
            $cpk = (array) $cpk;
        }

        if(is_array($cpk)){
            unset($cpk["d"]);

            $cpk["alg"] = $algorithm;
            $key = JWK::parseKey($cpk, $algorithm);

            if($key === null){
                throw new UnexpectedValueException("Invalid client public key");
            }

            return $key;
        }

        if(is_string($cpk)){
            $der = base64_decode($cpk, true);

            if($der === false){
                throw new UnexpectedValueException("Invalid client key encoding");
            }

            $pem = "-----BEGIN PUBLIC KEY-----\n" .
                chunk_split(base64_encode($der), 64, "\n") .
                "-----END PUBLIC KEY-----\n";

            if(openssl_pkey_get_public($pem) === false){
                throw new UnexpectedValueException("Invalid client public key");
            }

            return new Key($pem, $algorithm);
        }

        throw new UnexpectedValueException("Missing client public key");
    }

    private static function fingerprintJson(string $sdp) : string{
        $fingerprints = [];

        foreach(self::lines($sdp) as $line){
            if(!str_starts_with($line, "a=fingerprint:")){
                continue;
            }

            $value = substr($line, strlen("a=fingerprint:"));

            if(!preg_match("/^([a-zA-Z0-9-]+) ([0-9A-Fa-f:]+)$/D", $value, $match)){
                throw new UnexpectedValueException("Malformed SDP fingerprint");
            }

            $fingerprints[] = [
                "algorithm" => $match[1],
                "digest" => $match[2]
            ];
        }

        if($fingerprints === []){
            throw new UnexpectedValueException("SDP has no fingerprints");
        }

        return json_encode(
            ["fingerprint" => $fingerprints],
            self::JSON_FLAGS
        );
    }

    /** @return list<string> */
    private static function lines(string $sdp) : array{
        if(strlen($sdp) > 65536 || str_contains($sdp, "\0")){
            throw new UnexpectedValueException("Invalid SDP");
        }

        return explode("\n", str_replace("\r\n", "\n", rtrim($sdp, "\r\n")));
    }

    private static function base64Url(string $bytes) : string{
        return rtrim(strtr(base64_encode($bytes), "+/", "-_"), "=");
    }
}