<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Projections\Strategies;

use LaravelModularDDD\EventSourcing\Projections\ProjectionStrategyInterface;
use LaravelModularDDD\EventSourcing\Projections\ProjectionManager;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BatchedProjectionStrategy implements ProjectionStrategyInterface
{
    private const BATCH_KEY_PREFIX = 'projection_batch:';

    public function __construct(
        private readonly ProjectionManager $projectionManager,
        private readonly array $eventPatterns = ['*'],
        private readonly int $batchSize = 100,
        private readonly int $batchTimeout = 60 // seconds
    ) {}

    public function handle(DomainEventInterface $event): void
    {
        if (!$this->shouldHandle($event)) {
            return;
        }

        $batchKey = self::BATCH_KEY_PREFIX . 'events';

        try {
            // Add event to batch
            $batch = Cache::get($batchKey, []);
            $batch[] = [
                'event' => serialize($event),
                'timestamp' => time(),
            ];

            Cache::put($batchKey, $batch, $this->batchTimeout);

            Log::debug('Event added to projection batch', [
                'event_type' => $event->getEventType(),
                'batch_size' => count($batch),
                'max_batch_size' => $this->batchSize,
                'strategy' => $this->getName(),
            ]);

            // Process batch if it's full
            if (count($batch) >= $this->batchSize) {
                $this->processBatch($batchKey, $batch);
            }

        } catch (\Throwable $e) {
            Log::error('Failed to add event to projection batch', [
                'event_type' => $event->getEventType(),
                'error' => $e->getMessage(),
                'strategy' => $this->getName(),
            ]);

            throw $e;
        }
    }

    public function rebuild(string $projectionName): void
    {
        try {
            $this->projectionManager->rebuildProjection($projectionName);

            Log::info('Projection rebuilt via batched strategy', [
                'projection' => $projectionName,
                'strategy' => $this->getName(),
            ]);

        } catch (\Throwable $e) {
            Log::error('Projection rebuild failed', [
                'projection' => $projectionName,
                'error' => $e->getMessage(),
                'strategy' => $this->getName(),
            ]);

            throw $e;
        }
    }

    public function getDelay(): int
    {
        return 0; // Batching provides its own delay mechanism
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
        return 'batched';
    }

    public function getPriority(): int
    {
        return 25; // Lower priority for batched updates
    }

    /**
     * Process expired batches (called via scheduled command)
     */
    public function processExpiredBatches(): int
    {
        $processed = 0;
        $batchKey = self::BATCH_KEY_PREFIX . 'events';

        $batch = Cache::get($batchKey, []);

        if (!empty($batch)) {
            $oldestEventTime = min(array_column($batch, 'timestamp'));
            $isExpired = (time() - $oldestEventTime) >= $this->batchTimeout;

            if ($isExpired) {
                $this->processBatch($batchKey, $batch);
                $processed = count($batch);
            }
        }

        return $processed;
    }

    private function processBatch(string $batchKey, array $batch): void
    {
        if (empty($batch)) {
            return;
        }

        try {
            // Clear the batch first to prevent re-processing
            Cache::forget($batchKey);

            // Deserialize and process events
            foreach ($batch as $item) {
                $event = unserialize($item['event']);
                $this->projectionManager->processEvent($event);
            }

            Log::info('Projection batch processed', [
                'batch_size' => count($batch),
                'strategy' => $this->getName(),
            ]);

        } catch (\Throwable $e) {
            Log::error('Projection batch processing failed', [
                'batch_size' => count($batch),
                'error' => $e->getMessage(),
                'strategy' => $this->getName(),
            ]);

            // Re-queue the batch for retry
            Cache::put($batchKey, $batch, $this->batchTimeout);

            throw $e;
        }
    }
}