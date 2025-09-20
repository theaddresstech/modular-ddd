<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Monitoring;

use Illuminate\Support\Facades\Log;

class MemoryLeakDetector
{
    private array $memorySnapshots = [];
    private array $objectCounts = [];
    private int $maxSnapshots = 100;
    private float $leakThresholdMB = 50.0;

    public function takeSnapshot(string $label = 'default'): void
    {
        $snapshot = [
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'objects' => $this->countObjects(),
            'label' => $label,
        ];

        $this->memorySnapshots[] = $snapshot;

        // Limit snapshots to prevent memory growth
        if (count($this->memorySnapshots) > $this->maxSnapshots) {
            array_shift($this->memorySnapshots);
        }

        $this->detectLeaks($snapshot);
    }

    public function detectLeaks(array $currentSnapshot): void
    {
        if (count($this->memorySnapshots) < 2) {
            return;
        }

        $previousSnapshot = $this->memorySnapshots[count($this->memorySnapshots) - 2];
        $memoryIncrease = ($currentSnapshot['memory_usage'] - $previousSnapshot['memory_usage']) / 1024 / 1024;

        if ($memoryIncrease > $this->leakThresholdMB) {
            $this->reportPotentialLeak($previousSnapshot, $currentSnapshot, $memoryIncrease);
        }
    }

    public function forceGarbageCollection(): void
    {
        $beforeMemory = memory_get_usage(true);

        // Force garbage collection
        if (function_exists('gc_collect_cycles')) {
            $cycles = gc_collect_cycles();
        } else {
            $cycles = 0;
        }

        $afterMemory = memory_get_usage(true);
        $freed = ($beforeMemory - $afterMemory) / 1024 / 1024;

        Log::info('Forced garbage collection', [
            'cycles_collected' => $cycles,
            'memory_freed_mb' => round($freed, 2),
            'memory_before_mb' => round($beforeMemory / 1024 / 1024, 2),
            'memory_after_mb' => round($afterMemory / 1024 / 1024, 2),
        ]);
    }

    public function getMemoryReport(): array
    {
        if (empty($this->memorySnapshots)) {
            return ['error' => 'No snapshots available'];
        }

        $first = $this->memorySnapshots[0];
        $last = $this->memorySnapshots[array_key_last($this->memorySnapshots)];

        $totalIncrease = ($last['memory_usage'] - $first['memory_usage']) / 1024 / 1024;
        $timeElapsed = $last['timestamp'] - $first['timestamp'];

        return [
            'snapshots_taken' => count($this->memorySnapshots),
            'time_elapsed_seconds' => round($timeElapsed, 2),
            'memory_increase_mb' => round($totalIncrease, 2),
            'average_increase_per_minute' => $timeElapsed > 0 ? round(($totalIncrease / $timeElapsed) * 60, 2) : 0,
            'current_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'php_memory_limit' => ini_get('memory_limit'),
            'gc_enabled' => gc_enabled(),
        ];
    }

    private function countObjects(): array
    {
        $counts = [];

        if (function_exists('gc_status')) {
            $gcStatus = gc_status();
            $counts['gc_objects'] = $gcStatus['roots'] ?? 0;
        }

        // Count specific DDD objects if they exist in memory
        $counts['total_objects'] = $this->estimateObjectCount();

        return $counts;
    }

    private function estimateObjectCount(): int
    {
        // Simple estimation based on memory usage patterns
        // In production, you might use more sophisticated memory profiling tools
        $currentMemory = memory_get_usage(true);
        $baseMemory = 2 * 1024 * 1024; // ~2MB base memory

        if ($currentMemory <= $baseMemory) {
            return 0;
        }

        // Rough estimation: 1000 bytes per object on average
        return (int) (($currentMemory - $baseMemory) / 1000);
    }

    private function reportPotentialLeak(array $previous, array $current, float $increaseMB): void
    {
        Log::warning('Potential memory leak detected', [
            'memory_increase_mb' => round($increaseMB, 2),
            'threshold_mb' => $this->leakThresholdMB,
            'previous_label' => $previous['label'],
            'current_label' => $current['label'],
            'time_diff_seconds' => round($current['timestamp'] - $previous['timestamp'], 2),
            'previous_memory_mb' => round($previous['memory_usage'] / 1024 / 1024, 2),
            'current_memory_mb' => round($current['memory_usage'] / 1024 / 1024, 2),
            'recommendation' => 'Consider reviewing object lifecycle and cache management',
        ]);
    }
}