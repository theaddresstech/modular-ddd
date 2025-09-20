<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Monitoring;

interface MetricsCollectorInterface
{
    /**
     * Record command execution metrics
     */
    public function recordCommandExecution(
        string $commandType,
        float $executionTime,
        bool $successful,
        array $metadata = []
    ): void;

    /**
     * Record query execution metrics
     */
    public function recordQueryExecution(
        string $queryType,
        float $executionTime,
        bool $cacheHit,
        array $metadata = []
    ): void;

    /**
     * Record saga metrics
     */
    public function recordSagaMetrics(
        string $sagaType,
        string $state,
        float $duration,
        array $metadata = []
    ): void;

    /**
     * Record read model generation metrics
     */
    public function recordReadModelGeneration(
        string $readModelType,
        int $eventsProcessed,
        float $generationTime,
        array $metadata = []
    ): void;

    /**
     * Increment counter metric
     */
    public function incrementCounter(string $name, array $tags = []): void;

    /**
     * Record gauge metric
     */
    public function gauge(string $name, float $value, array $tags = []): void;

    /**
     * Record histogram metric
     */
    public function histogram(string $name, float $value, array $tags = []): void;

    /**
     * Get collected metrics
     */
    public function getMetrics(): array;

    /**
     * Reset metrics
     */
    public function reset(): void;
}