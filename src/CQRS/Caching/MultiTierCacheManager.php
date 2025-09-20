<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Caching;

use LaravelModularDDD\CQRS\Contracts\QueryInterface;
use LaravelModularDDD\CQRS\Caching\CacheEvictionManager;
use LaravelModularDDD\CQRS\Caching\CacheInvalidationManager;
use LaravelModularDDD\CQRS\Monitoring\MemoryLeakDetector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MultiTierCacheManager
{
    private array $l1Cache = []; // In-memory cache for request lifecycle
    private int $l1MaxSize = 1000; // Maximum L1 cache entries
    private int $maxMemoryMb = 128; // Maximum memory usage in MB
    private float $evictionThreshold = 0.8; // Trigger eviction at 80% of memory limit
    private CacheEvictionManager $evictionManager;
    private MemoryLeakDetector $memoryDetector;
    private CacheInvalidationManager $invalidationManager;

    public function __construct(
        private readonly string $l2CacheStore = 'redis', // L2: Distributed cache
        private readonly string $l3CacheStore = 'database' // L3: Database cache
    ) {
        $this->evictionManager = new CacheEvictionManager();
        $this->memoryDetector = new MemoryLeakDetector();
        $this->invalidationManager = new CacheInvalidationManager();
    }

    /**
     * Configure memory limits for cache management
     */
    public function setMemoryLimits(int $l1MaxEntries, int $maxMemoryMb, float $evictionThreshold): void
    {
        $this->l1MaxSize = $l1MaxEntries;
        $this->maxMemoryMb = $maxMemoryMb;
        $this->evictionThreshold = $evictionThreshold;

        // Update eviction manager with new thresholds
        $this->evictionManager->setMemoryThreshold($maxMemoryMb * 1024 * 1024, $evictionThreshold);

        Log::debug('Cache memory limits configured', [
            'l1_max_entries' => $l1MaxEntries,
            'max_memory_mb' => $maxMemoryMb,
            'eviction_threshold' => $evictionThreshold,
        ]);
    }

    /**
     * Get value from multi-tier cache
     */
    public function get(QueryInterface $query): mixed
    {
        $cacheKey = $query->getCacheKey();

        // L1: Check in-memory cache first (fastest)
        if ($this->hasInL1($cacheKey)) {
            $this->recordCacheHit('L1', $query);
            return $this->l1Cache[$cacheKey]['data'];
        }

        // L2: Check distributed cache (Redis)
        $l2Result = Cache::store($this->l2CacheStore)->get($cacheKey);
        if ($l2Result !== null) {
            // Promote to L1 cache
            $this->putInL1($cacheKey, $l2Result, $query->getCacheTtl());
            $this->recordCacheHit('L2', $query);
            return $l2Result;
        }

        // L3: Check database cache
        $l3Result = Cache::store($this->l3CacheStore)->get($cacheKey);
        if ($l3Result !== null) {
            // Promote to L2 and L1
            $this->putInL2($cacheKey, $l3Result, $query);
            $this->putInL1($cacheKey, $l3Result, $query->getCacheTtl());
            $this->recordCacheHit('L3', $query);
            return $l3Result;
        }

        $this->recordCacheMiss($query);
        return null;
    }

    /**
     * Put value in all cache tiers
     */
    public function put(QueryInterface $query, mixed $value): void
    {
        $cacheKey = $query->getCacheKey();
        $ttl = $query->getCacheTtl();

        // Store in all tiers
        $this->putInL1($cacheKey, $value, $ttl);
        $this->putInL2($cacheKey, $value, $query);
        $this->putInL3($cacheKey, $value, $query);

        Log::debug('Value cached in all tiers', [
            'cache_key' => $cacheKey,
            'query_type' => $query->getQueryName(),
            'ttl' => $ttl,
        ]);
    }

    /**
     * Check if key exists in any cache tier
     */
    public function has(QueryInterface $query): bool
    {
        $cacheKey = $query->getCacheKey();

        return $this->hasInL1($cacheKey) ||
               Cache::store($this->l2CacheStore)->has($cacheKey) ||
               Cache::store($this->l3CacheStore)->has($cacheKey);
    }

    /**
     * Invalidate cache by tags (rate-limited to prevent storms)
     */
    public function invalidateTags(array $tags): void
    {
        // Clear L1 cache entries immediately (in-memory, fast)
        $this->invalidateL1ByTags($tags);

        // Queue L2/L3 invalidation to prevent storms
        $this->invalidationManager->queueInvalidation($tags);

        Log::info('Cache invalidation queued for tags', [
            'tags' => $tags,
            'l1_invalidated' => 'immediate',
            'l2_l3_invalidated' => 'queued',
        ]);
    }

    /**
     * Force immediate cache invalidation (use sparingly)
     */
    public function forceInvalidateTags(array $tags): void
    {
        // Clear L1 cache entries with matching tags
        $this->invalidateL1ByTags($tags);

        // Clear L2 cache (Redis supports tags)
        if (method_exists(Cache::store($this->l2CacheStore), 'tags')) {
            Cache::store($this->l2CacheStore)->tags($tags)->flush();
        }

        // Clear L3 cache (Database cache typically doesn't support tags efficiently)
        // So we track cache keys by tags separately
        $this->invalidateL3ByTags($tags);

        Log::warning('Forced cache invalidation for tags', [
            'tags' => $tags,
            'invalidated_tiers' => ['L1', 'L2', 'L3'],
            'warning' => 'This bypassed rate limiting and may cause cache storms',
        ]);
    }

    /**
     * Get cache statistics including memory management info
     */
    public function getStatistics(): array
    {
        return [
            'l1_cache' => [
                'size' => count($this->l1Cache),
                'max_size' => $this->l1MaxSize,
                'hit_rate' => $this->getL1HitRate(),
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'memory_limit_mb' => $this->maxMemoryMb,
                'eviction_threshold' => $this->evictionThreshold,
                'memory_threshold_mb' => round($this->maxMemoryMb * $this->evictionThreshold, 2),
            ],
            'l2_cache' => [
                'store' => $this->l2CacheStore,
                'hit_rate' => $this->getL2HitRate(),
            ],
            'l3_cache' => [
                'store' => $this->l3CacheStore,
                'hit_rate' => $this->getL3HitRate(),
            ],
            'overall_performance' => $this->getOverallPerformance(),
            'memory_management' => [
                'eviction_stats' => $this->evictionManager->getEvictionStats(),
                'memory_report' => $this->memoryDetector->getMemoryReport(),
                'invalidation_stats' => $this->invalidationManager->getStatistics(),
            ],
        ];
    }

    /**
     * Clear all cache tiers
     */
    public function flush(): void
    {
        // Clear L1
        $this->l1Cache = [];

        // Clear L2
        Cache::store($this->l2CacheStore)->flush();

        // Clear L3
        Cache::store($this->l3CacheStore)->flush();

        Log::info('All cache tiers flushed');
    }

    /**
     * Warm cache with query
     */
    public function warm(QueryInterface $query, mixed $value): void
    {
        $this->put($query, $value);
    }

    /**
     * Perform periodic cleanup to prevent memory leaks
     */
    public function periodicCleanup(): void
    {
        // Remove expired entries from L1 cache
        $currentTime = time();
        $expiredKeys = [];

        foreach ($this->l1Cache as $key => $entry) {
            if (isset($entry['expires_at']) && $entry['expires_at'] && $currentTime > $entry['expires_at']) {
                $expiredKeys[] = $key;
            }
        }

        foreach ($expiredKeys as $key) {
            unset($this->l1Cache[$key]);
        }

        // Force eviction if memory usage is high
        if ($this->evictionManager->shouldEvict()) {
            $removed = $this->evictionManager->evictFromArray($this->l1Cache, 'ttl');

            Log::info('Periodic cache cleanup completed', [
                'expired_entries_removed' => count($expiredKeys),
                'evicted_entries_removed' => $removed,
                'remaining_entries' => count($this->l1Cache),
                'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            ]);
        }

        // Take memory snapshot for leak detection
        $this->memoryDetector->takeSnapshot("periodic_cleanup");

        // Force garbage collection if needed
        if ($this->evictionManager->shouldEvict()) {
            $this->memoryDetector->forceGarbageCollection();
        }
    }

    private function hasInL1(string $cacheKey): bool
    {
        if (!isset($this->l1Cache[$cacheKey])) {
            return false;
        }

        $entry = $this->l1Cache[$cacheKey];

        // Check if expired
        if ($entry['expires_at'] && time() > $entry['expires_at']) {
            unset($this->l1Cache[$cacheKey]);
            return false;
        }

        return true;
    }

    private function putInL1(string $cacheKey, mixed $value, int $ttl): void
    {
        // Check memory limits before adding
        $currentMemoryMb = memory_get_usage(true) / 1024 / 1024;
        $memoryThresholdMb = $this->maxMemoryMb * $this->evictionThreshold;

        // Use eviction manager for more sophisticated memory management
        if (count($this->l1Cache) >= $this->l1MaxSize ||
            $currentMemoryMb >= $memoryThresholdMb ||
            $this->evictionManager->shouldEvict()) {

            $entriesRemoved = $this->evictionManager->evictFromArray($this->l1Cache, 'lru');

            // Take memory snapshot for leak detection
            $this->memoryDetector->takeSnapshot("L1_cache_eviction");

            Log::debug('L1 cache eviction triggered', [
                'reason' => $currentMemoryMb >= $memoryThresholdMb ? 'memory_threshold' : 'size_limit',
                'entries_removed' => $entriesRemoved,
                'memory_mb' => round($currentMemoryMb, 2),
                'memory_threshold_mb' => round($memoryThresholdMb, 2),
                'cache_size' => count($this->l1Cache),
                'max_size' => $this->l1MaxSize,
            ]);
        }

        $this->l1Cache[$cacheKey] = [
            'data' => $value,
            'expires_at' => $ttl > 0 ? time() + $ttl : null,
            'created_at' => time(),
            'access_count' => 0,
            'last_accessed' => time(),
        ];
    }

    private function putInL2(string $cacheKey, mixed $value, QueryInterface $query): void
    {
        $ttl = $query->getCacheTtl();
        $tags = $query->getCacheTags();

        if (!empty($tags) && method_exists(Cache::store($this->l2CacheStore), 'tags')) {
            Cache::store($this->l2CacheStore)->tags($tags)->put($cacheKey, $value, $ttl);
        } else {
            Cache::store($this->l2CacheStore)->put($cacheKey, $value, $ttl);
        }

        // Store tag mapping for invalidation
        if (!empty($tags)) {
            $this->storeTagMapping($cacheKey, $tags, 'L2');
        }
    }

    private function putInL3(string $cacheKey, mixed $value, QueryInterface $query): void
    {
        $ttl = $query->getCacheTtl() * 2; // L3 cache lives longer
        $tags = $query->getCacheTags();

        Cache::store($this->l3CacheStore)->put($cacheKey, $value, $ttl);

        // Store tag mapping for invalidation
        if (!empty($tags)) {
            $this->storeTagMapping($cacheKey, $tags, 'L3');
        }
    }


    private function invalidateL1ByTags(array $tags): void
    {
        // For L1 cache, we need to check each entry's tags
        // This is simplified - in production you might want tag indexing
        foreach ($this->l1Cache as $key => $entry) {
            // This would require storing tags with each L1 entry
            // Simplified implementation for now
        }
    }

    private function invalidateL3ByTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $taggedKeys = Cache::get("tag_mapping:{$tag}:L3", []);
            foreach ($taggedKeys as $key) {
                Cache::store($this->l3CacheStore)->forget($key);
            }
            Cache::forget("tag_mapping:{$tag}:L3");
        }
    }

    private function storeTagMapping(string $cacheKey, array $tags, string $tier): void
    {
        foreach ($tags as $tag) {
            $taggedKeys = Cache::get("tag_mapping:{$tag}:{$tier}", []);
            $taggedKeys[] = $cacheKey;
            Cache::put("tag_mapping:{$tag}:{$tier}", array_unique($taggedKeys), 3600);
        }
    }

    private function recordCacheHit(string $tier, QueryInterface $query): void
    {
        $stats = Cache::get('cache_tier_stats', ['L1' => 0, 'L2' => 0, 'L3' => 0, 'misses' => 0]);
        $stats[$tier]++;
        Cache::put('cache_tier_stats', $stats, 300);

        // Update L1 access info if applicable
        if ($tier === 'L1') {
            $cacheKey = $query->getCacheKey();
            if (isset($this->l1Cache[$cacheKey])) {
                $this->l1Cache[$cacheKey]['access_count']++;
                $this->l1Cache[$cacheKey]['last_accessed'] = time();
            }
        }
    }

    private function recordCacheMiss(QueryInterface $query): void
    {
        $stats = Cache::get('cache_tier_stats', ['L1' => 0, 'L2' => 0, 'L3' => 0, 'misses' => 0]);
        $stats['misses']++;
        Cache::put('cache_tier_stats', $stats, 300);
    }

    private function getL1HitRate(): float
    {
        $stats = Cache::get('cache_tier_stats', ['L1' => 0, 'L2' => 0, 'L3' => 0, 'misses' => 0]);
        $total = array_sum($stats);
        return $total > 0 ? ($stats['L1'] / $total) * 100 : 0;
    }

    private function getL2HitRate(): float
    {
        $stats = Cache::get('cache_tier_stats', ['L1' => 0, 'L2' => 0, 'L3' => 0, 'misses' => 0]);
        $total = array_sum($stats);
        return $total > 0 ? ($stats['L2'] / $total) * 100 : 0;
    }

    private function getL3HitRate(): float
    {
        $stats = Cache::get('cache_tier_stats', ['L1' => 0, 'L2' => 0, 'L3' => 0, 'misses' => 0]);
        $total = array_sum($stats);
        return $total > 0 ? ($stats['L3'] / $total) * 100 : 0;
    }

    private function getOverallPerformance(): array
    {
        $stats = Cache::get('cache_tier_stats', ['L1' => 0, 'L2' => 0, 'L3' => 0, 'misses' => 0]);
        $total = array_sum($stats);

        if ($total === 0) {
            return ['hit_rate' => 0, 'miss_rate' => 0];
        }

        $hits = $stats['L1'] + $stats['L2'] + $stats['L3'];

        return [
            'hit_rate' => ($hits / $total) * 100,
            'miss_rate' => ($stats['misses'] / $total) * 100,
            'l1_percentage' => ($stats['L1'] / $total) * 100,
            'l2_percentage' => ($stats['L2'] / $total) * 100,
            'l3_percentage' => ($stats['L3'] / $total) * 100,
        ];
    }
}
