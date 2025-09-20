<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Saga;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\Core\Events\DomainEventInterface;

class CompensationSaga extends AbstractSaga
{
    protected array $compensationCommands;
    private int $currentStep = 0;

    public function __construct(
        string $originalSagaId,
        array $compensationCommands,
        ?string $sagaId = null
    ) {
        $this->compensationCommands = $compensationCommands;
        $this->metadata['original_saga_id'] = $originalSagaId;
        $this->metadata['total_steps'] = count($compensationCommands);

        parent::__construct($sagaId);
    }

    public function getSagaType(): string
    {
        return 'compensation';
    }

    protected function registerEventHandlers(): void
    {
        $this->registerEventHandler(
            'LaravelModularDDD\\CQRS\\Events\\CommandExecutedEvent',
            'handleCommandExecuted'
        );
        $this->registerEventHandler(
            'LaravelModularDDD\\CQRS\\Events\\CommandFailedEvent',
            'handleCommandFailed'
        );
    }

    protected function handleCommandExecuted(DomainEventInterface $event): array
    {
        $this->currentStep++;
        $this->metadata['completed_steps'] = $this->currentStep;

        // Check if all compensation commands are executed
        if ($this->currentStep >= count($this->compensationCommands)) {
            $this->transitionTo(SagaState::COMPLETED);
            return [];
        }

        // Return next compensation command
        return [$this->compensationCommands[$this->currentStep]];
    }

    protected function handleCommandFailed(DomainEventInterface $event): array
    {
        // Compensation command failed - this is a critical situation
        $this->transitionTo(SagaState::FAILED);
        $this->metadata['failed_at_step'] = $this->currentStep;
        $this->metadata['compensation_failure'] = true;

        // No further commands - manual intervention required
        return [];
    }

    protected function shouldComplete(DomainEventInterface $event): bool
    {
        return $this->currentStep >= count($this->compensationCommands);
    }

    public function getProgress(): array
    {
        return [
            'current_step' => $this->currentStep,
            'total_steps' => count($this->compensationCommands),
            'completed_steps' => $this->metadata['completed_steps'] ?? 0,
            'percentage' => count($this->compensationCommands) > 0
                ? ($this->currentStep / count($this->compensationCommands)) * 100
                : 100,
        ];
    }

    public function getRemainingCommands(): array
    {
        return array_slice($this->compensationCommands, $this->currentStep);
    }
}