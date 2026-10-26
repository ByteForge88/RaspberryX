<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\resourcepack;

use raspberry\network\mcpe\protocol\ResourcePacksInfoPacket;
use raspberry\network\mcpe\protocol\ResourcePackStackPacket;
use raspberry\network\mcpe\protocol\ResourcePackClientResponsePacket;

use LogicException;
use UnexpectedValueException;

use function hrtime;

class ResourcePackNegotiation{

    private const NEW = 0;
    private const WAITING_FOR_DOWNLOADS = 1;
    private const WAITING_FOR_STACK = 2;
    private const COMPLETE = 3;

    private const TIMEOUT_NS = 30_000_000_000;

    private int $state = self::NEW;
    private int $deadlineNs = 0;

    private bool $receivedDownloadRequest = false;

    public function begin() : ResourcePacksInfoPacket{
        if($this->state !== self::NEW){
            throw new LogicException("Resource-pack negotiation has already started");
        }

        $this->state = self::WAITING_FOR_DOWNLOADS;
        $this->deadlineNs = hrtime(true) + self::TIMEOUT_NS;

        return new ResourcePacksInfoPacket();
    }

    public function isComplete() : bool{
        return $this->state === self::COMPLETE;
    }

    public function isExpired(int $nowNs) : bool{
        return $this->state !== self::NEW && $this->state !== self::COMPLETE && $nowNs >= $this->deadlineNs;
    }

    public function handle(ResourcePackClientResponsePacket $response) : ?ResourcePackStackPacket{
        if($this->state === self::NEW || $this->state === self::COMPLETE){
            throw new UnexpectedValueException("Resource-pack response arrived outside negotiation");
        }

        if($this->isExpired(hrtime(true))){
            throw new UnexpectedValueException("Resource-pack negotiation timed out");
        }

        if($response->status === ResourcePackClientResponsePacket::CANCEL){
            throw new UnexpectedValueException("Client cancelled resource-pack negotiation");
        }

        if($response->packIds !== []){
            throw new UnexpectedValueException("Client requested packs that the server did not advertise");
        }

        switch($response->status){
            case ResourcePackClientResponsePacket::DOWNLOADING:
                if($this->state !== self::WAITING_FOR_DOWNLOADS || $this->receivedDownloadRequest
                ){
                    throw new UnexpectedValueException("Unexpected or repeated resource-pack download request");
                }

                $this->receivedDownloadRequest = true;
                return null;

            case ResourcePackClientResponsePacket::DOWNLOADING_FINISHED:
                if($this->state !== self::WAITING_FOR_DOWNLOADS){
                    throw new UnexpectedValueException("Unexpected downloads-finished response");
                }

                $this->state = self::WAITING_FOR_STACK;
                $this->deadlineNs = hrtime(true) + self::TIMEOUT_NS;

                return new ResourcePackStackPacket();

            case ResourcePackClientResponsePacket::STACK_FINISHED:
                if($this->state !== self::WAITING_FOR_STACK){
                    throw new UnexpectedValueException("Client completed the resource-pack stack too early");
                }

                $this->state = self::COMPLETE;
                return null;

            default:
                throw new UnexpectedValueException("Unknown resource-pack response");
        }
    }
}