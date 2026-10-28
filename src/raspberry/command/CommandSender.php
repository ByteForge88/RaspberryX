<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\permission\Permissible;

interface CommandSender extends Permissible{

    public function getName() : string;

    public function sendMessage(string $message) : void;

}