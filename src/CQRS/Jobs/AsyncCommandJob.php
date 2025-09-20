<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Jobs;

use LaravelModularDDD\CQRS\Async\AsyncStatus;
use LaravelModularDDD\CQRS\Async\AsyncStatusRepository;
use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\CQRS\Contracts\CommandBusInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AsyncCommandJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $maxExceptions = 3;

    public function __construct(
        private readonly string $asyncId,
        private readonly CommandInterface $command
    ) {
        $this->timeout = $command->getTimeout();
        $this->tries = $command->shouldRetry() ? $command->getMaxRetries() : 1;
    }

    public function handle(
        CommandBusInterface $commandBus,
        AsyncStatusRepository $statusRepository
    ): void {
        Log::info('Processing async command', [
            'async_id' => $this->asyncId,
            'command_id' => $this->command->getCommandId(),
            'command_type' => $this->command->getCommandName(),
            'attempt' => $this->attempts(),
        ]);

        // Update status to processing
        $statusRepository->setStatus($this->asyncId, AsyncStatus::PROCESSING, [
            'started_at' => now()->toISOString(),
            'attempt' => $this->attempts(),
        ]);

        try {
            // Execute the command
            $result = $commandBus->processCommand($this->command);

            // Store result and mark as completed
            $statusRepository->setResult($this->asyncId, $result);

            Log::info('Async command completed successfully', [
                'async_id' => $this->asyncId,
                'command_id' => $this->command->getCommandId(),
                'command_type' => $this->command->getCommandName(),
                'result_type' => gettype($result),
            ]);

        } catch (\Throwable $e) {
            Log::error('Async command processing failed', [
                'async_id' => $this->asyncId,
                'command_id' => $this->command->getCommandId(),
                'command_type' => $this->command->getCommandName(),
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
            ]);

            // Store error for retrieval
            $statusRepository->setError($this->asyncId, $e);

            throw $e; // Re-throw to trigger job retry if configured
        }
    }

    public function failed(\Throwable $exception): void
    {
        $statusRepository = app(AsyncStatusRepository::class);

        Log::critical('Async command failed after all retries', [
            'async_id' => $this->asyncId,
            'command_id' => $this->command->getCommandId(),
            'command_type' => $this->command->getCommandName(),
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Ensure error is stored and status is marked as failed
        $statusRepository->setError($this->asyncId, $exception);
    }

    public function backoff(): array
    {
        // Exponential backoff: 1s, 4s, 16s
        return [1, 4, 16];
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(10);
    }

    public function tags(): array
    {
        return [
            'async_command',
            'command:' . $this->command->getCommandName(),
            'async_id:' . $this->asyncId,
        ];
    }
}