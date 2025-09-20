<?php

declare(strict_types=1);

namespace LaravelModularDDD\Console;

use Illuminate\Console\Command;
use LaravelModularDDD\EventSourcing\Contracts\EventStoreInterface;
use LaravelModularDDD\Core\Infrastructure\Repository\EventSourcedAggregateRepository;
use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

class StressTestCommand extends Command
{
    protected $signature = 'ddd:stress-test
                           {--duration=60 : Test duration in seconds}
                           {--target-throughput=10000 : Target events per second}
                           {--batch-size=100 : Events per batch}
                           {--memory-limit=256 : Memory limit in MB}
                           {--report-interval=5 : Report interval in seconds}';

    protected $description = 'Run stress tests to validate system under sustained load';

    private array $metrics = [];
    private int $totalEvents = 0;
    private float $startTime;

    public function handle(): void
    {
        $this->info('🚀 DDD Stress Test - Sustained Load Validation');
        $this->line('===============================================');

        $duration = (int) $this->option('duration');
        $targetThroughput = (int) $this->option('target-throughput');
        $batchSize = (int) $this->option('batch-size');
        $memoryLimit = (int) $this->option('memory-limit');
        $reportInterval = (int) $this->option('report-interval');

        $this->displayTestParameters($duration, $targetThroughput, $batchSize, $memoryLimit);

        if (!$this->confirm('Start stress test? This will generate significant load on the system.')) {
            $this->info('Stress test cancelled.');
            return;
        }

        $this->runStressTest($duration, $targetThroughput, $batchSize, $memoryLimit, $reportInterval);
        $this->generateReport();
    }

    private function displayTestParameters(int $duration, int $targetThroughput, int $batchSize, int $memoryLimit): void
    {
        $this->newLine();
        $this->line("⏱️  Duration: {$duration} seconds");
        $this->line("🎯 Target: {$targetThroughput} events/second");
        $this->line("📦 Batch Size: {$batchSize} events");
        $this->line("💾 Memory Limit: {$memoryLimit} MB");
        $this->line("📊 Expected Total Events: " . number_format($duration * $targetThroughput));
        $this->newLine();
    }

    private function runStressTest(int $duration, int $targetThroughput, int $batchSize, int $memoryLimit, int $reportInterval): void
    {
        $this->startTime = microtime(true);
        $lastReportTime = $this->startTime;
        $lastEventCount = 0;

        $eventStore = app(EventStoreInterface::class);
        $repository = app(EventSourcedAggregateRepository::class);

        $this->info('🏃 Starting stress test...');
        $this->newLine();

        $aggregateCounter = 0;
        $batchCounter = 0;

        while ((microtime(true) - $this->startTime) < $duration) {
            $batchStartTime = microtime(true);
            $batchStartMemory = memory_get_usage(true);

            // Create a new aggregate for every 1000 events to test snapshot creation
            if ($this->totalEvents % 1000 === 0) {
                $aggregateCounter++;
            }

            $aggregateId = $this->createStressTestAggregateId("stress-{$aggregateCounter}");
            $events = $this->createEventBatch($aggregateId, $batchSize, $batchCounter);

            try {
                $eventStore->append($aggregateId, $events);
                $this->totalEvents += count($events);
                $batchCounter++;

                $batchTime = microtime(true) - $batchStartTime;
                $batchMemory = memory_get_usage(true) - $batchStartMemory;

                $this->recordBatchMetrics($batchTime, $batchMemory, count($events));

                // Check memory usage
                $currentMemoryMB = memory_get_usage(true) / 1024 / 1024;
                if ($currentMemoryMB > $memoryLimit) {
                    $this->error("❌ Memory limit exceeded: {$currentMemoryMB}MB > {$memoryLimit}MB");
                    break;
                }

                // Periodic reporting
                $currentTime = microtime(true);
                if (($currentTime - $lastReportTime) >= $reportInterval) {
                    $this->reportProgress($currentTime, $lastReportTime, $lastEventCount, $targetThroughput);
                    $lastReportTime = $currentTime;
                    $lastEventCount = $this->totalEvents;
                }

                // Brief pause to prevent overwhelming the system
                usleep(1000); // 1ms pause

            } catch (\Exception $e) {
                $this->error("❌ Batch failed: " . $e->getMessage());
                $this->recordFailure($e);
            }
        }

        $this->info('✅ Stress test completed');
        $this->newLine();
    }

