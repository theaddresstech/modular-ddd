<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Versioning;

use Illuminate\Support\Facades\Cache;

class EventVersioningManager
{
    /** @var EventUpcasterInterface[] */
    private array $upcasters = [];

    private const CACHE_TTL = 3600; // 1 hour

    public function __construct()
    {
        $this->loadUpcasters();
    }

    /**
     * Register an event upcaster
     */
    public function register(EventUpcasterInterface $upcaster): void
    {
        $key = $this->getUpcasterKey($upcaster);
        $this->upcasters[$key] = $upcaster;

        // Sort by priority
        $this->sortUpcastersByPriority();

        // Clear cache
        $this->clearUpcastersCache();
    }

    /**
     * Unregister an event upcaster
     */
    public function unregister(string $eventType, int $fromVersion, int $toVersion): void
    {
        $key = "{$eventType}:{$fromVersion}:{$toVersion}";
        unset($this->upcasters[$key]);

        $this->clearUpcastersCache();
    }

    /**
     * Upcast event data to the latest version
     */
    public function upcastEvent(array $eventData): array
    {
        $eventType = $eventData['event_type'] ?? '';
        $currentVersion = $eventData['event_version'] ?? 1;

        if (empty($eventType)) {
            return $eventData;
        }

        // Get upcast chain for this event type
        $upcastChain = $this->getUpcastChain($eventType, $currentVersion);

        if (empty($upcastChain)) {
            return $eventData;
        }

        // Apply upcasters in sequence
        $upcastedData = $eventData;
        foreach ($upcastChain as $upcaster) {
            if ($upcaster->canUpcast($upcastedData)) {
                $upcastedData = $upcaster->upcast($upcastedData);

                // Add migration metadata
                $upcastedData = $this->addMigrationMetadata(
                    $upcastedData,
                    $upcaster->getFromVersion(),
                    $upcaster->getToVersion()
                );
            }
        }

        return $upcastedData;
    }

