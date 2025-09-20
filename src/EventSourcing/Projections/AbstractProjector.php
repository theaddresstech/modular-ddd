<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Projections;

use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

abstract class AbstractProjector implements ProjectorInterface
{
    private const POSITION_CACHE_TTL = 3600; // 1 hour
    private const POSITION_TABLE = 'projections';

    protected bool $enabled = true;

    public function __construct(
        protected readonly string $name
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function canHandle(DomainEventInterface $event): bool
    {
        $eventClass = get_class($event);
        return in_array($eventClass, $this->getHandledEvents(), true);
    }

    public function handle(DomainEventInterface $event): void
    {
        if (!$this->isEnabled() || !$this->canHandle($event)) {
            return;
        }

        try {
            $this->project($event);
            $this->updateMetrics($event);
        } catch (\Exception $e) {
            $this->handleProjectionError($event, $e);
            throw $e;
        }
    }

    public function getPosition(): int
    {
        $cacheKey = $this->getPositionCacheKey();

        return Cache::remember($cacheKey, self::POSITION_CACHE_TTL, function () {
            $record = DB::table(self::POSITION_TABLE)
                ->where('projection_name', $this->name)
                ->first();

            return $record ? $record->last_processed_sequence : 0;
        });
    }

    public function setPosition(int $position): void
    {
        DB::table(self::POSITION_TABLE)->updateOrInsert(
            ['projection_name' => $this->name],
            [
                'last_processed_sequence' => $position,
                'updated_at' => now(),
            ]
        );

        // Update cache
        Cache::put($this->getPositionCacheKey(), $position, now()->addSeconds(self::POSITION_CACHE_TTL));
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function reset(): void
    {
        DB::transaction(function () {
            $this->resetProjection();

            // Reset position
            DB::table(self::POSITION_TABLE)
                ->where('projection_name', $this->name)
                ->delete();

            // Clear cache
            Cache::forget($this->getPositionCacheKey());
        });
    }

    /**
     * Get projection statistics
     */
    public function getStatistics(): array
    {
        $position = $this->getPosition();
        $record = DB::table(self::POSITION_TABLE)
            ->where('projection_name', $this->name)
            ->first();

        return [
            'name' => $this->name,
            'position' => $position,
            'enabled' => $this->enabled,
            'last_updated' => $record?->updated_at,
            'handled_events' => $this->getHandledEvents(),
            'performance_metrics' => $this->getPerformanceMetrics(),
        ];
    }

    /**
     * Lock projection for exclusive processing
     */
    public function lock(int $timeoutSeconds = 300): bool
    {
        $lockUntil = now()->addSeconds($timeoutSeconds);

        $updated = DB::table(self::POSITION_TABLE)
            ->where('projection_name', $this->name)
            ->where(function ($query) {
                $query->where('locked', false)
                      ->orWhere('locked_until', '<', now());
            })
            ->update([
                'locked' => true,
                'locked_until' => $lockUntil,
            ]);

        return $updated > 0;
    }

    /**
     * Unlock projection
     */
    public function unlock(): void
    {
        DB::table(self::POSITION_TABLE)
            ->where('projection_name', $this->name)
            ->update([
                'locked' => false,
                'locked_until' => null,
            ]);
    }

    /**
     * Check if projection is locked
     */
    public function isLocked(): bool
    {
        $record = DB::table(self::POSITION_TABLE)
            ->where('projection_name', $this->name)
            ->first();

        if (!$record) {
            return false;
        }

        return $record->locked && $record->locked_until > now();
    }

    /**
     * Abstract method for actual projection logic
     */
    abstract protected function project(DomainEventInterface $event): void;

    /**
     * Abstract method for resetting the specific projection
     */
    abstract protected function resetProjection(): void;

    /**
     * Get performance metrics for this projector
     */
    protected function getPerformanceMetrics(): array
    {
        $cacheKey = "projector_metrics:{$this->name}";

        return Cache::get($cacheKey, [
            'events_processed' => 0,
            'avg_processing_time_ms' => 0,
            'last_processing_time_ms' => 0,
            'errors_count' => 0,
            'last_error' => null,
        ]);
    }

    /**
     * Update performance metrics
     */
    protected function updateMetrics(DomainEventInterface $event): void
    {
        $cacheKey = "projector_metrics:{$this->name}";
        $metrics = $this->getPerformanceMetrics();

        $metrics['events_processed']++;
        $metrics['last_processed_at'] = now()->toISOString();

        Cache::put($cacheKey, $metrics, now()->addHours(24));
    }

    /**
     * Handle projection errors
     */
    protected function handleProjectionError(DomainEventInterface $event, \Exception $e): void
    {
        $cacheKey = "projector_metrics:{$this->name}";
        $metrics = $this->getPerformanceMetrics();

        $metrics['errors_count']++;
        $metrics['last_error'] = [
            'message' => $e->getMessage(),
            'event_type' => get_class($event),
            'event_id' => $event->getEventId(),
            'occurred_at' => now()->toISOString(),
        ];

        Cache::put($cacheKey, $metrics, now()->addHours(24));

        // Log the error
        logger()->error('Projection error', [
            'projector' => $this->name,
            'event_type' => get_class($event),
            'event_id' => $event->getEventId(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    private function getPositionCacheKey(): string
    {
        return "projector_position:{$this->name}";
    }
}