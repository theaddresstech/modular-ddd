<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Monitoring;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\CQRS\Contracts\QueryInterface;
use Illuminate\Support\Facades\Log;

class PerformanceMonitor
{
    private array $activeOperations = [];
    private array $performanceThresholds = [];

    public function __construct(
        private readonly MetricsCollectorInterface $metricsCollector,
        array $defaultThresholds = []
    ) {
        $this->performanceThresholds = array_merge([
            'command_slow_threshold_ms' => 1000,
            'query_slow_threshold_ms' => 500,
            'saga_timeout_threshold_ms' => 30000,
            'read_model_slow_threshold_ms' => 2000,
        ], $defaultThresholds);
    }

    /**
     * Start monitoring command execution
     */
    public function startCommandExecution(CommandInterface $command): string
    {
        $operationId = $this->generateOperationId();
        $this->activeOperations[$operationId] = [
            'type' => 'command',
            'class' => get_class($command),
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true),
        ];

        return $operationId;
    }

    /**
     * End command execution monitoring
     */
    public function endCommandExecution(string $operationId, bool $successful, array $metadata = []): void
    {
        if (!isset($this->activeOperations[$operationId])) {
            return;
        }

        $operation = $this->activeOperations[$operationId];
        $executionTime = (microtime(true) - $operation['start_time']) * 1000; // Convert to ms
        $memoryUsed = memory_get_usage(true) - $operation['memory_start'];

        // Record metrics
        $this->metricsCollector->recordCommandExecution(
            $operation['class'],
            $executionTime,
            $successful,
            array_merge($metadata, ['memory_used' => $memoryUsed])
        );

        // Check for slow operations
        if ($executionTime > $this->performanceThresholds['command_slow_threshold_ms']) {
            Log::warning('Slow command execution detected', [
                'command_type' => $operation['class'],
                'execution_time_ms' => $executionTime,
                'threshold_ms' => $this->performanceThresholds['command_slow_threshold_ms'],
                'memory_used' => $memoryUsed,
            ]);
        }

        unset($this->activeOperations[$operationId]);
    }

    /**
     * Start monitoring query execution
     */
    public function startQueryExecution(QueryInterface $query): string
    {
        $operationId = $this->generateOperationId();
        $this->activeOperations[$operationId] = [
            'type' => 'query',
            'class' => get_class($query),
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true),
        ];

        return $operationId;
    }

    /**
     * End query execution monitoring
     */
    public function endQueryExecution(string $operationId, bool $cacheHit, array $metadata = []): void
    {
        if (!isset($this->activeOperations[$operationId])) {
            return;
        }

        $operation = $this->activeOperations[$operationId];
        $executionTime = (microtime(true) - $operation['start_time']) * 1000; // Convert to ms
        $memoryUsed = memory_get_usage(true) - $operation['memory_start'];

        // Record metrics
        $this->metricsCollector->recordQueryExecution(
            $operation['class'],
            $executionTime,
            $cacheHit,
            array_merge($metadata, ['memory_used' => $memoryUsed])
        );

        // Check for slow operations
        if ($executionTime > $this->performanceThresholds['query_slow_threshold_ms']) {
            Log::warning('Slow query execution detected', [
                'query_type' => $operation['class'],
                'execution_time_ms' => $executionTime,
                'threshold_ms' => $this->performanceThresholds['query_slow_threshold_ms'],
                'cache_hit' => $cacheHit,
                'memory_used' => $memoryUsed,
            ]);
        }

        unset($this->activeOperations[$operationId]);
    }

    /**
     * Monitor saga execution
     */
    public function monitorSagaExecution(string $sagaType, string $state, float $duration, array $metadata = []): void
    {
        $this->metricsCollector->recordSagaMetrics($sagaType, $state, $duration, $metadata);

        // Check for timeout
        if ($duration > $this->performanceThresholds['saga_timeout_threshold_ms']) {
            Log::warning('Saga execution timeout detected', [
                'saga_type' => $sagaType,
                'state' => $state,
                'duration_ms' => $duration,
                'threshold_ms' => $this->performanceThresholds['saga_timeout_threshold_ms'],
            ]);
        }
    }

    /**
     * Monitor read model generation
     */
    public function monitorReadModelGeneration(
        string $readModelType,
        int $eventsProcessed,
        float $generationTime,
        array $metadata = []
    ): void {
        $this->metricsCollector->recordReadModelGeneration(
            $readModelType,
            $eventsProcessed,
            $generationTime,
            $metadata
        );

        // Check for slow generation
        if ($generationTime > $this->performanceThresholds['read_model_slow_threshold_ms']) {
            Log::warning('Slow read model generation detected', [
                'read_model_type' => $readModelType,
                'events_processed' => $eventsProcessed,
                'generation_time_ms' => $generationTime,
                'threshold_ms' => $this->performanceThresholds['read_model_slow_threshold_ms'],
            ]);
        }
    }

    /**
     * Get performance statistics
     */
    public function getPerformanceStatistics(): array
    {
        $metrics = $this->metricsCollector->getMetrics();

        return [
            'active_operations' => count($this->activeOperations),
            'performance_thresholds' => $this->performanceThresholds,
            'metrics' => $metrics,
            'performance_analysis' => $this->analyzePerformance($metrics),
        ];
    }

    /**
     * Get active operations
     */
    public function getActiveOperations(): array
    {
        $now = microtime(true);
        $activeOps = [];

        foreach ($this->activeOperations as $id => $operation) {
            $activeOps[$id] = array_merge($operation, [
                'duration_ms' => ($now - $operation['start_time']) * 1000,
            ]);
        }

        return $activeOps;
    }

    /**
     * Set performance threshold
     */
    public function setThreshold(string $threshold, float $value): void
    {
        $this->performanceThresholds[$threshold] = $value;
    }

    /**
     * Check for performance issues
     */
    public function checkPerformanceIssues(): array
    {
        $issues = [];
        $metrics = $this->metricsCollector->getMetrics();

        // Check for long-running operations
        foreach ($this->activeOperations as $id => $operation) {
            $duration = (microtime(true) - $operation['start_time']) * 1000;
            $threshold = $operation['type'] === 'command'
                ? $this->performanceThresholds['command_slow_threshold_ms']
                : $this->performanceThresholds['query_slow_threshold_ms'];

            if ($duration > $threshold) {
                $issues[] = [
                    'type' => 'long_running_operation',
                    'operation_id' => $id,
                    'operation_type' => $operation['type'],
                    'class' => $operation['class'],
                    'duration_ms' => $duration,
                    'threshold_ms' => $threshold,
                ];
            }
        }

        // Check success rates
        $summary = $metrics['summary'] ?? [];
        if (($summary['command_success_rate'] ?? 100) < 95) {
            $issues[] = [
                'type' => 'low_success_rate',
                'success_rate' => $summary['command_success_rate'],
                'threshold' => 95,
            ];
        }

        return $issues;
    }

    private function generateOperationId(): string
    {
        return uniqid('op_', true);
    }

    private function analyzePerformance(array $metrics): array
    {
        $analysis = [];

        // Analyze command performance
        if (!empty($metrics['histograms'])) {
            foreach ($metrics['histograms'] as $key => $histogram) {
                if (str_contains($key, 'commands.execution_time')) {
                    $analysis['commands'][] = [
                        'metric' => $key,
                        'avg_time' => $histogram['avg'],
                        'p95_time' => $histogram['p95'],
                        'performance_grade' => $this->gradePerformance($histogram['p95'], $this->performanceThresholds['command_slow_threshold_ms']),
                    ];
                } elseif (str_contains($key, 'queries.execution_time')) {
                    $analysis['queries'][] = [
                        'metric' => $key,
                        'avg_time' => $histogram['avg'],
                        'p95_time' => $histogram['p95'],
                        'performance_grade' => $this->gradePerformance($histogram['p95'], $this->performanceThresholds['query_slow_threshold_ms']),
                    ];
                }
            }
        }

        return $analysis;
    }

    private function gradePerformance(float $actualTime, float $threshold): string
    {
        $ratio = $actualTime / $threshold;

        return match (true) {
            $ratio <= 0.5 => 'A',
            $ratio <= 0.75 => 'B',
            $ratio <= 1.0 => 'C',
            $ratio <= 1.5 => 'D',
            default => 'F',
        };
    }
}