<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Events;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\Core\Events\DomainEvent;

class CommandFailedEvent extends DomainEvent
{
    public function __construct(
        private readonly CommandInterface $command,
        private readonly \Throwable $exception,
        private readonly float $executionTime,
        private readonly array $metadata = []
    ) {
        parent::__construct();
    }

    public function getCommand(): CommandInterface
    {
        return $this->command;
    }

    public function getException(): \Throwable
    {
        return $this->exception;
    }

    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }

    public function getCommandType(): string
    {
        return get_class($this->command);
    }

    public function getErrorMessage(): string
    {
        return $this->exception->getMessage();
    }

    public function getErrorCode(): int
    {
        return $this->exception->getCode();
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function toArray(): array
    {
        return [
            'command_type' => $this->getCommandType(),
            'execution_time' => $this->executionTime,
            'error_message' => $this->getErrorMessage(),
            'error_code' => $this->getErrorCode(),
            'error_file' => $this->exception->getFile(),
            'error_line' => $this->exception->getLine(),
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurredAt->toISOString(),
        ];
    }
}