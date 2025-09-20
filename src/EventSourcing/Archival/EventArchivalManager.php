<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Archival;

use LaravelModularDDD\EventSourcing\Contracts\EventStoreInterface;
use LaravelModularDDD\EventSourcing\EventStore\MySQLEventStore;
use LaravelModularDDD\EventSourcing\EventStore\RedisEventStore;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class EventArchivalManager
{
    private const ARCHIVE_BATCH_SIZE = 1000;
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly MySQLEventStore $warmStore,
        private readonly RedisEventStore $hotStore,
        private readonly ConnectionInterface $connection,
        private readonly string $archiveStorage = 'local'
    ) {}

    /**
     * Archive old events from hot storage to warm storage
     */
    public function archiveHotEvents(int $olderThanHours = 24): array
    {
        $stats = [
            'processed_aggregates' => 0,
            'archived_events' => 0,
            'freed_memory' => 0,
            'errors' => 0,
        ];

        $cutoffTime = now()->subHours($olderThanHours);

        // Get aggregates that haven't been accessed recently
        $inactiveAggregates = $this->findInactiveAggregates($cutoffTime);

        foreach ($inactiveAggregates as $aggregateId) {
            try {
                $result = $this->archiveAggregateFromHot($aggregateId);
                $stats['processed_aggregates']++;
                $stats['archived_events'] += $result['events_archived'];
                $stats['freed_memory'] += $result['memory_freed'];
            } catch (\Exception $e) {
                $stats['errors']++;
                logger()->error('Failed to archive aggregate from hot storage', [
                    'aggregate_id' => $aggregateId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Archive old events from warm storage to cold storage
     */
    public function archiveWarmEvents(int $olderThanDays = 90): array
    {
        $stats = [
            'archived_partitions' => 0,
            'archived_events' => 0,
            'freed_space_mb' => 0,
            'errors' => 0,
        ];

        $cutoffDate = now()->subDays($olderThanDays);

        // Find old partitions that can be archived
        $oldPartitions = $this->findOldPartitions($cutoffDate);

        foreach ($oldPartitions as $partition) {
            try {
                $result = $this->archivePartitionToCold($partition);
                $stats['archived_partitions']++;
                $stats['archived_events'] += $result['events_count'];
                $stats['freed_space_mb'] += $result['space_freed_mb'];
            } catch (\Exception $e) {
                $stats['errors']++;
                logger()->error('Failed to archive partition to cold storage', [
                    'partition' => $partition,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Restore events from cold storage
     */
    public function restoreFromCold(string $aggregateId, ?int $fromVersion = null, ?int $toVersion = null): array
    {
        $archivePath = $this->getArchivePath($aggregateId);

        if (!Storage::disk($this->archiveStorage)->exists($archivePath)) {
            return [];
        }

        try {
            $archiveData = Storage::disk($this->archiveStorage)->get($archivePath);
            $events = $this->deserializeArchiveData($archiveData);

            // Filter by version if specified
            if ($fromVersion !== null || $toVersion !== null) {
                $events = $this->filterEventsByVersion($events, $fromVersion, $toVersion);
            }

            return $events;
        } catch (\Exception $e) {
            logger()->error('Failed to restore events from cold storage', [
                'aggregate_id' => $aggregateId,
                'error' => $e->getMessage(),
            ]);

            throw new ArchivalException("Failed to restore events for aggregate {$aggregateId}", 0, $e);
        }
    }

    /**
     * Migrate frequently accessed aggregates from cold to warm storage
     */
    public function migrateFrequentlyAccessedEvents(): array
    {
        $stats = [
            'migrated_aggregates' => 0,
            'migrated_events' => 0,
            'errors' => 0,
        ];

        // Get list of frequently accessed aggregates from access logs
        $frequentAggregates = $this->getFrequentlyAccessedAggregates();

        foreach ($frequentAggregates as $aggregateId) {
            try {
                $result = $this->migrateColdToWarm($aggregateId);
                $stats['migrated_aggregates']++;
                $stats['migrated_events'] += $result['events_count'];
            } catch (\Exception $e) {
                $stats['errors']++;
                logger()->error('Failed to migrate aggregate from cold to warm', [
                    'aggregate_id' => $aggregateId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Get archival statistics
     */
    public function getArchivalStatistics(): array
    {
        return [
            'hot_storage' => $this->getHotStorageStats(),
            'warm_storage' => $this->getWarmStorageStats(),
            'cold_storage' => $this->getColdStorageStats(),
            'archival_jobs' => $this->getArchivalJobStats(),
        ];
    }

    /**
     * Clean up corrupted or incomplete archives
     */
    public function cleanupCorruptedArchives(): array
    {
        $stats = [
            'scanned_files' => 0,
            'corrupted_files' => 0,
            'cleaned_files' => 0,
            'freed_space_mb' => 0,
        ];

        $archiveFiles = Storage::disk($this->archiveStorage)->allFiles('archives/');

        foreach ($archiveFiles as $file) {
            $stats['scanned_files']++;

            try {
                $content = Storage::disk($this->archiveStorage)->get($file);
                $this->validateArchiveFile($content);
            } catch (\Exception $e) {
                $stats['corrupted_files']++;

                // Get file size before deletion
                $size = Storage::disk($this->archiveStorage)->size($file);
                $stats['freed_space_mb'] += $size / (1024 * 1024);

                // Delete corrupted file
                Storage::disk($this->archiveStorage)->delete($file);
                $stats['cleaned_files']++;

                logger()->warning('Cleaned up corrupted archive file', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    private function findInactiveAggregates(\DateTime $cutoffTime): array
    {
        // This would typically query access logs or cache metadata
        // For now, return a placeholder implementation
        return Cache::get('inactive_aggregates', []);
    }

    private function archiveAggregateFromHot(string $aggregateId): array
    {
        // Load events from Redis
        $events = $this->hotStore->load($aggregateId);

        if ($events->isEmpty()) {
            return ['events_archived' => 0, 'memory_freed' => 0];
        }

        // Ensure events are also in warm storage
        $this->warmStore->append($aggregateId, $events->getEvents());

        // Remove from hot storage
        $memoryFreed = $this->estimateMemoryUsage($events);
        $this->hotStore->evict($aggregateId);

        return [
            'events_archived' => $events->count(),
            'memory_freed' => $memoryFreed,
        ];
    }

    private function findOldPartitions(\DateTime $cutoffDate): array
    {
        // Find database partitions older than cutoff date
        $partitions = $this->connection->select("
            SELECT PARTITION_NAME
            FROM INFORMATION_SCHEMA.PARTITIONS
            WHERE TABLE_NAME = 'event_store'
            AND PARTITION_DESCRIPTION < ?
        ", [$cutoffDate->format('Y')]);

        return array_column($partitions, 'PARTITION_NAME');
    }

    private function archivePartitionToCold(string $partition): array
    {
        // Export partition data
        $events = $this->exportPartitionData($partition);

        if (empty($events)) {
            return ['events_count' => 0, 'space_freed_mb' => 0];
        }

        // Compress and store in cold storage
        $archiveData = $this->compressEvents($events);
        $archivePath = "archives/partitions/{$partition}.gz";

        Storage::disk($this->archiveStorage)->put($archivePath, $archiveData);

        // Calculate space freed
        $spaceFreed = $this->calculatePartitionSize($partition);

        // Drop the partition
        $this->connection->statement("ALTER TABLE event_store DROP PARTITION {$partition}");

        return [
            'events_count' => count($events),
            'space_freed_mb' => $spaceFreed / (1024 * 1024),
        ];
    }

    private function exportPartitionData(string $partition): array
    {
        return $this->connection->select("
            SELECT *
            FROM event_store PARTITION ({$partition})
            ORDER BY sequence_number
        ");
    }

    private function calculatePartitionSize(string $partition): int
    {
        $result = $this->connection->select("
            SELECT
                ROUND(((DATA_LENGTH + INDEX_LENGTH)), 2) AS size_bytes
            FROM INFORMATION_SCHEMA.PARTITIONS
            WHERE TABLE_NAME = 'event_store'
            AND PARTITION_NAME = ?
        ", [$partition]);

        return $result[0]->size_bytes ?? 0;
    }

    private function compressEvents(array $events): string
    {
        $serialized = json_encode($events, JSON_THROW_ON_ERROR);

        if (function_exists('gzcompress')) {
            return gzcompress($serialized, 9); // Maximum compression
        }

        return $serialized;
    }

    private function deserializeArchiveData(string $archiveData): array
    {
        // Try to decompress first
        if (function_exists('gzuncompress')) {
            $decompressed = @gzuncompress($archiveData);
            if ($decompressed !== false) {
                $archiveData = $decompressed;
            }
        }

        return json_decode($archiveData, true, 512, JSON_THROW_ON_ERROR);
    }

    private function filterEventsByVersion(array $events, ?int $fromVersion, ?int $toVersion): array
    {
        return array_filter($events, function ($event) use ($fromVersion, $toVersion) {
            $version = $event['version'] ?? 0;

            if ($fromVersion !== null && $version < $fromVersion) {
                return false;
            }

            if ($toVersion !== null && $version > $toVersion) {
                return false;
            }

            return true;
        });
    }

    private function getFrequentlyAccessedAggregates(): array
    {
        // This would analyze access patterns from logs or metrics
        // For now, return cached list
        return Cache::get('frequently_accessed_aggregates', []);
    }

    private function migrateColdToWarm(string $aggregateId): array
    {
        $events = $this->restoreFromCold($aggregateId);

        if (empty($events)) {
            return ['events_count' => 0];
        }

        // Insert events back into warm storage
        foreach ($events as $event) {
            $this->connection->table('event_store')->insert($event);
        }

        return ['events_count' => count($events)];
    }

    private function estimateMemoryUsage($events): int
    {
        return strlen(serialize($events->getEvents()));
    }

    private function getArchivePath(string $aggregateId): string
    {
        return "archives/aggregates/" . substr($aggregateId, 0, 2) . "/{$aggregateId}.gz";
    }

    private function validateArchiveFile(string $content): void
    {
        // Validate archive file integrity
        $data = $this->deserializeArchiveData($content);

        if (!is_array($data)) {
            throw new \Exception('Invalid archive format');
        }

        // Basic validation - check for required fields
        foreach ($data as $event) {
            if (!isset($event['aggregate_id'], $event['event_type'], $event['occurred_at'])) {
                throw new \Exception('Invalid event format in archive');
            }
        }
    }

    private function getHotStorageStats(): array
    {
        return $this->hotStore->getCacheStats();
    }

    private function getWarmStorageStats(): array
    {
        $result = $this->connection->select("
            SELECT
                COUNT(*) as event_count,
                COUNT(DISTINCT aggregate_id) as aggregate_count,
                AVG(LENGTH(event_data)) as avg_event_size,
                MIN(occurred_at) as oldest_event,
                MAX(occurred_at) as newest_event
            FROM event_store
        ");

        return [
            'event_count' => $result[0]->event_count ?? 0,
            'aggregate_count' => $result[0]->aggregate_count ?? 0,
            'avg_event_size_bytes' => $result[0]->avg_event_size ?? 0,
            'oldest_event' => $result[0]->oldest_event,
            'newest_event' => $result[0]->newest_event,
        ];
    }

    private function getColdStorageStats(): array
    {
        $files = Storage::disk($this->archiveStorage)->allFiles('archives/');
        $totalSize = 0;

        foreach ($files as $file) {
            $totalSize += Storage::disk($this->archiveStorage)->size($file);
        }

        return [
            'archive_files' => count($files),
            'total_size_mb' => $totalSize / (1024 * 1024),
            'storage_driver' => $this->archiveStorage,
        ];
    }

    private function getArchivalJobStats(): array
    {
        return Cache::get('archival_job_stats', [
            'last_hot_archival' => null,
            'last_warm_archival' => null,
            'total_jobs_run' => 0,
            'total_events_archived' => 0,
        ]);
    }
}