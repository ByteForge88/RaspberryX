<?php

declare(strict_types=1);

namespace raspberry\network\nethernet;

use pmmp\webrtc\ConnectionState;
use pmmp\webrtc\DataChannel;
use pmmp\webrtc\IceCandidate;
use pmmp\webrtc\PeerConnection;
use pmmp\webrtc\PeerConnectionOptions;
use pmmp\webrtc\GatheringState;

use InvalidArgumentException;
use LogicException;
use RuntimeException;

class WebRtcSession{

    private const MAX_MESSAGE_BYTES = 262144;
    private const MAX_BUFFERED_BYTES = 4194304;
    private const MAX_MESSAGES_PER_POLL = 64;

    private readonly PeerConnection $peer;
    private readonly int $createdAt;

    /** @var array<string, DataChannel> */
    private array $channels = [];

    private bool $closed = false;
    private bool $answerEmitted = false;

    public function __construct(
        private readonly int $id,
        string $remoteOffer
    ){
        if($remoteOffer === "" || strlen($remoteOffer) > 65536){
            throw new InvalidArgumentException("Invalid SDP offer size");
        }

        $options = PeerConnectionOptions::create()
            ->setMaxMessageSize(self::MAX_MESSAGE_BYTES)
            ->setMaxReceiveQueueSize(self::MAX_BUFFERED_BYTES)
            ->setMaxReceiveQueueMessages(256)
            ->setMaxSendQueueSize(self::MAX_BUFFERED_BYTES)
            ->setMaxRemoteDescriptionSize(65536)
            ->setMaxPendingDataChannels(2);

        $this->createdAt = hrtime(true);
        $this->peer = new PeerConnection($options);

        try{
            $this->peer->setRemoteOffer($remoteOffer);
        }catch(\Throwable $exception){
            $this->peer->close();
            throw $exception;
        }
    }

    public function getId() : int{
        return $this->id;
    }

    public function isClosed() : bool{
        return $this->closed;
    }

    public function getState() : ConnectionState{
        return $this->peer->getState();
    }

    public function getRemoteAddress() : ?string{
        return $this->peer->getRemoteAddress();
    }

    public function addRemoteCandidate(
        string $candidate,
        ?string $mid = null
    ) : void{
        $this->assertOpen();

        if($candidate === "" || strlen($candidate) > 4096){
            throw new InvalidArgumentException("Invalid ICE candidate size");
        }

        if($mid !== null && strlen($mid) > 256){
            throw new InvalidArgumentException("ICE mid is too long");
        }

        $this->peer->addRemoteCandidate(
            IceCandidate::create($candidate, $mid)
        );
    }

    /**
     * Returns the local SDP answer once it becomes available.
     *
     * The signaling layer must deliver it to the remote peer.
     */
    public function takeLocalAnswer() : ?string{
        if($this->closed || $this->answerEmitted){
            return null;
        }

        if($this->peer->getGatheringState() !== GatheringState::COMPLETE){
            return null;
        }

        $answer = $this->peer->getLocalDescription();

        if($answer === null){
            return null;
        }

        if(!str_contains($answer, "a=candidate:")){
            throw new RuntimeException(
                "Completed SDP answer contains no ICE candidates"
            );
        }

        $this->answerEmitted = true;

        return $answer;
    }

    /**
     * @return list<array{candidate: string, mid: string}>
     */
    public function takeLocalCandidates() : array{
        if($this->closed){
            return [];
        }

        $result = [];

        foreach($this->peer->pollLocalCandidates() as $candidate){
            $result[] = [
                "candidate" => $candidate->getCandidate(),
                "mid" => $candidate->getMid()
            ];
        }

        return $result;
    }

    /**
     * Polls raw WebRTC messages.
     *
     * These still require NetherNet framing and Minecraft decoding.
     *
     * @return list<array{channel: string, payload: string}>
     */
    public function poll() : array{
        if($this->closed){
            return [];
        }

        $state = $this->peer->getState();

        if(
            $state === ConnectionState::FAILED ||
            $state === ConnectionState::CLOSED ||
            $state === ConnectionState::DISCONNECTED
        ){
            $this->close();
            return [];
        }

        if(
            $state !== ConnectionState::CONNECTED &&
            hrtime(true) - $this->createdAt > 30_000_000_000
        ){
            throw new RuntimeException("WebRTC connection timed out");
        }

        foreach($this->peer->pollDataChannels() as $channel){
            $label = $channel->getLabel();

            if(
                $label !== "ReliableDataChannel" &&
                $label !== "UnreliableDataChannel"
            ){
                $channel->close();
                throw new RuntimeException("Unexpected data channel");
            }

            if(isset($this->channels[$label])){
                $channel->close();
                throw new RuntimeException("Duplicate data channel");
            }

            if(
                $label === "ReliableDataChannel" &&
                (
                    $channel->isUnordered() ||
                    $channel->getMaxRetransmits() !== null ||
                    $channel->getMaxPacketLifeTime() !== null
                )
            ){
                $channel->close();
                throw new RuntimeException(
                    "Reliable channel has incompatible delivery settings"
                );
            }

            $this->channels[$label] = $channel;
        }

        $messages = [];

        foreach($this->channels as $label => $channel){
            if($channel->isClosed()){
                $this->close();
                return [];
            }

            if(!$channel->isOpen()){
                continue;
            }

            for(
                $count = 0;
                $count < self::MAX_MESSAGES_PER_POLL;
                ++$count
            ){
                $payload = $channel->receive();

                if($payload === null){
                    break;
                }

                if(strlen($payload) > self::MAX_MESSAGE_BYTES){
                    throw new RuntimeException("WebRTC message is too large");
                }

                $messages[] = [
                    "channel" => $label,
                    "payload" => $payload
                ];
            }
        }

        return $messages;
    }

    public function getReliableMessageSize() : int{
        $channel = $this->channels["ReliableDataChannel"] ?? null;

        if($channel === null || !$channel->isOpen()){
            throw new LogicException("Reliable data channel is not open");
        }

        return min(self::MAX_MESSAGE_BYTES, $channel->getMaxMessageSize());
    }

    public function getReliableBufferedAmount() : int{
        $channel = $this->channels["ReliableDataChannel"] ?? null;

        if($channel === null || !$channel->isOpen()){
            return PHP_INT_MAX;
        }

        return $channel->getBufferedAmount();
    }

    /**
     * Sends an already-framed nethernet message.
     *
     * Returns the native send() result unchanged.
     * Do not automatically resend based on this boolean.
     */
    public function sendRaw(
        string $payload,
        bool $reliable = true
    ) : bool{
        $this->assertOpen();

        $label = $reliable
            ? "ReliableDataChannel"
            : "UnreliableDataChannel";

        $channel = $this->channels[$label] ?? null;

        if($channel === null || !$channel->isOpen()){
            throw new LogicException("Data channel is not open");
        }

        $length = strlen($payload);

        if(
            $length > self::MAX_MESSAGE_BYTES ||
            $length > $channel->getMaxMessageSize()
        ){
            throw new InvalidArgumentException(
                "Message exceeds the channel's message size limit"
            );
        }

        if(
            $channel->getBufferedAmount() + $length >
            self::MAX_BUFFERED_BYTES
        ){
            throw new RuntimeException("WebRTC send buffer is full");
        }

        return $channel->send($payload);
    }

    public function close() : void{
        if($this->closed){
            return;
        }

        $this->closed = true;

        try{
            $this->peer->close();
        }finally{
            $this->channels = [];
        }
    }

    private function assertOpen() : void{
        if($this->closed){
            throw new LogicException("WebRTC session is closed");
        }
    }
}