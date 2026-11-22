<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\lang\Translation;

/**
 * @internal
 */
class CommandFeedback{

    /** @var list<array{Translation|string, bool}> */
    private array $messages = [];

    private int $successCount = 0;

    public function add(Translation|string $message, bool $success) : void{
        $this->messages[] = [$message, $success];

        if($success){
            ++$this->successCount;
        }
    }

    /**
     * @return list<array{Translation|string, bool}>
     */
    public function getMessages() : array{
        return $this->messages;
    }

    public function getSuccessCount() : int{
        return $this->successCount;
    }
}