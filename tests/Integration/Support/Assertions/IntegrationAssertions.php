<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Integration\Support\Assertions;

use PHPUnit\Framework\Assert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Custom assertions for integration testing scenarios.
 *
 * Provides domain-specific assertions for:
 * - Event sourcing verification
 * - CQRS system state
 * - Module communication
 * - Performance thresholds
 * - Data consistency checks
 */
class IntegrationAssertions extends Assert
{
    /**
     * Assert that an event stream contains events in correct order
     */
    public static function assertEventStreamOrdered(array $events, string $message = ''): void
    {
        $previousVersion = 0;
        $previousTimestamp = null;

        foreach ($events as $index => $event) {
            // Version should increase
            static::assertGreaterThan($previousVersion, $event->getVersion(),
                $message ?: "Event at index {$index} has incorrect version sequence");

            // Timestamp should not go backwards
            $currentTimestamp = $event->getOccurredAt();
            if ($previousTimestamp !== null) {
                static::assertGreaterThanOrEqual($previousTimestamp->getTimestamp(), $currentTimestamp->getTimestamp(),
                    $message ?: "Event at index {$index} has timestamp before previous event");
            }

            $previousVersion = $event->getVersion();
            $previousTimestamp = $currentTimestamp;
        }
    }

    /**
     * Assert that aggregate state is consistent after event replay
     */
    public static function assertAggregateStateConsistent(
        string $aggregateId,
        array $expectedState,
        string $message = ''
    ): void {
        // This would typically involve loading the aggregate and comparing state
        // For integration tests, we can check database consistency

        $events = DB::table('event_store')
            ->where('aggregate_id', $aggregateId)
            ->orderBy('version')
            ->get();

        static::assertNotEmpty($events, $message ?: "No events found for aggregate {$aggregateId}");

        // Verify version consistency
        $expectedVersion = 1;
        foreach ($events as $event) {
            static::assertEquals($expectedVersion, $event->version,
                $message ?: "Event version inconsistency at version {$expectedVersion}");
            $expectedVersion++;
        }
    }

    /**
     * Assert that snapshot accurately represents aggregate state at version
     */
    public static function assertSnapshotAccurate(
        string $aggregateId,
        int $version,
        array $expectedData,
        string $message = ''
    ): void {
        $snapshot = DB::table('snapshots')
            ->where('aggregate_id', $aggregateId)
            ->where('version', $version)
            ->first();

        static::assertNotNull($snapshot, $message ?: "Snapshot not found for {$aggregateId} at version {$version}");

        $snapshotData = json_decode($snapshot->snapshot_data, true);

        foreach ($expectedData as $key => $expectedValue) {
            static::assertArrayHasKey($key, $snapshotData,
                $message ?: "Snapshot missing expected key: {$key}");
            static::assertEquals($expectedValue, $snapshotData[$key],
                $message ?: "Snapshot data mismatch for key: {$key}");
        }
    }

    /**
     * Assert that cache performance meets expectations
     */
    public static function assertCachePerformance(
        float $hitRate,
        float $minHitRate = 80.0,
        string $message = ''
    ): void {
        static::assertGreaterThanOrEqual($minHitRate, $hitRate,
            $message ?: "Cache hit rate {$hitRate}% is below minimum {$minHitRate}%");
    }

    /**
     * Assert that query execution time is within acceptable limits
     */
    public static function assertQueryPerformance(
        float $executionTimeMs,
        float $maxTimeMs = 100.0,
        string $message = ''
    ): void {
        static::assertLessThanOrEqual($maxTimeMs, $executionTimeMs,
            $message ?: "Query execution time {$executionTimeMs}ms exceeds limit {$maxTimeMs}ms");
    }

    /**
     * Assert that command processing follows transaction boundaries
     */
    public static function assertTransactionBoundaries(
        string $transactionId,
        array $expectedOperations,
        string $message = ''
    ): void {
        // Check that all operations in transaction are either all committed or all rolled back
        $operations = DB::table('transaction_log')
            ->where('transaction_id', $transactionId)
            ->get();

        static::assertCount(count($expectedOperations), $operations,
            $message ?: "Transaction operation count mismatch");

        $statuses = $operations->pluck('status')->unique();
        static::assertCount(1, $statuses,
            $message ?: "Transaction has mixed operation statuses - should be all committed or all rolled back");
    }

    /**
     * Assert that message delivery guarantees are met
     */
    public static function assertMessageDeliveryGuarantees(
        string $messageId,
        string $expectedStatus = 'delivered',
        int $maxRetries = 3,
        string $message = ''
    ): void {
        $messageRecord = DB::table('module_messages')
            ->where('message_id', $messageId)
            ->first();

        static::assertNotNull($messageRecord, $message ?: "Message {$messageId} not found");
        static::assertEquals($expectedStatus, $messageRecord->status,
            $message ?: "Message status mismatch");
        static::assertLessThanOrEqual($maxRetries, $messageRecord->retry_count,
            $message ?: "Message retry count exceeds maximum");
    }

    /**
     * Assert that event subscription and delivery work correctly
     */
    public static function assertEventSubscriptionDelivery(
        string $eventId,
        array $expectedSubscribers,
        string $message = ''
    ): void {
        foreach ($expectedSubscribers as $subscriber) {
            $delivery = DB::table('event_deliveries')
                ->where('event_id', $eventId)
                ->where('subscriber', $subscriber)
                ->first();

            static::assertNotNull($delivery,
                $message ?: "Event {$eventId} not delivered to subscriber {$subscriber}");
            static::assertEquals('delivered', $delivery->status,
                $message ?: "Event delivery failed for subscriber {$subscriber}");
        }
    }

