<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Projections\Strategies;

use LaravelModularDDD\EventSourcing\Projections\ProjectionStrategyInterface;
use LaravelModularDDD\EventSourcing\Jobs\UpdateProjectionJob;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Facades\Log;

class AsyncProjectionStrategy implements ProjectionStrategyInterface
{
    public function __construct(
        private readonly Queue $queue,
        private readonly array $eventPatterns = ['*'],
        private readonly string $queueName = 'projections',
        private readonly int $delay = 0
    ) {}

    public function handle(DomainEventInterface $event): void
    {
        if (!$this->shouldHandle($event)) {
            return;
        }

        try {
            // Queue projection update job
            $job = new UpdateProjectionJob($event);
            $job->onQueue($this->queueName);

            if ($this->delay > 0) {
                $job->delay($this->delay);
            }

            $this->queue->push($job);

            Log::debug('Projection update queued', [
                'event_type' => $event->getEventType(),
                'aggregate_id' => $event->getAggregateId(),
                'queue' => $this->queueName,
                'delay' => $this->delay,
                'strategy' => $this->getName(),
            ]);

        } catch (\Throwable $e) {
            Log::error('Failed to queue projection update', [
                'event_type' => $event->getEventType(),
                'aggregate_id' => $event->getAggregateId(),
                'error' => $e->getMessage(),
                'strategy' => $this->getName(),
            ]);

            throw $e;
        }
    }

    public function rebuild(string $projectionName): void
    {
        try {
            $job = new UpdateProjectionJob(null, $projectionName, true); // Rebuild flag
            $job->onQueue($this->queueName);

            $this->queue->push($job);

            Log::info('Projection rebuild queued', [
                'projection' => $projectionName,
                'queue' => $this->queueName,
                'strategy' => $this->getName(),
            ]);

        } catch (\Throwable $e) {
            Log::error('Failed to queue projection rebuild', [
                'projection' => $projectionName,
                'error' => $e->getMessage(),
                'strategy' => $this->getName(),
            ]);

            throw $e;
        }
    }

    public function getDelay(): int
    {
        return $this->delay;
    }

    public function shouldHandle(DomainEventInterface $event): bool
    {
        $eventType = $event->getEventType();

        foreach ($this->eventPatterns as $pattern) {
            if ($pattern === '*' || fnmatch($pattern, $eventType)) {
                return true;
            }
        }

        return false;
    }

    public function getName(): string
    {
        return 'async';
    }

    public function getPriority(): int
    {
        return 50; // Medium priority for async updates
    }
}