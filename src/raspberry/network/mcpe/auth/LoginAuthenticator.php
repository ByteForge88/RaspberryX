<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\auth;

use raspberry\network\mcpe\protocol\LoginPacket;
use raspberry\network\mcpe\protocol\ProtocolInfo;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

use InvalidArgumentException;
use stdClass;
use Throwable;
use UnexpectedValueException;

use function array_key_exists;
use function base64_decode;
use function base64_encode;
use function chunk_split;
use function count;
use function explode;
use function hash_equals;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function openssl_pkey_get_details;
use function openssl_pkey_get_public;
use function preg_match;
use function rtrim;
use function strlen;
use function time;

use const JSON_THROW_ON_ERROR;
use const OPENSSL_KEYTYPE_EC;

class LoginAuthenticator{

    private const ISSUER = "https://authorization.franchise.minecraft-services.net/";

    private const AUDIENCE = "api://auth-minecraft-services/multiplayer";

    /** @var array<string|int, Key> */
    private array $trustedKeys;

    /**
     * Supply only keys fetched from the trusted Minecraft auth service.
     *
     * @param array<string|int, Key> $trustedKeys
     */
    public function __construct(array $trustedKeys){
        if($trustedKeys === []){
            throw new InvalidArgumentException("Minecraft authentication keys are missing");
        }

        foreach($trustedKeys as $key){
            if(!$key instanceof Key){
                throw new InvalidArgumentException("Invalid authentication key collection");
            }
        }

        $this->trustedKeys = $trustedKeys;
    }

    /**
     * $signalingClaims must come from NetherNetIdentity::verifyOffer().
     * Never pass claims decoded from an unverified token here.
     */
    public function authenticate(LoginPacket $packet, stdClass $signalingClaims) : AuthenticatedLogin{
        if($packet->protocolVersion !== ProtocolInfo::CURRENT_PROTOCOL){
            throw new UnexpectedValueException("Login protocol does not match the server protocol");
        }

        if(strlen($packet->authInfoJson) > LoginPacket::MAX_AUTH_BYTES || strlen($packet->clientDataJwt) > LoginPacket::MAX_CLIENT_DATA_BYTES){
            throw new UnexpectedValueException("Login authentication data is too large");
        }

        $auth = json_decode($packet->authInfoJson, true, 32, JSON_THROW_ON_ERROR);

        if(!is_array($auth) ||
            ($auth["AuthenticationType"] ?? null) !== 0 ||
            !is_string($auth["Token"] ?? null) ||
            $auth["Token"] === ""
        ){
            throw new UnexpectedValueException("An online Minecraft authentication token is required");
        }

        $token = $auth["Token"];
        $header = self::readHeader($token);

        if(($header["alg"] ?? null) !== "RS256" ||
            !is_string($header["kid"] ?? null)
        ){
            throw new UnexpectedValueException("Unsupported Minecraft authentication token header");
        }

        $key = $this->trustedKeys[$header["kid"]] ?? null;

        if($key === null || $key->getAlgorithm() !== "RS256"){
            throw new UnexpectedValueException("Minecraft authentication signing key is not cached");
        }

        $claims = self::verifyJwt($token, $key, "Minecraft authentication token");

        $audiences = is_string($claims->aud ?? null) ? [$claims->aud] : ($claims->aud ?? []);

        if(!is_string($claims->iss ?? null) ||
            rtrim($claims->iss, "/") !== rtrim(self::ISSUER, "/") ||
            !is_array($audiences) ||
            !in_array(self::AUDIENCE, $audiences, true)
        ){
            throw new UnexpectedValueException("Invalid Minecraft token issuer or audience");
        }

        if(!is_int($claims->exp ?? null) || $claims->exp <= time()){
            throw new UnexpectedValueException("Minecraft authentication token is expired or has no expiry");
        }

        $username = self::requiredString($claims, "xname", 64);
        $xuid = self::requiredString($claims, "xid", 20);
        $minecraftId = self::requiredString($claims, "mid", 128);

        if(preg_match("/^[0-9]{1,20}$/D", $xuid) !== 1 || $xuid === "0"){
            throw new UnexpectedValueException("Invalid authenticated XUID");
        }

        $signalingXuid = self::requiredString($signalingClaims, "xid", 20);

        $signalingMinecraftId = self::requiredString($signalingClaims, "mid", 128);

        if(!hash_equals($signalingXuid, $xuid) || !hash_equals($signalingMinecraftId, $minecraftId)){
            throw new UnexpectedValueException("Login identity differs from the signaling identity");
        }

        $loginPublicKey = self::publicKeyPem($claims->cpk ?? null);
        $signalingPublicKey = self::publicKeyPem($signalingClaims->cpk ?? null);

        if(!hash_equals($signalingPublicKey, $loginPublicKey)){
            throw new UnexpectedValueException("Login public key differs from the signaling public key");
        }

        $clientHeader = self::readHeader($packet->clientDataJwt);

        if(($clientHeader["alg"] ?? null) !== "ES384"){
            throw new UnexpectedValueException("Client data must use an ES384 signature");
        }

        $clientData = self::verifyJwt($packet->clientDataJwt, new Key($loginPublicKey, "ES384"), "Client-data token");

        return new AuthenticatedLogin($username, $xuid, $minecraftId, $clientData);
    }

