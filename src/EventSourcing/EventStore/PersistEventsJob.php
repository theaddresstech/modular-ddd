<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\EventStore;

use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PersistEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $maxExceptions = 2;
    public int $timeout = 60;

    /**
     * @param AggregateIdInterface $aggregateId
     * @param DomainEventInterface[] $events
     * @param int|null $expectedVersion
     */
    public function __construct(
        private readonly AggregateIdInterface $aggregateId,
        private readonly array $events,
        private readonly ?int $expectedVersion = null
    ) {
        $this->onQueue('events');
    }

    public function handle(MySQLEventStore $eventStore): void
    {
        try {
            $eventStore->append($this->aggregateId, $this->events, $this->expectedVersion);
        } catch (\Exception $e) {
            // Log the error
            logger()->error('Failed to persist events to warm storage', [
                'aggregate_id' => $this->aggregateId->toString(),
                'event_count' => count($this->events),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger retry mechanism
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        // Handle the failure - maybe send to dead letter queue or alert
        logger()->critical('Failed to persist events after all retries', [
            'aggregate_id' => $this->aggregateId->toString(),
            'event_count' => count($this->events),
            'error' => $exception->getMessage(),
        ]);

        // In a production system, you might want to:
        // 1. Store failed events in a dead letter table
        // 2. Send alerts to monitoring systems
        // 3. Attempt manual recovery
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [5, 15, 30]; // Wait 5, 15, then 30 seconds between retries
    }

    /**
     * Determine if the job should be retried based on the exception.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(10); // Stop retrying after 10 minutes
    }
}