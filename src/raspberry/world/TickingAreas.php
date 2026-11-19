<?php

declare(strict_types=1);

namespace raspberry\world;

use raspberry\world\format\io\WorldMetadata;

use InvalidArgumentException;

use function array_values;
use function count;
use function strtolower;

class TickingAreas{

    public const int MAX_AREAS = 10;

    public const int MAX_CHUNKS = 100;

    public const int MAX_RADIUS = 4;

    private WorldMetadata $metadata;

    /** @var array<string, TickingArea> */
    private array $areas = [];

    /** @var array<string, true>|null */
    private ?array $chunkKeys = null;

    /** goes up whenever an area is added or removed */
    private int $revision = 0;

    public function __construct(WorldMetadata $metadata){
        $this->metadata = $metadata;

        foreach($metadata->tickingAreas as $data){
            $area = TickingArea::fromArray($data);
            $this->areas[strtolower($area->getName())] = $area;
        }
    }

    /**
     * @return list<TickingArea>
     */
    public function getAll() : array{
        return array_values($this->areas);
    }

    public function get(string $name) : ?TickingArea{
        return $this->areas[strtolower($name)] ?? null;
    }

    public function count() : int{
        return count($this->areas);
    }

    public function isFull() : bool{
        return count($this->areas) >= self::MAX_AREAS;
    }

    public function getRevision() : int{
        return $this->revision;
    }

    /**
     * A name nobody uses yet for an area that was added without one.
     */
    public function nextName() : string{
        for($number = 0; isset($this->areas["area" . $number]); ++$number);

        return "Area" . $number;
    }

    public function add(TickingArea $area) : void{
        if($this->isFull()){
            throw new InvalidArgumentException("A world cannot have more than " . self::MAX_AREAS . " ticking areas");
        }

        if($area->getChunkCount() > self::MAX_CHUNKS){
            throw new InvalidArgumentException("A ticking area cannot have more than " . self::MAX_CHUNKS . " chunks");
        }

        if(isset($this->areas[strtolower($area->getName())])){
            throw new InvalidArgumentException("There is already a ticking area named " . $area->getName());
        }

        $this->areas[strtolower($area->getName())] = $area;
        $this->changed();
    }

    public function remove(TickingArea $area) : void{
        unset($this->areas[strtolower($area->getName())]);
        $this->changed();
    }

    public function removeAll() : void{
        $this->areas = [];
        $this->changed();
    }

    /**
     * The areas with the block x z in them.
     *
     * @return list<TickingArea>
     */
    public function getAt(int $x, int $z) : array{
        $found = [];

        foreach($this->areas as $area){
            if($area->contains($x, $z)){
                $found[] = $area;
            }
        }

        return $found;
    }

    /**
     * @return array<string, true>
     */
    public function getChunkKeys() : array{
        if($this->chunkKeys === null){
            $this->chunkKeys = [];

            foreach($this->areas as $area){
                foreach($area->getChunks() as [$chunkX, $chunkZ]){
                    $this->chunkKeys[$chunkX . ":" . $chunkZ] = true;
                }
            }
        }

        return $this->chunkKeys;
    }

    /**
     * Called after an area's preload flag changed, so it is saved.
     */
    public function changed() : void{
        $this->chunkKeys = null;
        ++$this->revision;

        $saved = [];

        foreach($this->areas as $area){
            $saved[] = $area->toArray();
        }

        $this->metadata->tickingAreas = $saved;
    }
}