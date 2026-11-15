<?php

declare(strict_types=1);

namespace raspberry\event\server;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;
use raspberry\event\Event;

use raspberry\network\mcpe\NetworkSession;
use raspberry\network\mcpe\protocol\Packet;

use raspberry\player\Player;

/**
 * A player's client sent a packet, before the server handles it. getPacket() is null for packets
 * the server cannot read. Cancelling it makes the server ignore the packet.
 */
class DataPacketReceiveEvent extends Event implements Cancellable{
    use CancellableTrait;

    private NetworkSession $session;

    private int $packetId;

    private string $buffer;

    private ?Packet $packet;

    public function __construct(NetworkSession $session, int $packetId, string $buffer, ?Packet $packet){
        $this->session = $session;
        $this->packetId = $packetId;
        $this->buffer = $buffer;
        $this->packet = $packet;
    }

    public function getSession() : NetworkSession{
        return $this->session;
    }

    public function getPlayer() : ?Player{
        return $this->session->getPlayer();
    }

    /**
     * A ProtocolPacketIds constant.
     */
    public function getPacketId() : int{
        return $this->packetId;
    }

    /**
     * The packet as it came, header and all.
     */
    public function getBuffer() : string{
        return $this->buffer;
    }

    public function getPacket() : ?Packet{
        return $this->packet;
    }
}