<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Async\Strategies;

use LaravelModularDDD\CQRS\Async\AsyncStrategyInterface;
use LaravelModularDDD\CQRS\Async\AsyncStatus;
use LaravelModularDDD\CQRS\Async\AsyncStatusRepository;
use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\CQRS\Jobs\AsyncCommandJob;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;

class LaravelQueueStrategy implements AsyncStrategyInterface
{
    public function __construct(
        private readonly AsyncStatusRepository $statusRepository,
        private readonly string $defaultQueue = 'commands'
    ) {}

    public function dispatch(CommandInterface $command): string
    {
        $id = Str::uuid()->toString();

        // Set initial status
        $this->statusRepository->setStatus($id, AsyncStatus::PENDING, [
            'command_type' => $command->getCommandName(),
            'command_id' => $command->getCommandId(),
            'queued_at' => now()->toISOString(),
        ]);

        // Create job
        $job = new AsyncCommandJob($id, $command);

        // Configure job properties
        $queue = $this->getQueueForCommand($command);
        $job->onQueue($queue);

        if ($command->getTimeout() > 0) {
            $job->timeout($command->getTimeout());
        }

        if ($command->shouldRetry()) {
            $job->tries($command->getMaxRetries());
        }

        // Dispatch to queue
        $jobId = Queue::push($job);

        Log::info('Command dispatched to Laravel queue', [
            'async_id' => $id,
            'job_id' => $jobId,
            'command_type' => $command->getCommandName(),
            'queue' => $queue,
        ]);

        return $id;
    }

    public function getStatus(string $id): AsyncStatus
    {
        return $this->statusRepository->getStatus($id);
    }

    public function getResult(string $id): mixed
    {
        $status = $this->getStatus($id);

        if ($status !== AsyncStatus::COMPLETED) {
            return null;
        }

        return $this->statusRepository->getResult($id);
    }

    public function cancel(string $id): bool
    {
        $status = $this->getStatus($id);

        if ($status->isCompleted()) {
            return false; // Already completed
        }

        // Laravel doesn't provide easy job cancellation
        // We can only mark it as cancelled in our tracking
        $this->statusRepository->setStatus($id, AsyncStatus::CANCELLED, [
            'cancelled_at' => now()->toISOString(),
        ]);

        Log::info('Async command marked as cancelled', [
            'async_id' => $id,
        ]);

        return true;
    }

    public function supports(CommandInterface $command): bool
    {
        // Laravel queue strategy supports all commands
        return true;
    }

    public function getName(): string
    {
        return 'laravel_queue';
    }

    private function getQueueForCommand(CommandInterface $command): string
    {
        $priority = $command->getPriority();

        return match (true) {
            $priority > 0 => $this->defaultQueue . '_high',
            $priority < 0 => $this->defaultQueue . '_low',
            default => $this->defaultQueue,
        };
    }
}