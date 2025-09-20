<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Saga;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\Core\Events\DomainEventInterface;
use Illuminate\Support\Str;

abstract class AbstractSaga implements SagaInterface
{
    protected string $sagaId;
    protected SagaState $state = SagaState::PENDING;
    protected array $metadata = [];
    protected array $compensationCommands = [];
    protected array $eventHandlers = [];
    protected int $timeout = 3600; // 1 hour default

    public function __construct(?string $sagaId = null)
    {
        $this->sagaId = $sagaId ?? $this->generateSagaId();
        $this->registerEventHandlers();
    }

    public function getSagaId(): string
    {
        return $this->sagaId;
    }

    public function getSagaType(): string
    {
        return static::class;
    }

    public function getState(): SagaState
    {
        return $this->state;
    }

    public function canHandle(DomainEventInterface $event): bool
    {
        $eventType = get_class($event);
        return isset($this->eventHandlers[$eventType]) && $this->state->isActive();
    }

    public function handle(DomainEventInterface $event): array
    {
        if (!$this->canHandle($event)) {
            return [];
        }

        $eventType = get_class($event);
        $handler = $this->eventHandlers[$eventType];

        try {
            $this->transitionTo(SagaState::RUNNING);
            $commands = $this->$handler($event);

            if ($this->shouldComplete($event)) {
                $this->transitionTo(SagaState::COMPLETED);
            }

            return $commands;
        } catch (\Throwable $e) {
            $this->transitionTo(SagaState::FAILED);
            $this->metadata['error'] = [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString(),
                'occurred_at' => now()->toISOString(),
            ];

            // Return compensation commands on failure
            return $this->getCompensationCommands();
        }
    }

    public function getCompensationCommands(): array
    {
        return $this->compensationCommands;
    }

    public function isCompleted(): bool
    {
        return $this->state === SagaState::COMPLETED;
    }

    public function hasFailed(): bool
    {
        return $this->state === SagaState::FAILED;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): void
    {
        $this->metadata = array_merge($this->metadata, $metadata);
    }

    public function hydrate(string $sagaId, SagaState $state, array $metadata): void
    {
        $this->sagaId = $sagaId;
        $this->state = $state;
        $this->metadata = $metadata;
    }

    /**
     * Create saga instance from persisted state
     */
    public static function fromPersistedState(string $sagaId, SagaState $state, array $metadata): static
    {
        $saga = new static($sagaId);
        $saga->hydrate($sagaId, $state, $metadata);
        return $saga;
    }

    protected function transitionTo(SagaState $newState): void
    {
        if (!$this->state->canTransitionTo($newState)) {
            throw new \InvalidArgumentException(
                "Invalid state transition from {$this->state->value} to {$newState->value}"
            );
        }

        $oldState = $this->state;
        $this->state = $newState;

        $this->metadata['state_transitions'][] = [
            'from' => $oldState->value,
            'to' => $newState->value,
            'timestamp' => now()->toISOString(),
        ];

        $this->onStateChanged($oldState, $newState);
    }

    protected function addCompensationCommand(CommandInterface $command): void
    {
        array_unshift($this->compensationCommands, $command);
    }

    protected function registerEventHandler(string $eventClass, string $methodName): void
    {
        $this->eventHandlers[$eventClass] = $methodName;
    }

    protected function generateSagaId(): string
    {
        return Str::uuid()->toString();
    }

    /**
     * Override this method to register event handlers
     */
    abstract protected function registerEventHandlers(): void;

    /**
     * Override this method to define completion conditions
     */
    protected function shouldComplete(DomainEventInterface $event): bool
    {
        return false;
    }

    /**
     * Override this method to handle state transitions
     */
    protected function onStateChanged(SagaState $oldState, SagaState $newState): void
    {
        // Default implementation does nothing
    }
}