<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use UnexpectedValueException;

use function is_a;

class PacketRegistry{

    /**
     * @var array<int, class-string<Packet>>
     */
    private array $packets = [];

    /**
     * @param class-string<Packet> $packetClass
     */
    public function register(string $packetClass) : void{
        if(!is_a($packetClass, Packet::class, true)){
            throw new InvalidArgumentException("{$packetClass} must implement " . Packet::class);
        }

        $reflection = new ReflectionClass($packetClass);

        if(!$reflection->isInstantiable()){
            throw new InvalidArgumentException("{$packetClass} must be instantiable");
        }

        $constructor = $reflection->getConstructor();

        if($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0){
            throw new InvalidArgumentException("{$packetClass} must allow construction without arguments");
        }

        $id = $packetClass::getPacketId();

        if($id < 0 || $id > 0x3ff){
            throw new InvalidArgumentException("Packet ID {$id} is outside 0 to 1023");
        }

        if(isset($this->packets[$id])){
            throw new LogicException("Packet ID {$id} is already registered to " . $this->packets[$id]);
        }

        $this->packets[$id] = $packetClass;
    }

    public function isRegistered(int $id) : bool{
        return isset($this->packets[$id]);
    }

    public function create(int $id) : Packet{
        $class = $this->packets[$id] ?? null;

        if($class === null){
            throw new UnexpectedValueException("Unknown packet ID: {$id}");
        }

        return new $class();
    }

    public function assertRegistered(Packet $packet) : void{
        $id = $packet::getPacketId();
        $registeredClass = $this->packets[$id] ?? null;

        if($registeredClass !== $packet::class){
            throw new LogicException("Packet class " . $packet::class . " is not registered for ID {$id}");
        }
    }
}