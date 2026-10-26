<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;
use raspberry\network\mcpe\serializer\NetworkNbtWriter;

use UnexpectedValueException;

use function bin2hex;
use function count;
use function is_bool;
use function is_int;
use function random_bytes;
use function sprintf;
use function str_repeat;
use function substr;

class StartGamePacket implements Packet{

    public const GAME_MODE_SURVIVAL = 0;
    public const GAME_MODE_CREATIVE = 1;
    public const GAME_MODE_ADVENTURE = 2;
    public const GAME_MODE_SPECTATOR = 6;

    public const DIFFICULTY_PEACEFUL = 0;
    public const DIFFICULTY_EASY = 1;
    public const DIFFICULTY_NORMAL = 2;
    public const DIFFICULTY_HARD = 3;

    public const PERMISSION_VISITOR = 0;
    public const PERMISSION_MEMBER = 1;
    public const PERMISSION_OPERATOR = 2;

    /** world generator shown to the client: 1 = infinite (default), 2 = flat */
    public const GENERATOR_INFINITE = 1;
    public const GENERATOR_FLAT = 2;

    public int $actorUniqueId = 1;
    public int $actorRuntimeId = 1;

    public int $playerGameMode = self::GAME_MODE_SURVIVAL;

    /** player eye position */
    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;

    public float $pitch = 0.0;
    public float $yaw = 0.0;

    public int $seed = 0;
    public int $dimension = 0;
    public int $generator = self::GENERATOR_INFINITE;
    public int $worldGameMode = self::GAME_MODE_SURVIVAL;
    public bool $hardcore = false;
    public int $difficulty = self::DIFFICULTY_NORMAL;

    public int $spawnX = 0;
    public int $spawnY = 0;
    public int $spawnZ = 0;

    public int $time = 0;

    public string $worldName = "";
    public string $levelId = "";

    /** @var array<string, bool|int|float> game rule name => value (bool, uint32 or float) */
    public array $gameRules = [];

    public int $playerPermissions = self::PERMISSION_MEMBER;
    public int $serverChunkTickRadius = 4;

    public bool $commandsEnabled = true;

    public string $baseGameVersion = "*";
    public string $gameVersion = ProtocolInfo::MINECRAFT_VERSION_NETWORK;

    public bool $serverAuthoritativeInventory = false;
    public bool $serverAuthoritativeBlockBreaking = false;

    /** clients compute block network IDs as hashes, so no block palette must be shared */
    public bool $useBlockNetworkIdHashes = true;

    public string $serverId = "";
    public string $worldId = "";

