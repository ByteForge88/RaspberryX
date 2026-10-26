<?php

declare(strict_types=1);

namespace raspberry\network\nethernet;

use raspberry\utils\Logger;

use Psr\Http\Message\ServerRequestInterface;

use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Http\Middleware\StreamingRequestMiddleware;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use React\Socket\SocketServer;

use Closure;
use RuntimeException;
use SplQueue;
use Throwable;

use function array_keys;
use function explode;
use function hrtime;
use function preg_match;
use function strlen;
use function strtolower;
use function trim;

class NetherNetTransport{

    private WebRtcSessionManager $sessions;

    private ?SocketServer $socket = null;
    private ?HttpServer $http = null;

    private NetherNetIdentity $identity;
    private array $serverInfo;
    private Closure $onPayload;
    private Logger $logger;

    /** @var null|Closure(int, string): void */
    private ?Closure $onClose;

    /** @var array<int, Deferred> */
    private array $pending = [];

    /** @var array<int, int> */
    private array $pendingSince = [];

    /** @var array<int, \stdClass> */
    private array $identities = [];

    /** @var array<int, NetherNetFraming> */
    private array $framing = [];

    /** @var array<int, SplQueue<string>> */
    private array $outgoing = [];

    /** @var array<int, int> */
    private array $queuedBytes = [];

    public function __construct(NetherNetIdentity $identity, array $serverInfo, Closure $onPayload, Logger $logger, ?Closure $onClose = null){
        $this->identity = $identity;
        $this->serverInfo = $serverInfo;
        $this->onPayload = $onPayload;
        $this->logger = $logger;
        $this->onClose = $onClose;

        $this->sessions = new WebRtcSessionManager(100);
    }

    public function start(string $address = "0.0.0.0:19132") : void{
        if($this->socket !== null){
            throw new RuntimeException("Transport is already listening");
        }

        $this->http = new HttpServer(
            new StreamingRequestMiddleware(),
            new LimitConcurrentRequestsMiddleware(32),
            new RequestBodyBufferMiddleware(65536),
            fn(ServerRequestInterface $request) => $this->handle($request)
        );

        $this->http->on("error", function(Throwable $error) : void{
            $this->logger->warning("Signaling HTTP error: " . $error->getMessage());
        });

        $this->socket = new SocketServer($address);
        $this->socket->on(
            "connection",
            function(\React\Socket\ConnectionInterface $connection) : void{
                $this->logger->debug("Signaling TCP connection from " . ($connection->getRemoteAddress() ?? "unknown"));

                $prefix = "";
                $checked = false;

                $connection->on(
                    "data",
                    function(string $data) use ($connection, &$prefix, &$checked) : void{
                        if($checked){
                            return;
                        }

                        $prefix .= substr($data, 0, 3 - strlen($prefix));

                        if(strlen($prefix) < 3){
                            return;
                        }

                        $checked = true;

                        if(
                            ord($prefix[0]) === 0x16 &&
                            ord($prefix[1]) === 0x03
                        ){
                            $this->logger->debug("Closing HTTPS probe: this endpoint serves plain HTTP");

                            $connection->close();
                            return;
                        }

                        $this->logger->debug("Non-TLS connection; continuing HTTP processing");
                    }
                );
            }
        );
        $this->http->listen($this->socket);

        $this->logger->debug("NetherNet signaling listening on http://{$address}");
    }

    private function handle(ServerRequestInterface $request) : Response|PromiseInterface{
        $path = $request->getUri()->getPath();
        $this->logger->debug("Signaling request: " . $request->getMethod() . " " . $path);

        if($request->getMethod() === "GET" && $path === "/v1/join"){
            return Response::json($this->serverInfo);
        }

        if($request->getMethod() !== "POST" || !preg_match('~^/v1/join/[^/]{1,128}$~D', $path)){
            return new Response(404, [], "Not found");
        }

        $type = strtolower(trim(explode(";", $request->getHeaderLine("Content-Type"), 2)[0]));

        if($type !== "application/sdp"){
            return new Response(415, [], "Expected application/sdp");
        }

        if($this->sessions->getSessionCount() >= 100){
            return new Response(503, [], "Server is full");
        }

        try{
            $verified = $this->identity->verifyOffer(
                (string) $request->getBody()
            );
        }catch(Throwable $error){
            $this->logger->warning("Client identity rejected: " . $error::class . ": " . $error->getMessage());

            return new Response(403, [], "Client identity rejected");
        }

        try{
            $session = $this->sessions->acceptOffer($verified["offer"]);
        }catch(Throwable $error){
            $this->logger->warning("WebRTC peer creation failed: " . $error->getMessage());

            return new Response(503, [], "Unable to create peer");
        }

        $id = $session->getId();

        $this->identities[$id] = $verified["claims"];
        $this->framing[$id] = new NetherNetFraming();
        $this->outgoing[$id] = new SplQueue();
        $this->queuedBytes[$id] = 0;

        $deferred = new Deferred();

        $this->pending[$id] = $deferred;
        $this->pendingSince[$id] = hrtime(true);

        return $deferred->promise();
    }

