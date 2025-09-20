<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Performance;

use LaravelModularDDD\Core\Events\DomainEventInterface;
use Illuminate\Support\Facades\Cache;

class EventDeserializationCache
{
    private array $l1Cache = []; // In-memory cache
    private int $l1MaxSize;
    private string $cachePrefix;
    private int $defaultTtl;

    public function __construct(
        int $l1MaxSize = 1000,
        string $cachePrefix = 'event_deserial',
        int $defaultTtl = 3600
    ) {
        $this->l1MaxSize = $l1MaxSize;
        $this->cachePrefix = $cachePrefix;
        $this->defaultTtl = $defaultTtl;
    }

    /**
     * Get deserialized event from cache
     */
    public function get(string $cacheKey): ?DomainEventInterface
    {
        // Check L1 cache first (fastest)
        if (isset($this->l1Cache[$cacheKey])) {
            $entry = $this->l1Cache[$cacheKey];

            // Check if expired
            if ($entry['expires_at'] > time()) {
                $entry['access_count']++;
                $entry['last_accessed'] = time();
                return clone $entry['event']; // Return clone to prevent mutations
            } else {
                unset($this->l1Cache[$cacheKey]);
            }
        }

        // Check L2 cache (Redis/database)
        $fullKey = $this->getCacheKey($cacheKey);
        $serializedData = Cache::get($fullKey);

        if ($serializedData !== null) {
            $event = unserialize($serializedData);

            if ($event instanceof DomainEventInterface) {
                // Promote to L1
                $this->putInL1($cacheKey, $event);
                return clone $event;
            }
        }

        return null;
    }

    /**
     * Store deserialized event in cache
     */
    public function put(string $cacheKey, DomainEventInterface $event, ?int $ttl = null): void
    {
        $ttl = $ttl ?? $this->defaultTtl;

        // Store in L1
        $this->putInL1($cacheKey, $event, $ttl);

        // Store in L2
        $fullKey = $this->getCacheKey($cacheKey);
        $serializedData = serialize($event);
        Cache::put($fullKey, $serializedData, $ttl);
    }

    /**
     * Check if event exists in cache
     */
    public function has(string $cacheKey): bool
    {
        if (isset($this->l1Cache[$cacheKey])) {
            return $this->l1Cache[$cacheKey]['expires_at'] > time();
        }

        return Cache::has($this->getCacheKey($cacheKey));
    }

    /**
     * Remove event from cache
     */
    public function forget(string $cacheKey): void
    {
        unset($this->l1Cache[$cacheKey]);
        Cache::forget($this->getCacheKey($cacheKey));
    }

    /**
     * Clear all cached events
     */
    public function flush(): void
    {
        $this->l1Cache = [];

        // Clear L2 cache with prefix
        if (method_exists(Cache::getStore(), 'flush')) {
            // If we can't selectively delete by prefix, we'll need to track keys
            // For now, just clear L1
        }
    }

    /**
     * Get cache statistics
     */
    public function getStatistics(): array
    {
        $l1Size = count($this->l1Cache);
        $l1Hits = array_sum(array_column($this->l1Cache, 'access_count'));

        return [
            'l1_cache' => [
                'size' => $l1Size,
                'max_size' => $this->l1MaxSize,
                'usage_percentage' => $l1Size > 0 ? ($l1Size / $this->l1MaxSize) * 100 : 0,
                'total_hits' => $l1Hits,
            ],
            'memory_usage_estimate_kb' => $this->estimateMemoryUsage(),
        ];
    }

    /**
     * Generate cache key for event data
     */
    public function generateCacheKey(array $eventData): string
    {
        // Create cache key from event data hash
        $keyData = [
            'type' => $eventData['event_type'] ?? '',
            'version' => $eventData['event_version'] ?? 1,
            'data_hash' => md5(serialize($eventData['event_data'] ?? [])),
        ];

        return md5(serialize($keyData));
    }

    private function putInL1(string $cacheKey, DomainEventInterface $event, ?int $ttl = null): void
    {
        $ttl = $ttl ?? $this->defaultTtl;

        // Evict if cache is full
        if (count($this->l1Cache) >= $this->l1MaxSize) {
            $this->evictFromL1();
        }

        $this->l1Cache[$cacheKey] = [
            'event' => clone $event, // Store clone to prevent mutations
            'expires_at' => time() + $ttl,
            'created_at' => time(),
            'access_count' => 1,
            'last_accessed' => time(),
        ];
    }

    private function evictFromL1(): void
    {
        if (empty($this->l1Cache)) {
            return;
        }

        // Evict least recently used entries
        uasort($this->l1Cache, function ($a, $b) {
            return $a['last_accessed'] <=> $b['last_accessed'];
        });

        // Remove 10% of entries
        $toRemove = max(1, (int) (count($this->l1Cache) * 0.1));

        for ($i = 0; $i < $toRemove; $i++) {
            array_shift($this->l1Cache);
        }
    }

    private function getCacheKey(string $cacheKey): string
    {
        return "{$this->cachePrefix}:{$cacheKey}";
    }

    private function estimateMemoryUsage(): int
    {
        if (empty($this->l1Cache)) {
            return 0;
        }

        // Rough estimate: 2KB per cached event (including metadata)
        return count($this->l1Cache) * 2;
    }
}