    private function createEventBatch(AggregateIdInterface $aggregateId, int $batchSize, int $batchNumber): array
    {
        $events = [];
        for ($i = 0; $i < $batchSize; $i++) {
            $events[] = $this->createStressTestEvent($aggregateId, $batchNumber, $i);
        }
        return $events;
    }

    private function recordBatchMetrics(float $batchTime, int $batchMemory, int $eventCount): void
    {
        $this->metrics[] = [
            'timestamp' => microtime(true),
            'batch_time_ms' => $batchTime * 1000,
            'batch_memory_bytes' => $batchMemory,
            'events_in_batch' => $eventCount,
            'events_per_second' => $eventCount / $batchTime,
            'total_memory_mb' => memory_get_usage(true) / 1024 / 1024,
            'peak_memory_mb' => memory_get_peak_usage(true) / 1024 / 1024,
        ];
    }

    private function recordFailure(\Exception $e): void
    {
        $this->metrics[] = [
            'timestamp' => microtime(true),
            'error' => true,
            'error_message' => $e->getMessage(),
            'error_type' => get_class($e),
        ];
    }

    private function reportProgress(float $currentTime, float $lastReportTime, int $lastEventCount, int $targetThroughput): void
    {
        $elapsedTime = $currentTime - $this->startTime;
        $intervalTime = $currentTime - $lastReportTime;
        $intervalEvents = $this->totalEvents - $lastEventCount;

        $overallThroughput = $this->totalEvents / $elapsedTime;
        $intervalThroughput = $intervalEvents / $intervalTime;
        $currentMemoryMB = memory_get_usage(true) / 1024 / 1024;

        $overallStatus = $overallThroughput >= $targetThroughput ? '✅' : '⚠️';
        $intervalStatus = $intervalThroughput >= $targetThroughput ? '✅' : '⚠️';

        $this->line(sprintf(
            '%s %.1fs | Events: %s | Overall: %s eps %s | Interval: %s eps %s | Memory: %.1f MB',
            $overallStatus,
            $elapsedTime,
            number_format($this->totalEvents),
            number_format($overallThroughput, 0),
            $overallStatus,
            number_format($intervalThroughput, 0),
            $intervalStatus,
            $currentMemoryMB
        ));
    }

    private function generateReport(): void
    {
        $totalTime = microtime(true) - $this->startTime;
        $overallThroughput = $this->totalEvents / $totalTime;

        $this->info('📊 Stress Test Report');
        $this->line('====================');

        // Overall metrics
        $this->line("📈 Total Events: " . number_format($this->totalEvents));
        $this->line("⏱️  Total Time: " . number_format($totalTime, 2) . " seconds");
        $this->line("⚡ Average Throughput: " . number_format($overallThroughput, 0) . " events/sec");

        // Memory metrics
        $peakMemoryMB = memory_get_peak_usage(true) / 1024 / 1024;
        $finalMemoryMB = memory_get_usage(true) / 1024 / 1024;
        $this->line("💾 Peak Memory: " . number_format($peakMemoryMB, 2) . " MB");
        $this->line("💾 Final Memory: " . number_format($finalMemoryMB, 2) . " MB");

        // Performance analysis
        $nonErrorMetrics = array_filter($this->metrics, fn($m) => !isset($m['error']));

        if (!empty($nonErrorMetrics)) {
            $avgBatchTime = array_sum(array_column($nonErrorMetrics, 'batch_time_ms')) / count($nonErrorMetrics);
            $maxBatchTime = max(array_column($nonErrorMetrics, 'batch_time_ms'));
            $minBatchTime = min(array_column($nonErrorMetrics, 'batch_time_ms'));

            $this->newLine();
            $this->line("📊 Batch Performance:");
            $this->line("   Average: " . number_format($avgBatchTime, 2) . "ms");
            $this->line("   Maximum: " . number_format($maxBatchTime, 2) . "ms");
            $this->line("   Minimum: " . number_format($minBatchTime, 2) . "ms");

            // Throughput distribution
            $throughputs = array_column($nonErrorMetrics, 'events_per_second');
            $avgThroughput = array_sum($throughputs) / count($throughputs);
            $maxThroughput = max($throughputs);
            $minThroughput = min($throughputs);

            $this->line("⚡ Throughput Distribution:");
            $this->line("   Average: " . number_format($avgThroughput, 0) . " events/sec");
            $this->line("   Maximum: " . number_format($maxThroughput, 0) . " events/sec");
            $this->line("   Minimum: " . number_format($minThroughput, 0) . " events/sec");
        }

        // Error analysis
        $errorMetrics = array_filter($this->metrics, fn($m) => isset($m['error']));
        if (!empty($errorMetrics)) {
            $this->newLine();
            $this->error("❌ Errors encountered: " . count($errorMetrics));
            foreach ($errorMetrics as $error) {
                $this->line("   - " . $error['error_type'] . ": " . $error['error_message']);
            }
        }

        // PRD validation
        $this->newLine();
        $this->validateStressTestResults($overallThroughput, $peakMemoryMB);
    }

