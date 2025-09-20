<?php

declare(strict_types=1);

namespace LaravelModularDDD\Monitoring;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * PerformanceMetricsCollector
 *
 * Collects and aggregates performance metrics for the DDD system.
 * Provides insights into command processing, query performance, and system health.
 */
final class PerformanceMetricsCollector
{
    private const METRICS_CACHE_PREFIX = 'ddd_metrics:';
    private const METRICS_CACHE_TTL = 300; // 5 minutes

    private array $metrics = [];
    private array $timers = [];

    public function __construct()
    {
        $this->initializeMetrics();
    }

    /**
     * Start timing an operation.
     */
    public function startTimer(string $operation, array $context = []): string
    {
        $timerId = uniqid($operation . '_', true);

        $this->timers[$timerId] = [
            'operation' => $operation,
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
            'context' => $context,
        ];

        return $timerId;
    }

    /**
     * Stop timing an operation and record the metric.
     */
    public function stopTimer(string $timerId): ?array
    {
        if (!isset($this->timers[$timerId])) {
            return null;
        }

        $timer = $this->timers[$timerId];
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $duration = ($endTime - $timer['start_time']) * 1000; // Convert to milliseconds
        $memoryUsed = $endMemory - $timer['start_memory'];

        $metric = [
            'operation' => $timer['operation'],
            'duration_ms' => round($duration, 2),
            'memory_used_bytes' => $memoryUsed,
            'timestamp' => now()->toISOString(),
            'context' => $timer['context'],
        ];

        $this->recordMetric($timer['operation'], $metric);

        unset($this->timers[$timerId]);

        return $metric;
    }

    /**
     * Record a custom metric.
     */
    public function recordMetric(string $type, array $data): void
    {
        $metricKey = $type . ':' . date('Y-m-d:H:i');

        if (!isset($this->metrics[$metricKey])) {
            $this->metrics[$metricKey] = [
                'type' => $type,
                'count' => 0,
                'total_duration_ms' => 0,
                'min_duration_ms' => null,
                'max_duration_ms' => null,
                'avg_duration_ms' => 0,
                'total_memory_bytes' => 0,
                'avg_memory_bytes' => 0,
                'errors' => 0,
                'minute' => date('Y-m-d H:i'),
                'samples' => [],
            ];
        }

        $metric = &$this->metrics[$metricKey];
        $metric['count']++;

        if (isset($data['duration_ms'])) {
            $duration = $data['duration_ms'];
            $metric['total_duration_ms'] += $duration;
            $metric['avg_duration_ms'] = $metric['total_duration_ms'] / $metric['count'];

            if ($metric['min_duration_ms'] === null || $duration < $metric['min_duration_ms']) {
                $metric['min_duration_ms'] = $duration;
            }

            if ($metric['max_duration_ms'] === null || $duration > $metric['max_duration_ms']) {
                $metric['max_duration_ms'] = $duration;
            }
        }

        if (isset($data['memory_used_bytes'])) {
            $metric['total_memory_bytes'] += $data['memory_used_bytes'];
            $metric['avg_memory_bytes'] = intval($metric['total_memory_bytes'] / $metric['count']);
        }

        if (isset($data['error']) && $data['error']) {
            $metric['errors']++;
        }

        // Store sample data (limited to prevent memory issues)
        if (count($metric['samples']) < 100) {
            $metric['samples'][] = $data;
        }

        // Persist metrics to cache
        $this->persistMetrics();
    }

