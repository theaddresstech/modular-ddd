<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Jobs;

use LaravelModularDDD\EventSourcing\Projections\ProjectionManager;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateProjectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $maxExceptions = 3;

    public function __construct(
        private readonly ?DomainEventInterface $event = null,
        private readonly ?string $projectionName = null,
        private readonly bool $isRebuild = false
    ) {}

    public function handle(ProjectionManager $projectionManager): void
    {
        if ($this->isRebuild && $this->projectionName) {
            $this->handleRebuild($projectionManager);
        } elseif ($this->event) {
            $this->handleEventUpdate($projectionManager);
        } else {
            Log::warning('UpdateProjectionJob called without event or projection name');
        }
    }

    private function handleEventUpdate(ProjectionManager $projectionManager): void
    {
        Log::debug('Processing projection update for event', [
            'event_type' => $this->event->getEventType(),
            'aggregate_id' => $this->event->getAggregateId(),
            'attempt' => $this->attempts(),
        ]);

        try {
            $projectionManager->processEvent($this->event);

            Log::debug('Projection update completed', [
                'event_type' => $this->event->getEventType(),
                'aggregate_id' => $this->event->getAggregateId(),
            ]);

        } catch (\Throwable $e) {
            Log::error('Projection update failed', [
                'event_type' => $this->event->getEventType(),
                'aggregate_id' => $this->event->getAggregateId(),
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            throw $e;
        }
    }

    private function handleRebuild(ProjectionManager $projectionManager): void
    {
        Log::info('Processing projection rebuild', [
            'projection' => $this->projectionName,
            'attempt' => $this->attempts(),
        ]);

        try {
            $projectionManager->rebuildProjection($this->projectionName);

            Log::info('Projection rebuild completed', [
                'projection' => $this->projectionName,
            ]);

        } catch (\Throwable $e) {
            Log::error('Projection rebuild failed', [
                'projection' => $this->projectionName,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->isRebuild && $this->projectionName) {
            Log::critical('Projection rebuild failed after all retries', [
                'projection' => $this->projectionName,
                'error' => $exception->getMessage(),
                'attempts' => $this->attempts(),
            ]);
        } elseif ($this->event) {
            Log::critical('Projection update failed after all retries', [
                'event_type' => $this->event->getEventType(),
                'aggregate_id' => $this->event->getAggregateId(),
                'error' => $exception->getMessage(),
                'attempts' => $this->attempts(),
            ]);
        }
    }

    public function backoff(): array
    {
        // Progressive backoff: 5s, 15s, 45s
        return [5, 15, 45];
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(2);
    }

    public function tags(): array
    {
        $tags = ['projection_update'];

        if ($this->isRebuild && $this->projectionName) {
            $tags[] = 'projection_rebuild';
            $tags[] = 'projection:' . $this->projectionName;
        } elseif ($this->event) {
            $tags[] = 'event_projection';
            $tags[] = 'event:' . $this->event->getEventType();
        }

        return $tags;
    }
}