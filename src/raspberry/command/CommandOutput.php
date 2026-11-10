<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\lang\Translation;

class CommandOutput{

    /** @var list<CommandMessage> */
    private array $messages = [];

    private int $successCount = 0;

    /**
     * @param list<string|int|float|Translation> $params
     */
    public function success(string $key, array $params = []) : void{
        $this->messages[] = new CommandMessage(new Translation($key, $params), true);
        ++$this->successCount;
    }

    public function successText(string $text) : void{
        $this->messages[] = new CommandMessage($text, true);
        ++$this->successCount;
    }

    public function addSuccess() : void{
        ++$this->successCount;
    }

    /**
     * @param list<string|int|float|Translation> $params
     */
    public function error(string $key, array $params = []) : void{
        $this->messages[] = new CommandMessage(new Translation($key, $params), false);
    }

    public function errorText(string $text) : void{
        $this->messages[] = new CommandMessage($text, false);
    }

    public function addException(CommandException $exception) : void{
        $this->messages[] = new CommandMessage($exception->getTranslation() ?? $exception->getMessage(), false);
    }

    /**
     * @return list<CommandMessage>
     */
    public function getMessages() : array{
        return $this->messages;
    }

    public function getSuccessCount() : int{
        return $this->successCount;
    }
}