<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\lang\Language;
use raspberry\lang\Translation;

class CommandMessage{

    private Translation|string $message;

    private bool $success;

    public function __construct(Translation|string $message, bool $success){
        $this->message = $message;
        $this->success = $success;
    }

    public function getMessage() : Translation|string{
        return $this->message;
    }

    public function isSuccess() : bool{
        return $this->success;
    }

    public function toText(Language $language) : string{
        return $this->message instanceof Translation ? $language->translateObject($this->message) : $this->message;
    }
}