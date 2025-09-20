<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Events;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\Core\Events\DomainEvent;

class CommandExecutedEvent extends DomainEvent
{
    public function __construct(
        private readonly CommandInterface $command,
        private readonly mixed $result,
        private readonly float $executionTime,
        private readonly array $metadata = []
    ) {
        parent::__construct();
    }

    public function getCommand(): CommandInterface
    {
        return $this->command;
    }

    public function getResult(): mixed
    {
        return $this->result;
    }

    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }

    public function getCommandType(): string
    {
        return get_class($this->command);
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
            'has_result' => $this->result !== null,
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurredAt->toISOString(),
        ];
    }
}