    /**
     * Get the latest version for an event type
     */
    public function getLatestVersion(string $eventType): int
    {
        $cacheKey = "event_latest_version:{$eventType}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($eventType) {
            $latestVersion = 1;

            foreach ($this->upcasters as $upcaster) {
                if ($upcaster->getEventType() === $eventType) {
                    $latestVersion = max($latestVersion, $upcaster->getToVersion());
                }
            }

            return $latestVersion;
        });
    }

    /**
     * Check if an event needs upcasting
     */
    public function needsUpcasting(array $eventData): bool
    {
        $eventType = $eventData['event_type'] ?? '';
        $currentVersion = $eventData['event_version'] ?? 1;

        if (empty($eventType)) {
            return false;
        }

        $latestVersion = $this->getLatestVersion($eventType);

        return $currentVersion < $latestVersion;
    }

    /**
     * Get available upcast paths for an event type
     */
    public function getUpcastPaths(string $eventType): array
    {
        $paths = [];

        foreach ($this->upcasters as $upcaster) {
            if ($upcaster->getEventType() === $eventType) {
                $paths[] = [
                    'from_version' => $upcaster->getFromVersion(),
                    'to_version' => $upcaster->getToVersion(),
                    'upcaster_class' => get_class($upcaster),
                ];
            }
        }

        return $paths;
    }

    /**
     * Validate that all upcasters form valid chains
     */
    public function validateUpcastChains(): array
    {
        $issues = [];
        $eventTypes = $this->getEventTypes();

        foreach ($eventTypes as $eventType) {
            $chainIssues = $this->validateEventTypeChain($eventType);
            if (!empty($chainIssues)) {
                $issues[$eventType] = $chainIssues;
            }
        }

        return $issues;
    }

    /**
     * Get statistics about event versioning
     */
    public function getStatistics(): array
    {
        $eventTypes = $this->getEventTypes();
        $stats = [
            'total_event_types' => count($eventTypes),
            'total_upcasters' => count($this->upcasters),
            'event_types' => [],
        ];

        foreach ($eventTypes as $eventType) {
            $paths = $this->getUpcastPaths($eventType);
            $latestVersion = $this->getLatestVersion($eventType);

            $stats['event_types'][$eventType] = [
                'latest_version' => $latestVersion,
                'upcast_paths' => count($paths),
                'has_gaps' => $this->hasVersionGaps($eventType),
            ];
        }

        return $stats;
    }

    /**
     * Migrate all events of a specific type to latest version
     */
    public function migrateEventType(string $eventType, callable $eventProcessor): array
    {
        $stats = [
            'processed' => 0,
            'migrated' => 0,
            'errors' => 0,
            'skipped' => 0,
        ];

        // This would typically work with the event store
        // For now, it's a placeholder for the migration process
        $latestVersion = $this->getLatestVersion($eventType);

        // Process events in batches
        // Implementation would depend on event store capabilities

        return $stats;
    }

    private function getUpcastChain(string $eventType, int $fromVersion): array
    {
        $cacheKey = "upcast_chain:{$eventType}:{$fromVersion}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($eventType, $fromVersion) {
            return $this->buildUpcastChain($eventType, $fromVersion);
        });
    }

    private function buildUpcastChain(string $eventType, int $fromVersion): array
    {
        $chain = [];
        $currentVersion = $fromVersion;
        $targetVersion = $this->getLatestVersion($eventType);

        while ($currentVersion < $targetVersion) {
            $nextUpcaster = $this->findNextUpcaster($eventType, $currentVersion);

            if (!$nextUpcaster) {
                // No more upcasters available
                break;
            }

            $chain[] = $nextUpcaster;
            $currentVersion = $nextUpcaster->getToVersion();
        }

        return $chain;
    }

    private function findNextUpcaster(string $eventType, int $fromVersion): ?EventUpcasterInterface
    {
        foreach ($this->upcasters as $upcaster) {
            if ($upcaster->getEventType() === $eventType &&
                $upcaster->getFromVersion() === $fromVersion) {
                return $upcaster;
            }
        }

        return null;
    }

    private function getEventTypes(): array
    {
        $eventTypes = [];

        foreach ($this->upcasters as $upcaster) {
            $eventTypes[] = $upcaster->getEventType();
        }

        return array_unique($eventTypes);
    }

    private function validateEventTypeChain(string $eventType): array
    {
        $issues = [];
        $versions = [];

        // Collect all versions for this event type
        foreach ($this->upcasters as $upcaster) {
            if ($upcaster->getEventType() === $eventType) {
                $versions[] = $upcaster->getFromVersion();
                $versions[] = $upcaster->getToVersion();
            }
        }

        $versions = array_unique($versions);
        sort($versions);

        // Check for gaps in version sequence
        for ($i = 1; $i < count($versions); $i++) {
            if ($versions[$i] - $versions[$i - 1] > 1) {
                $issues[] = "Gap between version {$versions[$i - 1]} and {$versions[$i]}";
            }
        }

        // Check for duplicate upcasters
        $upcasterPairs = [];
        foreach ($this->upcasters as $upcaster) {
            if ($upcaster->getEventType() === $eventType) {
                $pair = $upcaster->getFromVersion() . '->' . $upcaster->getToVersion();
                if (in_array($pair, $upcasterPairs)) {
                    $issues[] = "Duplicate upcaster for {$pair}";
                }
                $upcasterPairs[] = $pair;
            }
        }

        return $issues;
    }

    private function hasVersionGaps(string $eventType): bool
    {
        $issues = $this->validateEventTypeChain($eventType);

        return !empty(array_filter($issues, fn($issue) => strpos($issue, 'Gap') === 0));
    }

    private function sortUpcastersByPriority(): void
    {
        uasort($this->upcasters, function (EventUpcasterInterface $a, EventUpcasterInterface $b) {
            return $b->getPriority() <=> $a->getPriority();
        });
    }

    private function getUpcasterKey(EventUpcasterInterface $upcaster): string
    {
        return "{$upcaster->getEventType()}:{$upcaster->getFromVersion()}:{$upcaster->getToVersion()}";
    }

    private function addMigrationMetadata(array $eventData, int $fromVersion, int $toVersion): array
    {
        if (!isset($eventData['metadata'])) {
            $eventData['metadata'] = [];
        }

        if (!isset($eventData['metadata']['migrations'])) {
            $eventData['metadata']['migrations'] = [];
        }

        $eventData['metadata']['migrations'][] = [
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'migrated_at' => now()->toISOString(),
        ];

        return $eventData;
    }

    private function loadUpcasters(): void
    {
        // In a real implementation, this would load upcasters from
        // configuration, service container, or auto-discovery
        // For now, it's a placeholder
    }

    private function clearUpcastersCache(): void
    {
        // Clear all cached upcast chains
        // In production, you might want to use cache tags
        $eventTypes = $this->getEventTypes();

        foreach ($eventTypes as $eventType) {
            Cache::forget("event_latest_version:{$eventType}");

            // Clear chains for all possible versions
            for ($version = 1; $version <= 10; $version++) {
                Cache::forget("upcast_chain:{$eventType}:{$version}");
            }
        }
    }
}