    private function validateStressTestResults(float $throughput, float $peakMemoryMB): void
    {
        $this->info('🎯 PRD Validation Results');
        $this->line('=========================');

        $targetThroughput = (int) $this->option('target-throughput');
        $memoryLimit = (int) $this->option('memory-limit');

        // Throughput validation
        $throughputStatus = $throughput >= $targetThroughput ? '✅' : '❌';
        $this->line("{$throughputStatus} Sustained Throughput: " . number_format($throughput, 0) . " events/sec (target: {$targetThroughput})");

        // Memory validation
        $memoryStatus = $peakMemoryMB <= $memoryLimit ? '✅' : '❌';
        $this->line("{$memoryStatus} Memory Usage: " . number_format($peakMemoryMB, 2) . " MB (limit: {$memoryLimit} MB)");

        // Stability validation (no errors)
        $errorCount = count(array_filter($this->metrics, fn($m) => isset($m['error'])));
        $stabilityStatus = $errorCount === 0 ? '✅' : '❌';
        $this->line("{$stabilityStatus} System Stability: {$errorCount} errors during test");

        // Overall assessment
        $allPassed = $throughput >= $targetThroughput && $peakMemoryMB <= $memoryLimit && $errorCount === 0;

        $this->newLine();
        if ($allPassed) {
            $this->info('🎉 All stress test requirements passed! System is production-ready.');
        } else {
            $this->error('⚠️  Some stress test requirements failed. System needs optimization.');
        }
    }

    private function createStressTestAggregateId(string $id): AggregateIdInterface
    {
        return new class($id) implements AggregateIdInterface {
            public function __construct(private string $id) {}
            public function toString(): string { return $this->id; }
            public function equals(AggregateIdInterface $other): bool { return $this->id === $other->toString(); }
        };
    }

    private function createStressTestEvent(AggregateIdInterface $aggregateId, int $batchNumber, int $eventNumber): DomainEventInterface
    {
        return new class($aggregateId, $batchNumber, $eventNumber) implements DomainEventInterface {
            public function __construct(
                private AggregateIdInterface $aggregateId,
                private int $batchNumber,
                private int $eventNumber
            ) {}

            public function getAggregateId(): string { return $this->aggregateId->toString(); }
            public function getEventId(): string { return Str::uuid()->toString(); }
            public function getEventType(): string { return 'stress.test.event'; }
            public function getEventVersion(): int { return 1; }
            public function getOccurredAt(): \DateTimeImmutable { return new \DateTimeImmutable(); }
            public function toArray(): array {
                return [
                    'batch_number' => $this->batchNumber,
                    'event_number' => $this->eventNumber,
                    'payload' => str_repeat('data', 10), // Small payload
                ];
            }
        };
    }
}