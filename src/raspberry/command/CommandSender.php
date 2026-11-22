<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\permission\Permissible;

interface CommandSender extends Permissible{

    public function getName() : string;

    public function sendMessage(string $message) : void;

    /**
     * What a command said when it was done. A player gets it as the command's output.
     */
    public function sendCommandFeedback(CommandFeedback $feedback) : void;

}