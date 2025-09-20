<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Exceptions;

use Exception;
use Illuminate\Support\MessageBag;

class CommandValidationException extends Exception
{
    public function __construct(
        private readonly string $commandType,
        private readonly MessageBag $errors
    ) {
        $errorString = $errors->all();
        $message = "Validation failed for command {$commandType}: " . implode(', ', $errorString);

        parent::__construct($message);
    }

    public function getCommandType(): string
    {
        return $this->commandType;
    }

    public function getErrors(): MessageBag
    {
        return $this->errors;
    }
}