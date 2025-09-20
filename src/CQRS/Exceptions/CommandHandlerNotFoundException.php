<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Exceptions;

use Exception;

class CommandHandlerNotFoundException extends Exception
{
    public function __construct(string $commandType)
    {
        parent::__construct("No handler found for command: {$commandType}");
    }
}