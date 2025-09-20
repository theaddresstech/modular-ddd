<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Caching;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CacheInvalidationManager
{
    private array $pendingInvalidations = [];
    private int $batchSize = 100;
    private int $maxInvalidationsPerSecond = 50;
    private int $lastInvalidationTime = 0;
    private int $invalidationCount = 0;

    public function __construct(int $batchSize = 100, int $maxInvalidationsPerSecond = 50)
    {
        $this->batchSize = $batchSize;
        $this->maxInvalidationsPerSecond = $maxInvalidationsPerSecond;
    }

    /**
     * Queue cache invalidation to prevent storms
     */
    public function queueInvalidation(array $tags): void
    {
        foreach ($tags as $tag) {
            if (!in_array($tag, $this->pendingInvalidations)) {
                $this->pendingInvalidations[] = $tag;
            }
        }

        // Process immediately if we have enough entries or it's been a while
        if (count($this->pendingInvalidations) >= $this->batchSize || $this->shouldProcessImmediately()) {
            $this->processPendingInvalidations();
        }
    }

    /**
     * Force immediate processing of all pending invalidations
     */
    public function flush(): void
    {
        if (!empty($this->pendingInvalidations)) {
            $this->processPendingInvalidations();
        }
    }

    /**
     * Process pending invalidations with rate limiting
     */
    private function processPendingInvalidations(): void
    {
        if (empty($this->pendingInvalidations)) {
            return;
        }

        // Rate limiting
        $currentTime = time();
        if ($currentTime === $this->lastInvalidationTime) {
            if ($this->invalidationCount >= $this->maxInvalidationsPerSecond) {
                Log::warning('Cache invalidation rate limit exceeded, delaying batch', [
                    'pending_count' => count($this->pendingInvalidations),
                    'rate_limit' => $this->maxInvalidationsPerSecond,
                ]);
                return;
            }
        } else {
            $this->lastInvalidationTime = $currentTime;
            $this->invalidationCount = 0;
        }

        $batch = array_splice($this->pendingInvalidations, 0, $this->batchSize);
        $processed = 0;

        try {
            // Process Redis cache invalidation (supports batching)
            $this->invalidateRedisCache($batch);
            $processed += count($batch);

            // Process database cache invalidation
            $this->invalidateDatabaseCache($batch);

            $this->invalidationCount += $processed;

            Log::info('Cache invalidation batch processed', [
                'tags_processed' => $processed,
                'remaining_pending' => count($this->pendingInvalidations),
                'rate_limit_usage' => "{$this->invalidationCount}/{$this->maxInvalidationsPerSecond}",
            ]);

        } catch (\Exception $e) {
            Log::error('Cache invalidation batch failed', [
                'error' => $e->getMessage(),
                'batch_size' => count($batch),
                'pending_count' => count($this->pendingInvalidations),
            ]);

            // Re-queue failed batch
            $this->pendingInvalidations = array_merge($batch, $this->pendingInvalidations);
        }
    }

    private function invalidateRedisCache(array $tags): void
    {
        try {
            $redis = Cache::store('redis');

            // Use Redis pipeline for efficiency
            $pipeline = $redis->getRedis()->pipeline();

            foreach ($tags as $tag) {
                // Invalidate by tag if supported
                if (method_exists($redis, 'tags')) {
                    $pipeline->eval("
                        local keys = redis.call('keys', ARGV[1] .. '*')
                        for i=1,#keys do
                            redis.call('del', keys[i])
                        end
                        return #keys
                    ", 0, "cache_tag:{$tag}:");
                }
            }

            $results = $pipeline->exec();

            Log::debug('Redis cache invalidated', [
                'tags' => $tags,
                'keys_deleted' => array_sum($results ?? []),
            ]);

        } catch (\Exception $e) {
            Log::error('Redis cache invalidation failed', [
                'tags' => $tags,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function invalidateDatabaseCache(array $tags): void
    {
        try {
            foreach ($tags as $tag) {
                $taggedKeys = Cache::get("tag_mapping:{$tag}:L3", []);

                // Batch delete database cache entries
                $chunks = array_chunk($taggedKeys, 50); // Process in chunks of 50

                foreach ($chunks as $chunk) {
                    Cache::store('database')->deleteMultiple($chunk);
                }

                // Clean up tag mapping
                Cache::forget("tag_mapping:{$tag}:L3");
            }

            Log::debug('Database cache invalidated', [
                'tags' => $tags,
            ]);

        } catch (\Exception $e) {
            Log::error('Database cache invalidation failed', [
                'tags' => $tags,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function shouldProcessImmediately(): bool
    {
        // Process if it's been more than 5 seconds since last processing
        return (time() - $this->lastInvalidationTime) > 5;
    }

    /**
     * Get invalidation statistics
     */
    public function getStatistics(): array
    {
        return [
            'pending_invalidations' => count($this->pendingInvalidations),
            'batch_size' => $this->batchSize,
            'rate_limit' => $this->maxInvalidationsPerSecond,
            'current_rate_usage' => $this->invalidationCount,
            'last_processing_time' => $this->lastInvalidationTime,
            'should_process_immediately' => $this->shouldProcessImmediately(),
        ];
    }

    /**
     * Configure batch processing settings
     */
    public function configure(int $batchSize = null, int $maxInvalidationsPerSecond = null): void
    {
        if ($batchSize !== null) {
            $this->batchSize = $batchSize;
        }

        if ($maxInvalidationsPerSecond !== null) {
            $this->maxInvalidationsPerSecond = $maxInvalidationsPerSecond;
        }

        Log::info('Cache invalidation manager configured', [
            'batch_size' => $this->batchSize,
            'max_invalidations_per_second' => $this->maxInvalidationsPerSecond,
        ]);
    }
}