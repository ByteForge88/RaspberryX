<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\Server;

use raspberry\nbt\tag\CompoundTag;

use function explode;
use function in_array;
use function is_bool;
use function is_int;

class BlockNBT{

    private static ?int $version = null;

    private function __construct(){
    }

    public static function write(BlockState $block) : CompoundTag{
        $states = new CompoundTag();

        foreach($block->getProperties() as $property => $value){
            match(true){
                is_bool($value) => $states->setByte($property, $value ? 1 : 0),
                is_int($value) => $states->setInt($property, $value),
                default => $states->setString($property, $value)
            };
        }

        return (new CompoundTag())
            ->setString("name", $block->getName())
            ->setTag("states", $states)
            ->setInt("version", self::getVersion());
    }

    public static function read(?CompoundTag $nbt) : ?BlockState{
        $name = $nbt?->getString("name");
        $registry = Server::getInstance()->getBlockStateRegistry();

        if($nbt === null || $name === null || !$registry->isRegistered($name)){
            return null;
        }

        $block = $registry->getDefaultState($name);
        $states = $nbt->getCompoundTag("states");

        if($states === null){
            return $block;
        }

        foreach($registry->getProperties($name) as $property => $values){
            $value = $states->getTag($property)?->getValue();

            if($value === null){
                continue;
            }

            if(is_bool($values[0] ?? null)){
                $value = $value !== 0;
            }

            if(in_array($value, $values, true)){
                $block = $block->withProperty($property, $value);
            }
        }

        return $block;
    }

    public static function getVersion() : int{
        if(self::$version === null){
            self::$version = 0;

            foreach(explode(".", Server::getInstance()->getBlockStateRegistry()->getVersion()) as $part){
                self::$version = self::$version << 8 | ((int) $part & 0xff);
            }
        }

        return self::$version;
    }
}