<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\convert;

use raspberry\entity\EntityProperties;

use raspberry\nbt\tag\CompoundTag;
use raspberry\nbt\tag\ListTag;
use raspberry\nbt\tag\StringTag;
use raspberry\nbt\tag\Tag;

use raspberry\network\mcpe\protocol\SyncActorPropertyPacket;

use function array_map;

class EntityPropertyAdapter{

    private const int TYPE_INT = 0;
    private const int TYPE_FLOAT = 1;
    private const int TYPE_BOOL = 2;
    private const int TYPE_ENUM = 3;

    /**
     * @return list<SyncActorPropertyPacket>
     */
    public static function createSyncPackets() : array{
        $packets = [];

        foreach(EntityProperties::getAll() as $entity => $properties){
            $list = [];

            foreach($properties as $property){
                $tag = new CompoundTag();
                $tag->setString("name", $property["name"]);

                if($property["type"] === "enum"){
                    $tag->setInt("type", self::TYPE_ENUM);
                    $tag->setTag("enum", new ListTag(array_map(fn(string $value) : StringTag => new StringTag($value), $property["values"]), Tag::TAG_STRING));
                }elseif($property["type"] === "bool"){
                    $tag->setInt("type", self::TYPE_BOOL);
                }elseif($property["type"] === "float"){
                    $tag->setInt("type", self::TYPE_FLOAT);
                    $tag->setFloat("min", (float) $property["min"]);
                    $tag->setFloat("max", (float) $property["max"]);
                }else{
                    $tag->setInt("type", self::TYPE_INT);
                    $tag->setInt("min", (int) $property["min"]);
                    $tag->setInt("max", (int) $property["max"]);
                }

                $list[] = $tag;
            }

            $root = new CompoundTag();
            $root->setString("type", $entity);
            $root->setTag("properties", new ListTag($list, Tag::TAG_COMPOUND));
            $packets[] = SyncActorPropertyPacket::create($root);
        }

        return $packets;
    }
}