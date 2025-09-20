<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Monitoring;

class InMemoryMetricsCollector implements MetricsCollectorInterface
{
    private array $counters = [];
    private array $gauges = [];
    private array $histograms = [];
    private array $commandMetrics = [];
    private array $queryMetrics = [];
    private array $sagaMetrics = [];
    private array $readModelMetrics = [];

    public function recordCommandExecution(
        string $commandType,
        float $executionTime,
        bool $successful,
        array $metadata = []
    ): void {
        $this->commandMetrics[] = [
            'type' => $commandType,
            'execution_time' => $executionTime,
            'successful' => $successful,
            'metadata' => $metadata,
            'timestamp' => microtime(true),
        ];

        // Update aggregate metrics
        $this->incrementCounter('commands.total', ['type' => $commandType]);
        $this->incrementCounter(
            $successful ? 'commands.successful' : 'commands.failed',
            ['type' => $commandType]
        );
        $this->histogram('commands.execution_time', $executionTime, ['type' => $commandType]);
    }

    public function recordQueryExecution(
        string $queryType,
        float $executionTime,
        bool $cacheHit,
        array $metadata = []
    ): void {
        $this->queryMetrics[] = [
            'type' => $queryType,
            'execution_time' => $executionTime,
            'cache_hit' => $cacheHit,
            'metadata' => $metadata,
            'timestamp' => microtime(true),
        ];

        // Update aggregate metrics
        $this->incrementCounter('queries.total', ['type' => $queryType]);
        $this->incrementCounter(
            $cacheHit ? 'queries.cache_hit' : 'queries.cache_miss',
            ['type' => $queryType]
        );
        $this->histogram('queries.execution_time', $executionTime, ['type' => $queryType]);
    }

    public function recordSagaMetrics(
        string $sagaType,
        string $state,
        float $duration,
        array $metadata = []
    ): void {
        $this->sagaMetrics[] = [
            'type' => $sagaType,
            'state' => $state,
            'duration' => $duration,
            'metadata' => $metadata,
            'timestamp' => microtime(true),
        ];

        // Update aggregate metrics
        $this->incrementCounter('sagas.state_changes', ['type' => $sagaType, 'state' => $state]);
        $this->histogram('sagas.duration', $duration, ['type' => $sagaType]);
    }

    public function recordReadModelGeneration(
        string $readModelType,
        int $eventsProcessed,
        float $generationTime,
        array $metadata = []
    ): void {
        $this->readModelMetrics[] = [
            'type' => $readModelType,
            'events_processed' => $eventsProcessed,
            'generation_time' => $generationTime,
            'metadata' => $metadata,
            'timestamp' => microtime(true),
        ];

        // Update aggregate metrics
        $this->incrementCounter('read_models.generated', ['type' => $readModelType]);
        $this->gauge('read_models.events_processed', $eventsProcessed, ['type' => $readModelType]);
        $this->histogram('read_models.generation_time', $generationTime, ['type' => $readModelType]);
    }

    public function incrementCounter(string $name, array $tags = []): void
    {
        $key = $this->buildMetricKey($name, $tags);
        $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;
    }

    public function gauge(string $name, float $value, array $tags = []): void
    {
        $key = $this->buildMetricKey($name, $tags);
        $this->gauges[$key] = $value;
    }

    public function histogram(string $name, float $value, array $tags = []): void
    {
        $key = $this->buildMetricKey($name, $tags);

        if (!isset($this->histograms[$key])) {
            $this->histograms[$key] = [
                'values' => [],
                'count' => 0,
                'sum' => 0,
                'min' => PHP_FLOAT_MAX,
                'max' => PHP_FLOAT_MIN,
            ];
        }

        $this->histograms[$key]['values'][] = $value;
        $this->histograms[$key]['count']++;
        $this->histograms[$key]['sum'] += $value;
        $this->histograms[$key]['min'] = min($this->histograms[$key]['min'], $value);
        $this->histograms[$key]['max'] = max($this->histograms[$key]['max'], $value);
    }

    public function getMetrics(): array
    {
        return [
            'counters' => $this->counters,
            'gauges' => $this->gauges,
            'histograms' => $this->computeHistogramStats(),
            'command_metrics' => $this->commandMetrics,
            'query_metrics' => $this->queryMetrics,
            'saga_metrics' => $this->sagaMetrics,
            'read_model_metrics' => $this->readModelMetrics,
            'summary' => $this->getSummaryMetrics(),
        ];
    }

    public function reset(): void
    {
        $this->counters = [];
        $this->gauges = [];
        $this->histograms = [];
        $this->commandMetrics = [];
        $this->queryMetrics = [];
        $this->sagaMetrics = [];
        $this->readModelMetrics = [];
    }

    private function buildMetricKey(string $name, array $tags): string
    {
        if (empty($tags)) {
            return $name;
        }

        $tagString = implode(',', array_map(
            fn($key, $value) => "{$key}={$value}",
            array_keys($tags),
            array_values($tags)
        ));

        return "{$name}[{$tagString}]";
    }

    private function computeHistogramStats(): array
    {
        $stats = [];

        foreach ($this->histograms as $key => $histogram) {
            $values = $histogram['values'];
            sort($values);

            $count = $histogram['count'];
            $stats[$key] = [
                'count' => $count,
                'sum' => $histogram['sum'],
                'min' => $histogram['min'],
                'max' => $histogram['max'],
                'avg' => $count > 0 ? $histogram['sum'] / $count : 0,
                'p50' => $this->percentile($values, 0.5),
                'p90' => $this->percentile($values, 0.9),
                'p95' => $this->percentile($values, 0.95),
                'p99' => $this->percentile($values, 0.99),
            ];
        }

        return $stats;
    }

    private function percentile(array $values, float $percentile): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0;
        }

        $index = ($percentile * ($count - 1));
        $lower = floor($index);
        $upper = ceil($index);

        if ($lower === $upper) {
            return $values[(int) $index];
        }

        $lowerValue = $values[(int) $lower];
        $upperValue = $values[(int) $upper];
        $weight = $index - $lower;

        return $lowerValue + ($upperValue - $lowerValue) * $weight;
    }

    private function getSummaryMetrics(): array
    {
        return [
            'total_commands' => count($this->commandMetrics),
            'total_queries' => count($this->queryMetrics),
            'total_sagas' => count($this->sagaMetrics),
            'total_read_models' => count($this->readModelMetrics),
            'command_success_rate' => $this->calculateSuccessRate($this->commandMetrics),
            'average_command_time' => $this->calculateAverageTime($this->commandMetrics),
            'average_query_time' => $this->calculateAverageTime($this->queryMetrics),
            'cache_hit_rate' => $this->calculateCacheHitRate(),
        ];
    }

    private function calculateSuccessRate(array $metrics): float
    {
        if (empty($metrics)) {
            return 0;
        }

        $successful = array_filter($metrics, fn($m) => $m['successful'] ?? false);
        return (count($successful) / count($metrics)) * 100;
    }

    private function calculateAverageTime(array $metrics): float
    {
        if (empty($metrics)) {
            return 0;
        }

        $totalTime = array_sum(array_column($metrics, 'execution_time'));
        return $totalTime / count($metrics);
    }

    private function calculateCacheHitRate(): float
    {
        if (empty($this->queryMetrics)) {
            return 0;
        }

        $cacheHits = array_filter($this->queryMetrics, fn($m) => $m['cache_hit'] ?? false);
        return (count($cacheHits) / count($this->queryMetrics)) * 100;
    }
}