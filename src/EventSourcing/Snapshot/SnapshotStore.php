<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Snapshot;

use LaravelModularDDD\EventSourcing\Contracts\SnapshotStoreInterface;
use LaravelModularDDD\EventSourcing\Contracts\AggregateSnapshotInterface;
use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\AggregateRootInterface;
use LaravelModularDDD\Core\Shared\AggregateId;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use DateTimeImmutable;

class SnapshotStore implements SnapshotStoreInterface
{
    private const TABLE_NAME = 'snapshots';
    private const CACHE_PREFIX = 'snapshot:';
    private const CACHE_TTL = 3600; // 1 hour

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly SnapshotStrategyInterface $strategy,
        private readonly ?SnapshotCompression $compression = null,
        private readonly bool $cacheEnabled = true
    ) {
        $this->compression = $compression ?? new SnapshotCompression();
    }

    public function save(
        AggregateIdInterface $aggregateId,
        AggregateRootInterface $aggregate,
        int $version
    ): void {
        $snapshot = AggregateSnapshot::fromAggregate($aggregate);

        $data = [
            'aggregate_id' => $aggregateId->toString(),
            'aggregate_type' => get_class($aggregate),
            'version' => $version,
            'state' => $this->compression !== null
                ? $this->compression->compress(json_encode($snapshot->getState()))
                : json_encode($snapshot->getState()),
            'hash' => $snapshot->getHash(),
            'created_at' => $snapshot->getCreatedAt()->format('Y-m-d H:i:s.u'),
        ];

        // Use upsert to handle version conflicts
        $this->connection->table(self::TABLE_NAME)->updateOrInsert(
            [
                'aggregate_id' => $aggregateId->toString(),
                'version' => $version,
            ],
            $data
        );

        // Update cache
        if ($this->cacheEnabled) {
            $this->cacheSnapshot($snapshot);
        }

        // Clean up old snapshots
        $this->pruneSnapshots($aggregateId);
    }

    public function load(AggregateIdInterface $aggregateId): ?AggregateSnapshotInterface
    {
        // Try cache first
        if ($this->cacheEnabled) {
            $cached = $this->loadFromCache($aggregateId);
            if ($cached !== null) {
                return $cached;
            }
        }

        // Load from database
        $record = $this->connection
            ->table(self::TABLE_NAME)
            ->where('aggregate_id', $aggregateId->toString())
            ->orderBy('version', 'desc')
            ->first();

        if (!$record) {
            return null;
        }

        $snapshot = $this->recordToSnapshot($record);

        // Cache for future use
        if ($this->cacheEnabled && $snapshot !== null) {
            $this->cacheSnapshot($snapshot);
        }

        return $snapshot;
    }

    public function loadVersion(
        AggregateIdInterface $aggregateId,
        int $version
    ): ?AggregateSnapshotInterface {
        $cacheKey = $this->getCacheKey($aggregateId, $version);

        // Try cache first
        if ($this->cacheEnabled) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $this->arrayToSnapshot($cached);
            }
        }

        // Load from database
        $record = $this->connection
            ->table(self::TABLE_NAME)
            ->where('aggregate_id', $aggregateId->toString())
            ->where('version', $version)
            ->first();

        if (!$record) {
            return null;
        }

        $snapshot = $this->recordToSnapshot($record);

        // Cache for future use
        if ($this->cacheEnabled && $snapshot !== null) {
            Cache::put($cacheKey, $snapshot->toArray(), now()->addSeconds(self::CACHE_TTL));
        }

        return $snapshot;
    }

    public function exists(AggregateIdInterface $aggregateId): bool
    {
        // Check cache first
        if ($this->cacheEnabled) {
            $cacheKey = $this->getCacheKey($aggregateId);
            if (Cache::has($cacheKey)) {
                return true;
            }
        }

        return $this->connection
            ->table(self::TABLE_NAME)
            ->where('aggregate_id', $aggregateId->toString())
            ->exists();
    }

    public function pruneSnapshots(AggregateIdInterface $aggregateId, int $keepCount = 3): void
    {
        $snapshots = $this->connection
            ->table(self::TABLE_NAME)
            ->where('aggregate_id', $aggregateId->toString())
            ->orderBy('version', 'desc')
            ->get(['version']);

        if ($snapshots->count() <= $keepCount) {
            return;
        }

        $versionsToDelete = $snapshots
            ->skip($keepCount)
            ->pluck('version')
            ->toArray();

        if (!empty($versionsToDelete)) {
            $this->connection
                ->table(self::TABLE_NAME)
                ->where('aggregate_id', $aggregateId->toString())
                ->whereIn('version', $versionsToDelete)
                ->delete();

            // Remove from cache
            if ($this->cacheEnabled) {
                foreach ($versionsToDelete as $version) {
                    $cacheKey = $this->getCacheKey($aggregateId, $version);
                    Cache::forget($cacheKey);
                }
            }
        }
    }

    public function removeAll(AggregateIdInterface $aggregateId): void
    {
        $this->connection
            ->table(self::TABLE_NAME)
            ->where('aggregate_id', $aggregateId->toString())
            ->delete();

        // Remove from cache
        if ($this->cacheEnabled) {
            $pattern = $this->getCacheKey($aggregateId) . '*';
            // Note: This is a simplified cache removal
            // In production, you might want to use cache tags
            Cache::forget($this->getCacheKey($aggregateId));
        }
    }

    /**
     * Get snapshots statistics
     */
    public function getStatistics(): array
    {
        $stats = $this->connection
            ->table(self::TABLE_NAME)
            ->selectRaw('
                COUNT(*) as total_snapshots,
                COUNT(DISTINCT aggregate_id) as unique_aggregates,
                AVG(LENGTH(state)) as avg_snapshot_size,
                MIN(created_at) as oldest_snapshot,
                MAX(created_at) as newest_snapshot
            ')
            ->first();

        return [
            'total_snapshots' => $stats->total_snapshots ?? 0,
            'unique_aggregates' => $stats->unique_aggregates ?? 0,
            'avg_snapshot_size_bytes' => $stats->avg_snapshot_size ?? 0,
            'oldest_snapshot' => $stats->oldest_snapshot,
            'newest_snapshot' => $stats->newest_snapshot,
            'compression_enabled' => $this->compression !== null,
            'cache_enabled' => $this->cacheEnabled,
            'strategy' => $this->strategy->getName(),
        ];
    }

    /**
     * Bulk load snapshots for multiple aggregates
     */
    public function loadBatch(array $aggregateIds): array
    {
        $stringIds = array_map(fn($id) => $id->toString(), $aggregateIds);

        $records = $this->connection
            ->table(self::TABLE_NAME)
            ->whereIn('aggregate_id', $stringIds)
            ->get()
            ->groupBy('aggregate_id');

        $snapshots = [];
        foreach ($aggregateIds as $aggregateId) {
            $stringId = $aggregateId->toString();
            if (isset($records[$stringId])) {
                // Get the latest version for this aggregate
                $latestRecord = $records[$stringId]
                    ->sortByDesc('version')
                    ->first();

                $snapshot = $this->recordToSnapshot($latestRecord);
                if ($snapshot !== null) {
                    $snapshots[$stringId] = $snapshot;
                }
            }
        }

        return $snapshots;
    }

    /**
     * Archive old snapshots to reduce database size
     */
    public function archiveOldSnapshots(int $olderThanDays = 30): int
    {
        $cutoffDate = now()->subDays($olderThanDays);

        $archivedCount = $this->connection
            ->table(self::TABLE_NAME)
            ->where('created_at', '<', $cutoffDate)
            ->delete();

        return $archivedCount;
    }

    /**
     * Verify integrity of all snapshots
     */
    public function verifyIntegrity(): array
    {
        $corruptSnapshots = [];

        $this->connection
            ->table(self::TABLE_NAME)
            ->orderBy('aggregate_id')
            ->chunk(100, function ($records) use (&$corruptSnapshots) {
                foreach ($records as $record) {
                    $snapshot = $this->recordToSnapshot($record);
                    if ($snapshot === null || !$snapshot->verifyIntegrity()) {
                        $corruptSnapshots[] = [
                            'aggregate_id' => $record->aggregate_id,
                            'version' => $record->version,
                            'created_at' => $record->created_at,
                        ];
                    }
                }
            });

        return $corruptSnapshots;
    }

    private function recordToSnapshot($record): ?AggregateSnapshotInterface
    {
        try {
            $aggregateId = new class($record->aggregate_id) extends AggregateId {};

            if ($this->compression !== null) {
                $decompressedState = $this->compression->decompress($record->state);
                $state = json_decode($decompressedState, true, 512, JSON_THROW_ON_ERROR);

                return new AggregateSnapshot(
                    $aggregateId,
                    $record->aggregate_type,
                    $record->version,
                    $state,
                    new DateTimeImmutable($record->created_at),
                    $record->hash
                );
            }

            $state = json_decode($record->state, true, 512, JSON_THROW_ON_ERROR);

            return new AggregateSnapshot(
                $aggregateId,
                $record->aggregate_type,
                $record->version,
                $state,
                new DateTimeImmutable($record->created_at),
                $record->hash
            );
        } catch (\Exception $e) {
            error_log("Failed to deserialize snapshot: " . $e->getMessage());
            return null;
        }
    }

    private function arrayToSnapshot(array $data): AggregateSnapshotInterface
    {
        $aggregateId = new class($data['aggregate_id']) extends AggregateId {};

        return new AggregateSnapshot(
            $aggregateId,
            $data['aggregate_type'],
            $data['version'],
            $data['state'],
            new DateTimeImmutable($data['created_at']),
            $data['hash']
        );
    }

    private function loadFromCache(AggregateIdInterface $aggregateId): ?AggregateSnapshotInterface
    {
        $cacheKey = $this->getCacheKey($aggregateId);
        $cached = Cache::get($cacheKey);

        return $cached ? $this->arrayToSnapshot($cached) : null;
    }

    private function cacheSnapshot(AggregateSnapshotInterface $snapshot): void
    {
        $cacheKey = $this->getCacheKey($snapshot->getAggregateId());
        $versionCacheKey = $this->getCacheKey($snapshot->getAggregateId(), $snapshot->getVersion());

        Cache::put($cacheKey, $snapshot->toArray(), now()->addSeconds(self::CACHE_TTL));
        Cache::put($versionCacheKey, $snapshot->toArray(), now()->addSeconds(self::CACHE_TTL));
    }

    private function getCacheKey(AggregateIdInterface $aggregateId, ?int $version = null): string
    {
        $key = self::CACHE_PREFIX . $aggregateId->toString();
        return $version !== null ? $key . ':' . $version : $key . ':latest';
    }
}