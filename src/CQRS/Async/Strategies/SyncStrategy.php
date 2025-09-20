<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Async\Strategies;

use LaravelModularDDD\CQRS\Async\AsyncStrategyInterface;
use LaravelModularDDD\CQRS\Async\AsyncStatus;
use LaravelModularDDD\CQRS\Async\AsyncStatusRepository;
use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\CQRS\Contracts\CommandBusInterface;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class SyncStrategy implements AsyncStrategyInterface
{
    public function __construct(
        private readonly AsyncStatusRepository $statusRepository,
        private readonly CommandBusInterface $commandBus
    ) {}

    public function dispatch(CommandInterface $command): string
    {
        $id = Str::uuid()->toString();

        // Set initial status
        $this->statusRepository->setStatus($id, AsyncStatus::PROCESSING, [
            'command_type' => $command->getCommandName(),
            'command_id' => $command->getCommandId(),
            'started_at' => now()->toISOString(),
        ]);

        try {
            // Execute command synchronously
            $result = $this->commandBus->processCommand($command);

            // Store result and mark as completed
            $this->statusRepository->setResult($id, $result);

            Log::info('Sync async command completed successfully', [
                'async_id' => $id,
                'command_type' => $command->getCommandName(),
            ]);

        } catch (\Throwable $e) {
            // Store error and mark as failed
            $this->statusRepository->setError($id, $e);

            Log::error('Sync async command failed', [
                'async_id' => $id,
                'command_type' => $command->getCommandName(),
                'error' => $e->getMessage(),
            ]);
        }

        return $id;
    }

    public function getStatus(string $id): AsyncStatus
    {
        return $this->statusRepository->getStatus($id);
    }

    public function getResult(string $id): mixed
    {
        return $this->statusRepository->getResult($id);
    }

    public function cancel(string $id): bool
    {
        // Cannot cancel synchronous execution
        return false;
    }

    public function supports(CommandInterface $command): bool
    {
        // Sync strategy supports all commands
        return true;
    }

    public function getName(): string
    {
        return 'sync';
    }
}