    /**
     * Converts either a P-384 JWK or base64 DER public key into
     * OpenSSL's canonical public PEM representation.
     */
    private static function publicKeyPem(mixed $value) : string{
        if($value instanceof stdClass){
            $value = (array) $value;
        }

        if(is_array($value)){
            if(($value["kty"] ?? null) !== "EC" || ($value["crv"] ?? null) !== "P-384" || array_key_exists("d", $value)){
                throw new UnexpectedValueException("Expected a P-384 client public key");
            }

            $value["alg"] = "ES384";

            $key = JWK::parseKey($value, "ES384");

            if($key === null){
                throw new UnexpectedValueException("Invalid client JWK");
            }

            $material = $key->getKeyMaterial();
        }elseif(is_string($value)){
            if(strlen($value) > 4096){
                throw new UnexpectedValueException("Client public key is too large");
            }

            $der = base64_decode($value, true);

            if($der === false || $der === ""){
                throw new UnexpectedValueException("Invalid client public-key encoding");
            }

            $material = "-----BEGIN PUBLIC KEY-----\n" .
                chunk_split(base64_encode($der), 64, "\n") .
                "-----END PUBLIC KEY-----\n";
        }else{
            throw new UnexpectedValueException("Client public key is missing");
        }

        $publicKey = openssl_pkey_get_public($material);
        $details = $publicKey === false ? false : openssl_pkey_get_details($publicKey);

        if($details === false ||
            ($details["type"] ?? null) !== OPENSSL_KEYTYPE_EC ||
            ($details["ec"]["curve_name"] ?? null) !== "secp384r1"
        ){
            throw new UnexpectedValueException("Client public key is not a valid P-384 key");
        }

        return $details["key"];
    }

    /** @return array<string, mixed> */
    private static function readHeader(string $jwt) : array{
        $parts = explode(".", $jwt);

        if(count($parts) !== 3 || $parts[0] === "" || $parts[1] === "" || $parts[2] === "" || strlen($parts[0]) > 8192){
            throw new UnexpectedValueException("Malformed login JWT");
        }

        $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true, 16, JSON_THROW_ON_ERROR);

        if(!is_array($header) || array_key_exists("crit", $header) || array_key_exists("b64", $header)){
            throw new UnexpectedValueException("Unsupported login JWT header");
        }

        return $header;
    }

    private static function verifyJwt(string $jwt, Key $key, string $label) : stdClass{
        try{
            return JWT::decode($jwt, $key);
        }catch(Throwable $error){
            throw new UnexpectedValueException($label . " signature or validity check failed", 0, $error);
        }
    }

    private static function requiredString(stdClass $claims, string $name, int $maximumBytes) : string{
        $value = $claims->{$name} ?? null;

        if(!is_string($value) || $value === "" || strlen($value) > $maximumBytes || preg_match("/[\x00-\x1f\x7f]/", $value) !== 0){
            throw new UnexpectedValueException("Missing or invalid identity claim: " . $name);
        }

        return $value;
    }
}