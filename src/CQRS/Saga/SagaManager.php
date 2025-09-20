<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Saga;

use LaravelModularDDD\CQRS\Contracts\CommandBusInterface;
use LaravelModularDDD\CQRS\Saga\Persistence\SagaRepositoryInterface;
use LaravelModularDDD\Core\Events\DomainEventInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SagaManager
{
    private array $sagaTypes = [];

    public function __construct(
        private readonly SagaRepositoryInterface $repository,
        private readonly CommandBusInterface $commandBus
    ) {}

    /**
     * Register a saga type
     */
    public function registerSaga(string $sagaClass): void
    {
        if (!is_subclass_of($sagaClass, SagaInterface::class)) {
            throw new \InvalidArgumentException("Class {$sagaClass} must implement SagaInterface");
        }

        $this->sagaTypes[$sagaClass] = $sagaClass;
    }

    /**
     * Start a new saga
     */
    public function startSaga(string $sagaClass, DomainEventInterface $event, array $metadata = []): SagaInterface
    {
        if (!isset($this->sagaTypes[$sagaClass])) {
            throw new \InvalidArgumentException("Saga type {$sagaClass} is not registered");
        }

        $saga = new $sagaClass();
        $saga->setMetadata(array_merge($metadata, [
            'started_at' => now()->toISOString(),
            'triggering_event' => get_class($event),
        ]));

        // Handle the initial event
        $commands = $saga->handle($event);

        // Persist saga
        $this->repository->save($saga);

        // Dispatch generated commands
        $this->dispatchCommands($commands, $saga);

        Log::info('Saga started', [
            'saga_id' => $saga->getSagaId(),
            'saga_type' => $saga->getSagaType(),
            'triggering_event' => get_class($event),
            'commands_generated' => count($commands),
        ]);

        return $saga;
    }

    /**
     * Handle incoming event for existing sagas
     */
    public function handleEvent(DomainEventInterface $event): void
    {
        // Find sagas that can handle this event
        $activeSagas = $this->repository->findActiveSagas();

        foreach ($activeSagas as $saga) {
            if ($saga->canHandle($event)) {
                $this->processEventForSaga($saga, $event);
            }
        }

        // Check if any saga types should be started by this event
        $this->checkForNewSagas($event);
    }

    /**
     * Process event for a specific saga
     */
    public function processEventForSaga(SagaInterface $saga, DomainEventInterface $event): void
    {
        DB::transaction(function () use ($saga, $event) {
            try {
                $commands = $saga->handle($event);

                // Update saga state
                $this->repository->save($saga);

                // Dispatch commands
                $this->dispatchCommands($commands, $saga);

                Log::debug('Event processed by saga', [
                    'saga_id' => $saga->getSagaId(),
                    'saga_type' => $saga->getSagaType(),
                    'event_type' => get_class($event),
                    'saga_state' => $saga->getState()->value,
                    'commands_generated' => count($commands),
                ]);

            } catch (\Throwable $e) {
                Log::error('Error processing event in saga', [
                    'saga_id' => $saga->getSagaId(),
                    'event_type' => get_class($event),
                    'error' => $e->getMessage(),
                ]);

                // Start compensation if saga failed
                if ($saga->hasFailed()) {
                    $this->startCompensation($saga);
                }

                throw $e;
            }
        });
    }

    /**
     * Start compensation process for failed saga
     */
    public function startCompensation(SagaInterface $saga): void
    {
        if (!$saga->hasFailed()) {
            return;
        }

        $compensationCommands = $saga->getCompensationCommands();

        if (empty($compensationCommands)) {
            Log::info('No compensation commands for failed saga', [
                'saga_id' => $saga->getSagaId(),
            ]);
            return;
        }

        // Create compensation saga
        $compensationSaga = new CompensationSaga($saga->getSagaId(), $compensationCommands);
        $this->repository->save($compensationSaga);

        // Dispatch compensation commands
        foreach ($compensationCommands as $command) {
            try {
                $this->commandBus->dispatch($command);
            } catch (\Throwable $e) {
                Log::error('Compensation command failed', [
                    'saga_id' => $saga->getSagaId(),
                    'command_type' => get_class($command),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Compensation started for failed saga', [
            'saga_id' => $saga->getSagaId(),
            'compensation_commands' => count($compensationCommands),
        ]);
    }

    /**
     * Handle saga timeouts
     */
    public function handleTimeouts(): void
    {
        $timedOutSagas = $this->repository->findTimedOutSagas();

        foreach ($timedOutSagas as $saga) {
            $this->timeoutSaga($saga);
        }
    }

    /**
     * Get saga statistics
     */
    public function getStatistics(): array
    {
        return $this->repository->getStatistics();
    }

    /**
     * Get saga by ID
     */
    public function getSaga(string $sagaId): ?SagaInterface
    {
        return $this->repository->findById($sagaId);
    }

    /**
     * Get active sagas
     */
    public function getActiveSagas(): array
    {
        return $this->repository->findActiveSagas();
    }

    private function dispatchCommands(array $commands, SagaInterface $saga): void
    {
        foreach ($commands as $command) {
            try {
                $this->commandBus->dispatch($command);
            } catch (\Throwable $e) {
                Log::error('Command dispatch failed in saga', [
                    'saga_id' => $saga->getSagaId(),
                    'command_type' => get_class($command),
                    'error' => $e->getMessage(),
                ]);

                // This will trigger compensation
                throw $e;
            }
        }
    }

    private function checkForNewSagas(DomainEventInterface $event): void
    {
        foreach ($this->sagaTypes as $sagaClass) {
            // Check if this saga type should be started by this event
            // This would be configuration-driven in a real implementation
            if ($this->shouldStartSaga($sagaClass, $event)) {
                $this->startSaga($sagaClass, $event);
            }
        }
    }

    private function shouldStartSaga(string $sagaClass, DomainEventInterface $event): bool
    {
        // Create temporary instance to check if it can handle the event
        $tempSaga = new $sagaClass();
        return $tempSaga->canHandle($event) && $tempSaga->getState() === SagaState::PENDING;
    }

    private function timeoutSaga(SagaInterface $saga): void
    {
        try {
            // Transition to timed out state
            $reflection = new \ReflectionClass($saga);
            $method = $reflection->getMethod('transitionTo');
            $method->setAccessible(true);
            $method->invoke($saga, SagaState::TIMED_OUT);

            $this->repository->save($saga);

            // Start compensation for timed out saga
            $this->startCompensation($saga);

            Log::warning('Saga timed out', [
                'saga_id' => $saga->getSagaId(),
                'saga_type' => $saga->getSagaType(),
            ]);

        } catch (\Throwable $e) {
            Log::error('Error timing out saga', [
                'saga_id' => $saga->getSagaId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}