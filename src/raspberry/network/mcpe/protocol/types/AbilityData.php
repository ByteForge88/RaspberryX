<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use function count;

class AbilityData{

    public const PLAYER_PERMISSION_VISITOR = 0;
    public const PLAYER_PERMISSION_MEMBER = 1;
    public const PLAYER_PERMISSION_OPERATOR = 2;
    public const PLAYER_PERMISSION_CUSTOM = 3;

    public const COMMAND_PERMISSION_NORMAL = 0;
    public const COMMAND_PERMISSION_OPERATOR = 1;

    public int $actorUniqueId;

    public int $playerPermission;

    public int $commandPermission;

    /** @var list<AbilityLayer> */
    public array $layers;

    /**
     * @param list<AbilityLayer> $layers
     */
    public function __construct(int $actorUniqueId, int $playerPermission, int $commandPermission, array $layers){
        $this->actorUniqueId = $actorUniqueId;
        $this->playerPermission = $playerPermission;
        $this->commandPermission = $commandPermission;
        $this->layers = $layers;
    }

    public static function read(BinaryReader $reader) : self{
        $actorUniqueId = $reader->readLong();
        $playerPermission = $reader->readByte();
        $commandPermission = $reader->readByte();
        $layers = [];

        for($i = 0, $count = $reader->readUnsignedVarInt(); $i < $count; ++$i){
            $layers[] = AbilityLayer::read($reader);
        }

        return new self($actorUniqueId, $playerPermission, $commandPermission, $layers);
    }

    public function write(BinaryWriter $writer) : void{
        $writer->writeLong($this->actorUniqueId);
        $writer->writeByte($this->playerPermission);
        $writer->writeByte($this->commandPermission);
        $writer->writeUnsignedVarInt(count($this->layers));

        foreach($this->layers as $layer){
            $layer->write($writer);
        }
    }
}