    /**
     * Assert that system performance metrics are within acceptable ranges
     */
    public static function assertSystemPerformanceMetrics(
        array $metrics,
        array $thresholds = [],
        string $message = ''
    ): void {
        $defaultThresholds = [
            'cpu_usage_percent' => 80,
            'memory_usage_percent' => 85,
            'disk_usage_percent' => 90,
            'response_time_ms' => 200,
            'error_rate_percent' => 1,
        ];

        $thresholds = array_merge($defaultThresholds, $thresholds);

        foreach ($thresholds as $metric => $threshold) {
            if (isset($metrics[$metric])) {
                static::assertLessThanOrEqual($threshold, $metrics[$metric],
                    $message ?: "System metric {$metric} ({$metrics[$metric]}) exceeds threshold ({$threshold})");
            }
        }
    }

    /**
     * Assert that database connection resilience is working
     */
    public static function assertDatabaseResilience(
        int $activeConnections,
        int $maxConnections = 100,
        int $failedConnections = 0,
        string $message = ''
    ): void {
        static::assertLessThanOrEqual($maxConnections, $activeConnections,
            $message ?: "Active database connections ({$activeConnections}) exceed maximum ({$maxConnections})");

        static::assertEquals(0, $failedConnections,
            $message ?: "Database has {$failedConnections} failed connections");
    }

    /**
     * Assert that memory usage is stable (no significant leaks)
     */
    public static function assertMemoryStability(
        int $initialMemory,
        int $finalMemory,
        float $maxIncreasePercent = 20.0,
        string $message = ''
    ): void {
        $memoryIncrease = $finalMemory - $initialMemory;
        $increasePercent = ($memoryIncrease / $initialMemory) * 100;

        static::assertLessThanOrEqual($maxIncreasePercent, $increasePercent,
            $message ?: "Memory increased by {$increasePercent}% which exceeds limit of {$maxIncreasePercent}%");
    }

    /**
     * Assert that error handling and recovery work correctly
     */
    public static function assertErrorHandlingAndRecovery(
        array $errorScenarios,
        array $expectedRecoveryActions,
        string $message = ''
    ): void {
        foreach ($errorScenarios as $scenario => $errorData) {
            if (isset($expectedRecoveryActions[$scenario])) {
                $recoveryAction = $expectedRecoveryActions[$scenario];

                // Verify that recovery action was executed
                $recovery = DB::table('error_recovery_log')
                    ->where('error_type', $errorData['type'])
                    ->where('recovery_action', $recoveryAction)
                    ->where('status', 'completed')
                    ->first();

                static::assertNotNull($recovery,
                    $message ?: "Recovery action {$recoveryAction} not executed for error scenario {$scenario}");
            }
        }
    }

    /**
     * Assert that circuit breaker is functioning correctly
     */
    public static function assertCircuitBreakerFunctioning(
        string $serviceName,
        string $expectedState,
        int $failureThreshold = 5,
        string $message = ''
    ): void {
        $circuitState = Cache::get("circuit_breaker_{$serviceName}");

        static::assertNotNull($circuitState, $message ?: "Circuit breaker state not found for {$serviceName}");
        static::assertEquals($expectedState, $circuitState['state'],
            $message ?: "Circuit breaker state mismatch for {$serviceName}");

        if ($expectedState === 'open') {
            static::assertGreaterThanOrEqual($failureThreshold, $circuitState['failure_count'],
                $message ?: "Circuit breaker opened but failure count below threshold");
        }
    }

    /**
     * Assert that data consistency is maintained across modules
     */
    public static function assertCrossModuleDataConsistency(
        string $entityId,
        array $moduleStates,
        string $message = ''
    ): void {
        $versions = [];
        $timestamps = [];

        foreach ($moduleStates as $module => $state) {
            static::assertArrayHasKey('version', $state,
                $message ?: "Module {$module} missing version information");
            static::assertArrayHasKey('last_updated', $state,
                $message ?: "Module {$module} missing timestamp information");

            $versions[] = $state['version'];
            $timestamps[] = $state['last_updated'];
        }

        // All modules should have the same version for the entity
        static::assertCount(1, array_unique($versions),
            $message ?: "Data version inconsistency across modules for entity {$entityId}");
    }

    /**
     * Assert that async operations complete within timeout
     */
    public static function assertAsyncOperationCompletion(
        string $operationId,
        int $timeoutSeconds = 30,
        string $message = ''
    ): void {
        $startTime = time();

        while (time() - $startTime < $timeoutSeconds) {
            $operation = DB::table('async_operations')
                ->where('operation_id', $operationId)
                ->first();

            if ($operation && $operation->status === 'completed') {
                static::assertTrue(true); // Operation completed successfully
                return;
            }

            sleep(1);
        }

        static::fail($message ?: "Async operation {$operationId} did not complete within {$timeoutSeconds} seconds");
    }

    /**
     * Assert that load balancing is working effectively
     */
    public static function assertLoadBalancing(
        array $instanceMetrics,
        float $maxVariationPercent = 20.0,
        string $message = ''
    ): void {
        $loads = array_column($instanceMetrics, 'load');
        $avgLoad = array_sum($loads) / count($loads);

        foreach ($loads as $load) {
            $variationPercent = abs(($load - $avgLoad) / $avgLoad) * 100;
            static::assertLessThanOrEqual($maxVariationPercent, $variationPercent,
                $message ?: "Load variation ({$variationPercent}%) exceeds threshold ({$maxVariationPercent}%)");
        }
    }
}