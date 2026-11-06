<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\item\Item;
use raspberry\item\ItemSerializer;

use raspberry\math\Vector3;

use raspberry\nbt\NbtException;
use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\StopSoundPacket;
use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\world\World;

use function substr;

class Jukebox extends BlockEntity{

    private const DISCS = [
        "minecraft:music_disc_13" => [LevelSoundEvent::RECORD_13, 1],
        "minecraft:music_disc_cat" => [LevelSoundEvent::RECORD_CAT, 2],
        "minecraft:music_disc_blocks" => [LevelSoundEvent::RECORD_BLOCKS, 3],
        "minecraft:music_disc_chirp" => [LevelSoundEvent::RECORD_CHIRP, 4],
        "minecraft:music_disc_far" => [LevelSoundEvent::RECORD_FAR, 5],
        "minecraft:music_disc_mall" => [LevelSoundEvent::RECORD_MALL, 6],
        "minecraft:music_disc_mellohi" => [LevelSoundEvent::RECORD_MELLOHI, 7],
        "minecraft:music_disc_stal" => [LevelSoundEvent::RECORD_STAL, 8],
        "minecraft:music_disc_strad" => [LevelSoundEvent::RECORD_STRAD, 9],
        "minecraft:music_disc_ward" => [LevelSoundEvent::RECORD_WARD, 10],
        "minecraft:music_disc_11" => [LevelSoundEvent::RECORD_11, 11],
        "minecraft:music_disc_wait" => [LevelSoundEvent::RECORD_WAIT, 12],
        "minecraft:music_disc_pigstep" => [LevelSoundEvent::RECORD_PIGSTEP, 13],
        "minecraft:music_disc_otherside" => [LevelSoundEvent::RECORD_OTHERSIDE, 14],
        "minecraft:music_disc_5" => [LevelSoundEvent::RECORD_5, 15],
        "minecraft:music_disc_relic" => [LevelSoundEvent::RECORD_RELIC, 14],
        "minecraft:music_disc_precipice" => [LevelSoundEvent::RECORD_PRECIPICE, 13],
        "minecraft:music_disc_creator" => [LevelSoundEvent::RECORD_CREATOR, 12],
        "minecraft:music_disc_creator_music_box" => [LevelSoundEvent::RECORD_CREATOR_MUSIC_BOX, 11],
        "minecraft:music_disc_tears" => [LevelSoundEvent::RECORD_TEARS, 10],
        "minecraft:music_disc_lava_chicken" => [LevelSoundEvent::RECORD_LAVA_CHICKEN, 9],
        "minecraft:music_disc_bounce" => [LevelSoundEvent::RECORD_BOUNCE, 8]
    ];

    private ?Item $record = null;

    public function getSaveId() : string{
        return "Jukebox";
    }

    public static function isDisc(Item $item) : bool{
        return isset(self::DISCS[$item->getId()]);
    }

    public function getRecord() : ?Item{
        return $this->record;
    }

    public function getSignal() : int{
        return $this->record === null ? 0 : self::DISCS[$this->record->getId()][1];
    }

    /**
     * Puts a disc in and starts playing it.
     */
    public function insert(World $world, Item $disc) : void{
        [$x, $y, $z] = [$this->getX(), $this->getY(), $this->getZ()];
        $this->record = (clone $disc)->setCount(1);
        $this->setChanged();

        $world->broadcastPacket($x, $z, LevelSoundEventPacket::create(self::DISCS[$disc->getId()][0], $x + 0.5, $y + 0.5, $z + 0.5));

        foreach($world->getPlayersViewing($x >> 4, $z >> 4) as $player){
            $player->sendJukeboxPopup("%record.nowPlaying", ["%item.record_" . substr($disc->getId(), 21) . ".desc"]);
        }

        $world->getRedstoneUpdater()->update($x, $y, $z);
    }

    /**
     * Stops the music and pops the disc out on top.
     */
    public function eject(World $world) : void{
        $record = $this->takeRecord($world);

        if($record !== null){
            $world->dropItem(new Vector3($this->getX() + 0.5, $this->getY() + 1.01, $this->getZ() + 0.5), $record);
        }
    }

    public function readData(CompoundTag $nbt) : void{
        $tag = $nbt->getCompoundTag("RecordItem");

        try{
            $record = $tag === null ? null : ItemSerializer::deserialize($tag);
        }catch(NbtException){
            $record = null;
        }

        $this->record = $record !== null && self::isDisc($record) ? $record : null;
    }

    public function writeData(CompoundTag $nbt) : void{
        if($this->record !== null){
            $nbt->setTag("RecordItem", ItemSerializer::serialize($this->record));
        }
    }

    public function onBreak(World $world) : array{
        $record = $this->takeRecord($world);

        return $record === null ? [] : [$record];
    }

    private function takeRecord(World $world) : ?Item{
        $record = $this->record;

        if($record === null){
            return null;
        }

        [$x, $y, $z] = [$this->getX(), $this->getY(), $this->getZ()];
        $this->record = null;
        $this->setChanged();

        $world->broadcastPacket($x, $z, LevelSoundEventPacket::create(LevelSoundEvent::RECORD_NULL, $x + 0.5, $y + 0.5, $z + 0.5));
        $world->broadcastPacket($x, $z, StopSoundPacket::create(self::DISCS[$record->getId()][0]));
        $world->getRedstoneUpdater()->update($x, $y, $z);

        return $record;
    }
}