    public function poll() : void{
        $this->sessions->poll(
            function(WebRtcSession $session, string $answer) : void{
                $id = $session->getId();
                $deferred = $this->pending[$id] ?? null;

                if($deferred === null){
                    throw new RuntimeException("No pending signaling request");
                }

                $signedAnswer = $this->identity->signAnswer($answer);

                unset($this->pending[$id], $this->pendingSince[$id]);

                $deferred->resolve(new Response(
                    200,
                    [
                        "Content-Type" => "application/sdp",
                        "Cache-Control" => "no-store"
                    ],
                    $signedAnswer
                ));

                $this->logger->debug("SDP answer sent for session {$id}");
            },

            static function(WebRtcSession $session, string $candidate, string $mid) : void{},

            function(WebRtcSession $session, string $channel, string $message) : void{
                $id = $session->getId();
                $reliable = $channel === "ReliableDataChannel";

                $payload = $this->framing[$id]->decode($message, $reliable);

                if($payload !== null){
                    ($this->onPayload)(
                        $id,
                        $payload,
                        $reliable,
                        $this->identities[$id]
                    );
                }
            },

            function(int $id, string $reason) : void{
                $this->forget($id, $reason);
            }
        );

        foreach($this->pendingSince as $id => $startedAt){
            if(hrtime(true) - $startedAt > 15_000_000_000){
                $this->disconnect($id, "Signaling timed out");
            }
        }

        foreach(array_keys($this->framing) as $id){
            try{
                $this->framing[$id]->checkTimeout();
                $this->flush($id);
            }catch(Throwable $error){
                $this->disconnect($id, $error->getMessage());
            }
        }
    }

    public function sendReliable(int $id, string $payload) : void{
        $session = $this->sessions->getSession($id);

        if($session === null || !isset($this->framing[$id])){
            throw new RuntimeException("Unknown session");
        }

        $messages = $this->framing[$id]->encode(
            $payload,
            $session->getReliableMessageSize()
        );

        $additional = 0;

        foreach($messages as $message){
            $additional += strlen($message);
        }

        if($this->queuedBytes[$id] + $additional > 4 * 1024 * 1024){
            throw new RuntimeException("Session outgoing queue is full");
        }

        foreach($messages as $message){
            $this->outgoing[$id]->enqueue($message);
        }

        $this->queuedBytes[$id] += $additional;
    }

    private function flush(int $id) : void{
        $session = $this->sessions->getSession($id);
        $queue = $this->outgoing[$id] ?? null;

        if($session === null || $queue === null){
            return;
        }

        for($sent = 0; $sent < 16 && !$queue->isEmpty(); ++$sent){
            if($session->getReliableBufferedAmount() > 1048576){
                return;
            }

            $message = $queue->bottom();

            $session->sendRaw($message, true);

            $queue->dequeue();
            $this->queuedBytes[$id] -= strlen($message);
        }
    }

    public function disconnect(int $id, string $reason) : void{
        $this->sessions->closeSession($id);
        $this->forget($id, $reason);
    }

    private function forget(int $id, string $reason) : void{
        if(isset($this->pending[$id])){
            $this->pending[$id]->resolve(new Response(503, [], "Connection could not be established"));
        }

        unset(
            $this->pending[$id],
            $this->pendingSince[$id],
            $this->identities[$id],
            $this->framing[$id],
            $this->outgoing[$id],
            $this->queuedBytes[$id]
        );

        $this->logger->debug("Session {$id} closed: {$reason}");

        if($this->onClose !== null){
            ($this->onClose)($id, $reason);
        }
    }

    public function shutdown() : void{
        $this->socket?->close();
        $this->socket = null;

        foreach(array_keys($this->identities) as $id){
            $this->disconnect($id, "Server stopping");
        }

        $this->sessions->shutdown();
    }
}