<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Exceptions;

use Exception;

class CommandAuthorizationException extends Exception
{
    public function __construct(
        private readonly string $commandType,
        private readonly string $userId
    ) {
        parent::__construct("User {$userId} is not authorized to execute command: {$commandType}");
    }

    public function getCommandType(): string
    {
        return $this->commandType;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }
}