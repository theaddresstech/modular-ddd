<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Jobs;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\CQRS\Contracts\CommandBusInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCommandJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $maxExceptions = 3;

    public function __construct(
        private readonly CommandInterface $command
    ) {
        $this->timeout = $command->getTimeout();
        $this->tries = $command->shouldRetry() ? $command->getMaxRetries() : 1;
    }

    public function handle(CommandBusInterface $commandBus): void
    {
        Log::info('Processing command asynchronously', [
            'command_id' => $this->command->getCommandId(),
            'command_type' => $this->command->getCommandName(),
            'attempt' => $this->attempts(),
        ]);

        try {
            $result = $commandBus->processCommand($this->command);

            Log::info('Command processed successfully', [
                'command_id' => $this->command->getCommandId(),
                'command_type' => $this->command->getCommandName(),
                'result_type' => gettype($result),
            ]);
        } catch (\Exception $e) {
            Log::error('Command processing failed', [
                'command_id' => $this->command->getCommandId(),
                'command_type' => $this->command->getCommandName(),
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('Command failed after all retries', [
            'command_id' => $this->command->getCommandId(),
            'command_type' => $this->command->getCommandName(),
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // TODO: Send to dead letter queue or alert system
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
            'command:' . $this->command->getCommandName(),
            'command_id:' . $this->command->getCommandId(),
        ];
    }
}