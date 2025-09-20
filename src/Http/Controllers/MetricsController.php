<?php

declare(strict_types=1);

namespace LaravelModularDDD\Http\Controllers;

use LaravelModularDDD\Monitoring\PerformanceMetricsCollector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;

/**
 * MetricsController
 *
 * Provides detailed performance metrics and insights for monitoring systems.
 * Supports multiple output formats including JSON and Prometheus.
 */
final class MetricsController
{
    public function __construct(
        private readonly PerformanceMetricsCollector $metricsCollector
    ) {}

    /**
     * Get comprehensive system metrics.
     */
    public function index(Request $request): JsonResponse
    {
        $period = $request->get('period', 60); // minutes
        $type = $request->get('type');

        $metrics = [
            'timestamp' => now()->toISOString(),
            'period_minutes' => $period,
        ];

        if ($type) {
            $metrics['metrics'] = $this->metricsCollector->getAggregatedMetrics($type, $period);
        } else {
            $metrics['command_processing'] = $this->metricsCollector->getAggregatedMetrics('command_processing', $period);
            $metrics['query_processing'] = $this->metricsCollector->getAggregatedMetrics('query_processing', $period);
            $metrics['event_processing'] = $this->metricsCollector->getAggregatedMetrics('event_processing', $period);
            $metrics['projection_processing'] = $this->metricsCollector->getAggregatedMetrics('projection_processing', $period);
        }

        return response()->json($metrics);
    }

    /**
     * Get performance insights and recommendations.
     */
    public function insights(): JsonResponse
    {
        $insights = $this->metricsCollector->getPerformanceInsights();

        return response()->json([
            'timestamp' => now()->toISOString(),
            'insights_count' => count($insights),
            'insights' => $insights,
        ]);
    }

    /**
     * Get metrics in Prometheus format.
     */
    public function prometheus(): Response
    {
        $prometheusMetrics = $this->metricsCollector->exportPrometheusMetrics();

        return response($prometheusMetrics)
            ->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    }

    /**
     * Get real-time metrics.
     */
    public function realtime(): JsonResponse
    {
        return response()->json([
            'timestamp' => now()->toISOString(),
            'memory' => [
                'current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'limit' => ini_get('memory_limit'),
            ],
            'cpu' => [
                'load_average' => function_exists('sys_getloadavg') ? sys_getloadavg() : null,
            ],
            'system' => [
                'php_version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'os' => PHP_OS_FAMILY,
                'timezone' => date_default_timezone_get(),
            ],
            'laravel' => [
                'version' => app()->version(),
                'environment' => app()->environment(),
                'debug' => config('app.debug'),
            ],
        ]);
    }

    /**
     * Get specific metric type data.
     */
    public function show(string $type, Request $request): JsonResponse
    {
        $period = $request->get('period', 60);
        $detailed = $request->boolean('detailed', false);

        $metrics = $this->metricsCollector->getAggregatedMetrics($type, $period);

        if ($detailed) {
            $rawMetrics = $this->metricsCollector->getMetrics($type);
            $metrics['raw_data'] = array_slice($rawMetrics, -50); // Last 50 data points
        }

        return response()->json([
            'timestamp' => now()->toISOString(),
            'type' => $type,
            'period_minutes' => $period,
            'metrics' => $metrics,
        ]);
    }

    /**
     * Clear old metrics data.
     */
    public function cleanup(Request $request): JsonResponse
    {
        $maxAgeMinutes = $request->get('max_age_minutes', 1440); // 24 hours default

        $this->metricsCollector->cleanup($maxAgeMinutes);

        return response()->json([
            'message' => 'Metrics cleanup completed',
            'max_age_minutes' => $maxAgeMinutes,
            'timestamp' => now()->toISOString(),
        ]);
    }
}