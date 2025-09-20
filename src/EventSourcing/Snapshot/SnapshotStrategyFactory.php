<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Snapshot;

class SnapshotStrategyFactory
{
    /**
     * Create snapshot strategy from configuration
     */
    public static function create(array $config): SnapshotStrategyInterface
    {
        $strategy = $config['strategy'] ?? 'simple';

        return match ($strategy) {
            'simple' => new SimpleSnapshotStrategy(
                $config['threshold'] ?? 10
            ),
            'adaptive' => new AdaptiveSnapshotStrategy(
                $config['adaptive_config'] ?? []
            ),
            'time_based' => new TimeBasedSnapshotStrategy(
                $config['time_interval'] ?? 3600
            ),
            default => new SimpleSnapshotStrategy(10), // Safe fallback to PRD requirement
        };
    }

    /**
     * Get available strategies
     */
    public static function getAvailableStrategies(): array
    {
        return [
            'simple' => [
                'name' => 'Simple Event Count',
                'description' => 'Creates snapshot every N events (default: 10)',
                'class' => SimpleSnapshotStrategy::class,
                'config_keys' => ['threshold'],
            ],
            'adaptive' => [
                'name' => 'Adaptive Strategy',
                'description' => 'Smart snapshots based on multiple factors',
                'class' => AdaptiveSnapshotStrategy::class,
                'config_keys' => ['adaptive_config'],
            ],
            'time_based' => [
                'name' => 'Time Based',
                'description' => 'Creates snapshot based on time intervals',
                'class' => TimeBasedSnapshotStrategy::class,
                'config_keys' => ['time_interval'],
            ],
        ];
    }

    /**
     * Validate strategy configuration
     */
    public static function validateConfig(array $config): array
    {
        $errors = [];
        $strategy = $config['strategy'] ?? 'simple';

        switch ($strategy) {
            case 'simple':
                $threshold = $config['threshold'] ?? 10;
                if (!is_int($threshold) || $threshold < 1) {
                    $errors[] = 'threshold must be a positive integer';
                }
                // PRD compliance check
                if ($threshold !== 10) {
                    $errors[] = 'WARNING: PRD requires threshold=10 for compliance. Current: ' . $threshold;
                }
                break;

            case 'adaptive':
                if (!isset($config['adaptive_config']) || !is_array($config['adaptive_config'])) {
                    $errors[] = 'adaptive_config must be an array';
                }
                $errors[] = 'INFO: Using adaptive strategy - ensure this meets your PRD requirements';
                break;

            case 'time_based':
                $interval = $config['time_interval'] ?? 3600;
                if (!is_int($interval) || $interval < 60) {
                    $errors[] = 'time_interval must be at least 60 seconds';
                }
                $errors[] = 'INFO: Using time-based strategy - ensure this meets your PRD requirements';
                break;

            default:
                $errors[] = "Unknown snapshot strategy: {$strategy}";
        }

        return $errors;
    }
}