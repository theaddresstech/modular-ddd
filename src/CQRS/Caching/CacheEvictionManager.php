<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Caching;

use Illuminate\Support\Facades\Log;

class CacheEvictionManager
{
    private int $maxMemoryMB;
    private int $targetMemoryMB;
    private float $evictionThreshold = 0.8; // 80% of max memory triggers eviction

    public function __construct(int $maxMemoryMB = 512, int $targetMemoryMB = 400)
    {
        $this->maxMemoryMB = $maxMemoryMB;
        $this->targetMemoryMB = $targetMemoryMB;
    }

    public function setMemoryThreshold(int $maxMemoryBytes, float $evictionThreshold): void
    {
        $this->maxMemoryMB = (int) ($maxMemoryBytes / 1024 / 1024);
        $this->evictionThreshold = $evictionThreshold;
    }

    public function shouldEvict(): bool
    {
        $currentMemoryMB = memory_get_usage(true) / 1024 / 1024;
        return $currentMemoryMB > ($this->maxMemoryMB * $this->evictionThreshold);
    }

    public function evictFromArray(array &$cache, string $strategy = 'lru'): int
    {
        if (!$this->shouldEvict()) {
            return 0;
        }

        $originalSize = count($cache);
        $targetSize = (int) ($originalSize * 0.7); // Remove 30% of entries

        switch ($strategy) {
            case 'lru':
                $cache = $this->evictLRU($cache, $targetSize);
                break;
            case 'ttl':
                $cache = $this->evictExpired($cache, $targetSize);
                break;
            case 'size':
                $cache = $this->evictLargest($cache, $targetSize);
                break;
            default:
                $cache = $this->evictRandom($cache, $targetSize);
        }

        $removedCount = $originalSize - count($cache);

        if ($removedCount > 0) {
            Log::info('Cache eviction performed', [
                'strategy' => $strategy,
                'original_size' => $originalSize,
                'removed_entries' => $removedCount,
                'remaining_entries' => count($cache),
                'memory_before_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            ]);

            // Force garbage collection after eviction
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            Log::info('Post-eviction memory usage', [
                'memory_after_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            ]);
        }

        return $removedCount;
    }

    private function evictLRU(array $cache, int $targetSize): array
    {
        // Sort by last accessed time (oldest first)
        uasort($cache, function ($a, $b) {
            $aTime = $a['last_accessed'] ?? $a['created_at'] ?? 0;
            $bTime = $b['last_accessed'] ?? $b['created_at'] ?? 0;
            return $aTime <=> $bTime;
        });

        return array_slice($cache, $targetSize, null, true);
    }

    private function evictExpired(array $cache, int $targetSize): array
    {
        $currentTime = time();
        $nonExpired = [];

        foreach ($cache as $key => $entry) {
            $expiresAt = $entry['expires_at'] ?? null;
            if ($expiresAt === null || $currentTime <= $expiresAt) {
                $nonExpired[$key] = $entry;
            }
        }

        // If we still have too many entries after removing expired ones
        if (count($nonExpired) > $targetSize) {
            return $this->evictLRU($nonExpired, $targetSize);
        }

        return $nonExpired;
    }

    private function evictLargest(array $cache, int $targetSize): array
    {
        // Sort by data size (largest first)
        uasort($cache, function ($a, $b) {
            $aSize = is_string($a['data']) ? strlen($a['data']) : strlen(serialize($a['data']));
            $bSize = is_string($b['data']) ? strlen($b['data']) : strlen(serialize($b['data']));
            return $bSize <=> $aSize;
        });

        return array_slice($cache, $targetSize, null, true);
    }

    private function evictRandom(array $cache, int $targetSize): array
    {
        $keys = array_keys($cache);
        shuffle($keys);

        $preserved = array_slice($keys, 0, $targetSize);
        $result = [];

        foreach ($preserved as $key) {
            $result[$key] = $cache[$key];
        }

        return $result;
    }

    public function getEvictionStats(): array
    {
        $currentMemoryMB = memory_get_usage(true) / 1024 / 1024;

        return [
            'current_memory_mb' => round($currentMemoryMB, 2),
            'max_memory_mb' => $this->maxMemoryMB,
            'target_memory_mb' => $this->targetMemoryMB,
            'eviction_threshold_mb' => round($this->maxMemoryMB * $this->evictionThreshold, 2),
            'should_evict' => $this->shouldEvict(),
            'memory_usage_percentage' => round(($currentMemoryMB / $this->maxMemoryMB) * 100, 2),
        ];
    }
}