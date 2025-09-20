<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Snapshot;

use LaravelModularDDD\Core\Domain\Contracts\AggregateRootInterface;
use LaravelModularDDD\EventSourcing\Contracts\AggregateSnapshotInterface;
use Illuminate\Support\Facades\Cache;

class AdaptiveSnapshotStrategy implements SnapshotStrategyInterface
{
    private array $config;
    private array $metrics = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'event_count_threshold' => 50,
            'time_threshold_seconds' => 3600, // 1 hour
            'complexity_multiplier' => 1.0,
            'access_frequency_weight' => 0.3,
            'size_weight' => 0.2,
            'performance_weight' => 0.5,
            'min_threshold' => 10,
            'max_threshold' => 1000,
        ], $config);
    }

    public function shouldSnapshot(
        AggregateRootInterface $aggregate,
        ?AggregateSnapshotInterface $lastSnapshot = null
    ): bool {
        $aggregateId = $aggregate->getAggregateId()->toString();

        // Get current metrics for this aggregate
        $metrics = $this->getAggregateMetrics($aggregateId);

        // Calculate adaptive threshold
        $threshold = $this->calculateAdaptiveThreshold($aggregate, $metrics);

        // Check event count threshold
        if ($this->checkEventCountThreshold($aggregate, $lastSnapshot, $threshold)) {
            $this->recordSnapshotDecision($aggregateId, 'event_count', $threshold);
            return true;
        }

        // Check time threshold
        if ($this->checkTimeThreshold($lastSnapshot)) {
            $this->recordSnapshotDecision($aggregateId, 'time_threshold', $threshold);
            return true;
        }

        // Check performance degradation
        if ($this->checkPerformanceDegradation($aggregateId, $metrics)) {
            $this->recordSnapshotDecision($aggregateId, 'performance', $threshold);
            return true;
        }

        // Check access pattern changes
        if ($this->checkAccessPatternChange($aggregateId, $metrics)) {
            $this->recordSnapshotDecision($aggregateId, 'access_pattern', $threshold);
            return true;
        }

        return false;
    }

    public function getName(): string
    {
        return 'adaptive';
    }

    public function getConfiguration(): array
    {
        return $this->config;
    }

    public function updateFromMetrics(array $metrics): void
    {
        $this->metrics = array_merge($this->metrics, $metrics);

        // Adjust thresholds based on system performance
        $this->adjustThresholds($metrics);
    }

    private function calculateAdaptiveThreshold(
        AggregateRootInterface $aggregate,
        array $metrics
    ): int {
        $baseThreshold = $this->config['event_count_threshold'];

        // Factor in aggregate complexity
        $complexityScore = $this->calculateComplexityScore($aggregate);
        $complexityAdjustment = $baseThreshold / ($complexityScore * $this->config['complexity_multiplier']);

        // Factor in access frequency
        $accessFrequency = $metrics['access_frequency'] ?? 1.0;
        $accessAdjustment = $baseThreshold * (1 - ($accessFrequency * $this->config['access_frequency_weight']));

        // Factor in aggregate size
        $sizeScore = $this->calculateSizeScore($aggregate);
        $sizeAdjustment = $baseThreshold * (1 + ($sizeScore * $this->config['size_weight']));

        // Factor in recent performance
        $performanceScore = $metrics['performance_score'] ?? 1.0;
        $performanceAdjustment = $baseThreshold * (1 - ($performanceScore * $this->config['performance_weight']));

        // Combine all factors
        $adaptiveThreshold = (int) round(
            ($complexityAdjustment + $accessAdjustment + $sizeAdjustment + $performanceAdjustment) / 4
        );

        // Ensure within bounds
        return max(
            $this->config['min_threshold'],
            min($this->config['max_threshold'], $adaptiveThreshold)
        );
    }

    private function checkEventCountThreshold(
        AggregateRootInterface $aggregate,
        ?AggregateSnapshotInterface $lastSnapshot,
        int $threshold
    ): bool {
        if (!$lastSnapshot) {
            return $aggregate->getVersion() >= $threshold;
        }

        $eventsSinceSnapshot = $aggregate->getVersion() - $lastSnapshot->getVersion();
        return $eventsSinceSnapshot >= $threshold;
    }

    private function checkTimeThreshold(?AggregateSnapshotInterface $lastSnapshot): bool
    {
        if (!$lastSnapshot) {
            return false;
        }

        $timeSinceSnapshot = time() - $lastSnapshot->getCreatedAt()->getTimestamp();
        return $timeSinceSnapshot >= $this->config['time_threshold_seconds'];
    }

    private function checkPerformanceDegradation(string $aggregateId, array $metrics): bool
    {
        $recentLoadTimes = $metrics['recent_load_times'] ?? [];

        if (count($recentLoadTimes) < 3) {
            return false;
        }

        // Check if load times are consistently increasing
        $trend = $this->calculateTrend($recentLoadTimes);
        return $trend > 1.5; // 50% increase in load time
    }

    private function checkAccessPatternChange(string $aggregateId, array $metrics): bool
    {
        $accessPattern = $metrics['access_pattern'] ?? [];

        if (empty($accessPattern)) {
            return false;
        }

        // If access frequency has increased significantly, consider snapshotting
        $recentAccess = array_slice($accessPattern, -5);
        $historicalAccess = array_slice($accessPattern, 0, -5);

        if (empty($historicalAccess)) {
            return false;
        }

        $recentAverage = array_sum($recentAccess) / count($recentAccess);
        $historicalAverage = array_sum($historicalAccess) / count($historicalAccess);

        return $recentAverage > ($historicalAverage * 2); // 100% increase
    }

    private function calculateComplexityScore(AggregateRootInterface $aggregate): float
    {
        // Estimate complexity based on aggregate state size
        $stateSize = strlen(serialize($aggregate));
        $eventCount = $aggregate->getVersion();

        // Logarithmic scaling to prevent extreme values
        $sizeScore = log10($stateSize) / 6; // Normalize around 1MB = score of 1
        $eventScore = log10($eventCount + 1) / 3; // Normalize around 1000 events = score of 1

        return max(0.1, min(10.0, ($sizeScore + $eventScore) / 2));
    }

    private function calculateSizeScore(AggregateRootInterface $aggregate): float
    {
        $stateSize = strlen(serialize($aggregate));

        // Normalize size score (1MB = score of 1.0)
        return min(5.0, $stateSize / (1024 * 1024));
    }

    private function calculateTrend(array $values): float
    {
        if (count($values) < 2) {
            return 1.0;
        }

        $first = array_slice($values, 0, count($values) / 2);
        $second = array_slice($values, count($values) / 2);

        $firstAvg = array_sum($first) / count($first);
        $secondAvg = array_sum($second) / count($second);

        return $firstAvg > 0 ? $secondAvg / $firstAvg : 1.0;
    }

    private function getAggregateMetrics(string $aggregateId): array
    {
        $cacheKey = "aggregate_metrics:{$aggregateId}";

        return Cache::get($cacheKey, [
            'access_frequency' => 1.0,
            'recent_load_times' => [],
            'access_pattern' => [],
            'performance_score' => 1.0,
            'last_accessed' => time(),
        ]);
    }

    private function recordSnapshotDecision(
        string $aggregateId,
        string $reason,
        int $threshold
    ): void {
        $cacheKey = "snapshot_decisions:{$aggregateId}";

        $decisions = Cache::get($cacheKey, []);
        $decisions[] = [
            'timestamp' => time(),
            'reason' => $reason,
            'threshold' => $threshold,
        ];

        // Keep only last 10 decisions
        $decisions = array_slice($decisions, -10);

        Cache::put($cacheKey, $decisions, now()->addHours(24));
    }

    private function adjustThresholds(array $systemMetrics): void
    {
        // Adjust based on system performance
        $cpuUsage = $systemMetrics['cpu_usage'] ?? 50;
        $memoryUsage = $systemMetrics['memory_usage'] ?? 50;
        $diskIo = $systemMetrics['disk_io'] ?? 50;

        // If system is under high load, increase thresholds to reduce snapshot frequency
        if ($cpuUsage > 80 || $memoryUsage > 80 || $diskIo > 80) {
            $this->config['event_count_threshold'] = min(
                $this->config['max_threshold'],
                (int) ($this->config['event_count_threshold'] * 1.2)
            );
        }

        // If system performance is good, optimize for faster reconstruction
        if ($cpuUsage < 30 && $memoryUsage < 50 && $diskIo < 30) {
            $this->config['event_count_threshold'] = max(
                $this->config['min_threshold'],
                (int) ($this->config['event_count_threshold'] * 0.9)
            );
        }
    }
}