    /**
     * Increment a counter metric.
     */
    public function increment(string $metric, int $value = 1, array $tags = []): void
    {
        $this->recordMetric($metric, [
            'counter' => $value,
            'tags' => $tags,
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Record a gauge metric (current value).
     */
    public function gauge(string $metric, float $value, array $tags = []): void
    {
        $this->recordMetric($metric, [
            'gauge' => $value,
            'tags' => $tags,
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Record a histogram metric.
     */
    public function histogram(string $metric, float $value, array $tags = []): void
    {
        $this->recordMetric($metric, [
            'histogram' => $value,
            'tags' => $tags,
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Get current metrics.
     */
    public function getMetrics(string $type = null): array
    {
        $this->loadPersistedMetrics();

        if ($type === null) {
            return $this->metrics;
        }

        return array_filter(
            $this->metrics,
            fn($metric) => $metric['type'] === $type
        );
    }

    /**
     * Get aggregated metrics for a time period.
     */
    public function getAggregatedMetrics(string $type, int $minutes = 60): array
    {
        $this->loadPersistedMetrics();

        $cutoff = now()->subMinutes($minutes);
        $aggregated = [
            'type' => $type,
            'period_minutes' => $minutes,
            'total_count' => 0,
            'total_duration_ms' => 0,
            'avg_duration_ms' => 0,
            'min_duration_ms' => null,
            'max_duration_ms' => null,
            'total_errors' => 0,
            'error_rate_percent' => 0,
            'throughput_per_minute' => 0,
            'memory_usage' => [
                'total_bytes' => 0,
                'avg_bytes' => 0,
            ],
        ];

        $relevantMetrics = array_filter(
            $this->metrics,
            function ($metric) use ($type, $cutoff) {
                return $metric['type'] === $type &&
                       isset($metric['minute']) &&
                       $cutoff->lessThanOrEqualTo($metric['minute']);
            }
        );

        foreach ($relevantMetrics as $metric) {
            $aggregated['total_count'] += $metric['count'];
            $aggregated['total_duration_ms'] += $metric['total_duration_ms'];
            $aggregated['total_errors'] += $metric['errors'];
            $aggregated['memory_usage']['total_bytes'] += $metric['total_memory_bytes'];

            if ($aggregated['min_duration_ms'] === null || $metric['min_duration_ms'] < $aggregated['min_duration_ms']) {
                $aggregated['min_duration_ms'] = $metric['min_duration_ms'];
            }

            if ($aggregated['max_duration_ms'] === null || $metric['max_duration_ms'] > $aggregated['max_duration_ms']) {
                $aggregated['max_duration_ms'] = $metric['max_duration_ms'];
            }
        }

        if ($aggregated['total_count'] > 0) {
            $aggregated['avg_duration_ms'] = $aggregated['total_duration_ms'] / $aggregated['total_count'];
            $aggregated['error_rate_percent'] = ($aggregated['total_errors'] / $aggregated['total_count']) * 100;
            $aggregated['throughput_per_minute'] = $aggregated['total_count'] / $minutes;
            $aggregated['memory_usage']['avg_bytes'] = intval($aggregated['memory_usage']['total_bytes'] / $aggregated['total_count']);
        }

        return $aggregated;
    }

    /**
     * Get performance insights and alerts.
     */
    public function getPerformanceInsights(): array
    {
        $insights = [];
        $thresholds = config('modular-ddd.performance.monitoring.performance_thresholds', []);

        // Command processing insights
        $commandMetrics = $this->getAggregatedMetrics('command_processing', 30);
        if ($commandMetrics['avg_duration_ms'] > ($thresholds['command_processing_ms'] ?? 200)) {
            $insights[] = [
                'type' => 'warning',
                'category' => 'command_processing',
                'message' => "Command processing is slower than expected ({$commandMetrics['avg_duration_ms']}ms avg)",
                'recommendation' => 'Check for slow database queries or complex business logic',
                'timestamp' => now()->toISOString(),
            ];
        }

        // Query processing insights
        $queryMetrics = $this->getAggregatedMetrics('query_processing', 30);
        if ($queryMetrics['avg_duration_ms'] > ($thresholds['query_processing_ms'] ?? 100)) {
            $insights[] = [
                'type' => 'warning',
                'category' => 'query_processing',
                'message' => "Query processing is slower than expected ({$queryMetrics['avg_duration_ms']}ms avg)",
                'recommendation' => 'Consider optimizing queries or improving cache hit rates',
                'timestamp' => now()->toISOString(),
            ];
        }

        // Error rate insights
        if ($commandMetrics['error_rate_percent'] > 5) {
            $insights[] = [
                'type' => 'critical',
                'category' => 'error_rate',
                'message' => "High command error rate: {$commandMetrics['error_rate_percent']}%",
                'recommendation' => 'Investigate failing commands and fix underlying issues',
                'timestamp' => now()->toISOString(),
            ];
        }

        // Memory usage insights
        $currentMemoryMb = round(memory_get_usage(true) / 1024 / 1024, 2);
        $memoryThreshold = $thresholds['memory_usage_mb'] ?? 256;
        if ($currentMemoryMb > $memoryThreshold) {
            $insights[] = [
                'type' => 'warning',
                'category' => 'memory_usage',
                'message' => "High memory usage: {$currentMemoryMb}MB",
                'recommendation' => 'Check for memory leaks or consider increasing memory limits',
                'timestamp' => now()->toISOString(),
            ];
        }

        return $insights;
    }

    /**
     * Clear old metrics to prevent memory buildup.
     */
    public function cleanup(int $maxAgeMinutes = 1440): void // 24 hours default
    {
        $cutoff = now()->subMinutes($maxAgeMinutes);

        foreach ($this->metrics as $key => $metric) {
            if (isset($metric['minute']) && $cutoff->greaterThan($metric['minute'])) {
                unset($this->metrics[$key]);
            }
        }

        $this->persistMetrics();

        Log::info('Performance metrics cleanup completed', [
            'cutoff' => $cutoff->toISOString(),
            'remaining_metrics' => count($this->metrics),
        ]);
    }

    /**
     * Export metrics in Prometheus format.
     */
    public function exportPrometheusMetrics(): string
    {
        $output = [];

        foreach ($this->metrics as $metric) {
            $metricName = 'ddd_' . str_replace('-', '_', $metric['type']);

            $output[] = "# HELP {$metricName}_total Total number of operations";
            $output[] = "# TYPE {$metricName}_total counter";
            $output[] = "{$metricName}_total {$metric['count']}";

            if ($metric['avg_duration_ms'] > 0) {
                $output[] = "# HELP {$metricName}_duration_ms Average duration in milliseconds";
                $output[] = "# TYPE {$metricName}_duration_ms gauge";
                $output[] = "{$metricName}_duration_ms {$metric['avg_duration_ms']}";
            }

            if ($metric['errors'] > 0) {
                $output[] = "# HELP {$metricName}_errors_total Total number of errors";
                $output[] = "# TYPE {$metricName}_errors_total counter";
                $output[] = "{$metricName}_errors_total {$metric['errors']}";
            }
        }

        return implode("\n", $output);
    }

    private function initializeMetrics(): void
    {
        $this->loadPersistedMetrics();
    }

    private function persistMetrics(): void
    {
        $cacheKey = self::METRICS_CACHE_PREFIX . 'current';
        Cache::put($cacheKey, $this->metrics, now()->addSeconds(self::METRICS_CACHE_TTL));
    }

    private function loadPersistedMetrics(): void
    {
        $cacheKey = self::METRICS_CACHE_PREFIX . 'current';
        $persistedMetrics = Cache::get($cacheKey, []);

        if (is_array($persistedMetrics)) {
            $this->metrics = array_merge($this->metrics, $persistedMetrics);
        }
    }
}