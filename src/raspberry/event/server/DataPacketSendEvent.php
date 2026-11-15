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
 * The packets a player gets in one batch are about to be sent. Take some out or add others with
 * setPackets(), or cancel it to send none of them.
 */
class DataPacketSendEvent extends Event implements Cancellable{
    use CancellableTrait;

    private NetworkSession $session;

    /** @var list<Packet> */
    private array $packets;

    /**
     * @param list<Packet> $packets
     */
    public function __construct(NetworkSession $session, array $packets){
        $this->session = $session;
        $this->packets = $packets;
    }

    public function getSession() : NetworkSession{
        return $this->session;
    }

    public function getPlayer() : ?Player{
        return $this->session->getPlayer();
    }

    /**
     * @return list<Packet>
     */
    public function getPackets() : array{
        return $this->packets;
    }

    /**
     * @param list<Packet> $packets
     */
    public function setPackets(array $packets) : void{
        $this->packets = $packets;
    }
}