<?php

declare(strict_types=1);

namespace raspberry\network\nethernet;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

class WebRtcSessionManager{

    /** @var array<int, WebRtcSession> */
    private array $sessions = [];

    private int $nextId = 1;

    private int $maxSessions;

    public function __construct(int $maxSessions = 100){
        $this->maxSessions = $maxSessions;

        if(!extension_loaded("webrtc")){
            throw new RuntimeException("The webrtc extension is required");
        }

        if($maxSessions < 1){
            throw new InvalidArgumentException("Maximum session count must be positive");
        }
    }

    public function acceptOffer(string $sdp) : WebRtcSession{
        if(count($this->sessions) >= $this->maxSessions){
            throw new RuntimeException("Maximum session count reached");
        }

        $id = $this->nextId++;
        $session = new WebRtcSession($id, $sdp);

        $this->sessions[$id] = $session;

        return $session;
    }

    public function getSession(int $id) : ?WebRtcSession{
        return $this->sessions[$id] ?? null;
    }

    public function getSessionCount() : int{
        return count($this->sessions);
    }

    public function closeSession(int $id) : void{
        $session = $this->sessions[$id] ?? null;

        unset($this->sessions[$id]);

        $session?->close();
    }

    /**
     * Call frequently from the server loop.
     *
     * @param callable(WebRtcSession, string): void $onAnswer
     * @param callable(WebRtcSession, string, string): void $onCandidate
     * @param callable(WebRtcSession, string, string): void $onMessage
     * @param callable(int, string): void $onClose
     */
    public function poll(
        callable $onAnswer,
        callable $onCandidate,
        callable $onMessage,
        callable $onClose
    ) : void{
        foreach($this->sessions as $id => $session){
            $closeReason = "Connection closed";

            try{
                $messages = $session->poll();

                if(!$session->isClosed()){
                    $answer = $session->takeLocalAnswer();

                    if($answer !== null){
                        $onAnswer($session, $answer);
                    }

                    foreach($session->takeLocalCandidates() as $candidate){
                        $onCandidate($session, $candidate["candidate"], $candidate["mid"]);
                    }

                    foreach($messages as $message){
                        if($session->isClosed()){
                            break;
                        }

                        $onMessage($session, $message["channel"], $message["payload"]);
                    }
                }
            }catch(Throwable $exception){
                $closeReason = $exception::class . ": " . $exception->getMessage();

                $this->closeSession($id);
                $onClose($id, $closeReason);
                continue;
            }

            if($session->isClosed()){
                unset($this->sessions[$id]);
                $onClose($id, $closeReason);
            }
        }
    }

    public function shutdown() : void{
        foreach(array_keys($this->sessions) as $id){
            $this->closeSession($id);
        }
    }
}