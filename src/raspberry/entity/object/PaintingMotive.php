<?php

declare(strict_types=1);

namespace raspberry\entity\object;

enum PaintingMotive : string{

    case KEBAB = "Kebab";
    case AZTEC = "Aztec";
    case ALBAN = "Alban";
    case AZTEC2 = "Aztec2";
    case BOMB = "Bomb";
    case PLANT = "Plant";
    case WASTELAND = "Wasteland";
    case MEDITATIVE = "meditative";

    case WANDERER = "Wanderer";
    case GRAHAM = "Graham";
    case PRAIRIE_RIDE = "prairie_ride";

    case POOL = "Pool";
    case COURBET = "Courbet";
    case SUNSET = "Sunset";
    case SEA = "Sea";
    case CREEBET = "Creebet";

    case MATCH = "Match";
    case BUST = "Bust";
    case STAGE = "Stage";
    case VOID = "Void";
    case SKULL_AND_ROSES = "SkullAndRoses";
    case WITHER = "Wither";
    case EARTH = "Earth";
    case WIND = "Wind";
    case FIRE = "Fire";
    case WATER = "Water";
    case BAROQUE = "baroque";
    case HUMBLE = "humble";

    case BOUQUET = "bouquet";
    case CAVEBIRD = "cavebird";
    case COTAN = "cotan";
    case ENDBOSS = "endboss";
    case FERN = "fern";
    case OWLEMONS = "owlemons";
    case SUNFLOWERS = "sunflowers";
    case TIDES = "tides";
    case DENNIS = "dennis";

    case BACKYARD = "backyard";
    case POND = "pond";

    case FIGHTERS = "Fighters";
    case CHANGING = "changing";
    case FINDING = "finding";
    case LOWMIST = "lowmist";
    case PASSAGE = "passage";

    case SKELETON = "Skeleton";
    case DONKEY_KONG = "DonkeyKong";

    case POINTER = "Pointer";
    case PIGSCENE = "Pigscene";
    case BURNING_SKULL = "BurningSkull";
    case ORB = "orb";
    case UNPACKED = "unpacked";

    public function getWidth() : int{
        return $this->getSize()[0];
    }

    public function getHeight() : int{
        return $this->getSize()[1];
    }

    /**
     * @return array{int, int}
     */
    private function getSize() : array{
        return match($this){
            self::KEBAB, self::AZTEC, self::ALBAN, self::AZTEC2, self::BOMB, self::PLANT, self::WASTELAND, self::MEDITATIVE => [1, 1],
            self::WANDERER, self::GRAHAM, self::PRAIRIE_RIDE => [1, 2],
            self::POOL, self::COURBET, self::SUNSET, self::SEA, self::CREEBET => [2, 1],
            self::MATCH, self::BUST, self::STAGE, self::VOID, self::SKULL_AND_ROSES, self::WITHER, self::EARTH, self::WIND, self::FIRE, self::WATER, self::BAROQUE, self::HUMBLE => [2, 2],
            self::BOUQUET, self::CAVEBIRD, self::COTAN, self::ENDBOSS, self::FERN, self::OWLEMONS, self::SUNFLOWERS, self::TIDES, self::DENNIS => [3, 3],
            self::BACKYARD, self::POND => [3, 4],
            self::FIGHTERS, self::CHANGING, self::FINDING, self::LOWMIST, self::PASSAGE => [4, 2],
            self::SKELETON, self::DONKEY_KONG => [4, 3],
            self::POINTER, self::PIGSCENE, self::BURNING_SKULL, self::ORB, self::UNPACKED => [4, 4]
        };
    }
}