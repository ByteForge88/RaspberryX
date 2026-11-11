<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\player\Player;

use function str_replace;

class PlayerChatEvent extends PlayerEvent implements Cancellable{
    use CancellableTrait;

    public const DEFAULT_FORMAT = "<{player}> {message}";

    private string $message;

    private string $format = self::DEFAULT_FORMAT;

    /** @var list<Player> */
    private array $recipients;

    /**
     * @param list<Player> $recipients
     */
    public function __construct(Player $player, string $message, array $recipients){
        $this->player = $player;
        $this->message = $message;
        $this->recipients = $recipients;
    }

    public function getMessage() : string{
        return $this->message;
    }

    public function setMessage(string $message) : void{
        $this->message = $message;
    }

    public function getFormat() : string{
        return $this->format;
    }

    public function setFormat(string $format) : void{
        $this->format = $format;
    }

    /**
     * @return list<Player>
     */
    public function getRecipients() : array{
        return $this->recipients;
    }

    /**
     * @param list<Player> $recipients
     */
    public function setRecipients(array $recipients) : void{
        $this->recipients = $recipients;
    }

    /**
     * The line as it is sent, with the format filled in.
     */
    public function getLine() : string{
        return str_replace(["{player}", "{message}"], [$this->player->getName(), $this->message], $this->format);
    }
}