    public static function getPacketId() : int{
        return ProtocolPacketIds::START_GAME_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarLong($this->actorUniqueId);
        $writer->writeUnsignedVarLong($this->actorRuntimeId);
        $writer->writeVarInt($this->playerGameMode);
        $writer->writeFloat($this->x);
        $writer->writeFloat($this->y);
        $writer->writeFloat($this->z);
        $writer->writeFloat($this->pitch);
        $writer->writeFloat($this->yaw);

        //level settings
        $writer->writeLong($this->seed);
        $writer->writeShort(0); //spawn biome type: default
        $writer->writeString(""); //user defined biome name
        $writer->writeVarInt($this->dimension);
        $writer->writeVarInt($this->generator);
        $writer->writeVarInt($this->worldGameMode);
        $writer->writeBool($this->hardcore);
        $writer->writeVarInt($this->difficulty);
        $writer->writeVarInt($this->spawnX);
        $writer->writeVarInt($this->spawnY);
        $writer->writeVarInt($this->spawnZ);
        $writer->writeBool(true); //achievements disabled
        $writer->writeVarInt(0); //editor world type: not an editor world
        $writer->writeBool(false); //created in editor
        $writer->writeBool(false); //exported from editor
        $writer->writeVarInt(0); //day cycle lock time
        $writer->writeUnsignedVarInt(0); //education edition offer
        $writer->writeBool(false); //education features enabled
        $writer->writeString(""); //education product ID
        $writer->writeFloat(0.0); //rain level
        $writer->writeFloat(0.0); //lightning level
        $writer->writeBool(false); //confirmed platform locked content
        $writer->writeBool(true); //multiplayer game
        $writer->writeBool(true); //LAN broadcast
        $writer->writeVarInt(4); //Xbox Live broadcast mode: public
        $writer->writeVarInt(4); //platform broadcast mode: public
        $writer->writeBool($this->commandsEnabled);
        $writer->writeBool(false); //texture packs required
        $this->writeGameRules($writer);
        $writer->writeUnsignedInt(0); //experiments
        $writer->writeBool(false); //experiments previously toggled
        $writer->writeBool(false); //bonus chest
        $writer->writeBool(false); //start with map
        $writer->writeByte($this->playerPermissions);
        $writer->writeInt($this->serverChunkTickRadius);
        $writer->writeBool(false); //locked behaviour pack
        $writer->writeBool(false); //locked texture pack
        $writer->writeBool(false); //from locked world template
        $writer->writeBool(false); //MSA gamer tags only
        $writer->writeBool(false); //from world template
        $writer->writeBool(false); //world template settings locked
        $writer->writeBool(false); //only spawn v1 villagers
        $writer->writeBool(false); //persona disabled
        $writer->writeBool(false); //custom skins disabled
        $writer->writeBool(false); //emote chat muted
        $writer->writeString($this->baseGameVersion);
        $writer->writeInt(0); //limited world width
        $writer->writeInt(0); //limited world depth
        $writer->writeBool(true); //new nether
        $writer->writeString(""); //education shared resource: button name
        $writer->writeString(""); //education shared resource: link
        $writer->writeBool(false); //force experimental gameplay: not set
        $writer->writeByte(0); //chat restriction level: none
        $writer->writeBool(false); //disable player interactions
        $writer->writeVarInt(0); //server editor connection policy
        $writer->writeBool(false); //allow anonymous block drops in editor worlds

        $writer->writeString($this->levelId);
        $writer->writeString($this->worldName);
        $writer->writeString(""); //template content identity
        $writer->writeBool(false); //trial
        $writer->writeVarInt(0); //movement rewind history size
        $writer->writeBool($this->serverAuthoritativeBlockBreaking);
        $writer->writeLong($this->time);
        $writer->writeVarInt(0); //enchantment seed
        $writer->writeUnsignedVarInt(0); //custom blocks
        $writer->writeString(self::randomUuid()); //multiplayer correlation ID
        $writer->writeBool($this->serverAuthoritativeInventory);
        $writer->writeString($this->gameVersion);
        $writer->writeBytes(NetworkNbtWriter::emptyCompound()); //player property data
        $writer->writeLong(0); //block state checksum: not checked
        $writer->writeBytes(str_repeat("\x00", 16)); //world template UUID
        $writer->writeBool(false); //client side generation
        $writer->writeBool($this->useBlockNetworkIdHashes);
        $writer->writeBool(false); //server authoritative sound
        $writer->writeBool(false); //server join information: not set
        $writer->writeString($this->serverId);
        $writer->writeString(""); //scenario ID
        $writer->writeString($this->worldId);
        $writer->writeString(""); //owner ID
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("StartGamePacket is server-to-client only");
    }

    private function writeGameRules(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarInt(count($this->gameRules));

        foreach($this->gameRules as $name => $value){
            $writer->writeString($name);
            $writer->writeBool(false);

            if(is_bool($value)){
                $writer->writeUnsignedVarInt(1);
                $writer->writeBool($value);
            }elseif(is_int($value)){
                $writer->writeUnsignedVarInt(2);
                $writer->writeUnsignedInt($value);
            }else{
                $writer->writeUnsignedVarInt(3);
                $writer->writeFloat($value);
            }
        }
    }

    private static function randomUuid() : string{
        $hex = bin2hex(random_bytes(16));
        return sprintf("%s-%s-4%s-%s-%s", substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}