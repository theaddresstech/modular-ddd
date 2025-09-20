# Snapshot Strategy Documentation

## Overview

This document explains the snapshot strategy implementation in the Laravel Modular DDD package, with emphasis on PRD compliance requirements.

## PRD Requirement

**CRITICAL**: The system must automatically create snapshots **every 10 events** for performance optimization.

## Default Configuration (PRD Compliant)

The system is configured by default to meet PRD requirements:

```php
// config/modular-ddd.php
'event_sourcing' => [
    'snapshots' => [
        'enabled' => true,
        'strategy' => 'simple',        // PRD compliant strategy
        'threshold' => 10,             // PRD requirement: exactly 10 events
    ],
],
```

## Available Strategies

### 1. SimpleSnapshotStrategy (PRD Compliant - DEFAULT)

**This is the default and PRD-compliant strategy.**

```php
use LaravelModularDDD\EventSourcing\Snapshot\SimpleSnapshotStrategy;

// PRD compliant configuration
$strategy = new SimpleSnapshotStrategy(10);
```

**Behavior:**
- Creates snapshot after exactly every 10 events
- Predictable and consistent performance
- Meets PRD requirements
- Recommended for production use

**Configuration:**
```php
'snapshots' => [
    'strategy' => 'simple',
    'threshold' => 10,  // MUST be 10 for PRD compliance
]
```

### 2. AdaptiveSnapshotStrategy (Advanced/Optional)

**Use only if you understand the implications and have specific performance requirements.**

```php
use LaravelModularDDD\EventSourcing\Snapshot\AdaptiveSnapshotStrategy;

$strategy = new AdaptiveSnapshotStrategy([
    'event_count_threshold' => 50,
    'time_threshold_seconds' => 3600,
    'complexity_multiplier' => 1.0,
    // ... other options
]);
```

**Behavior:**
- Smart snapshot creation based on multiple factors
- May not create snapshots every 10 events
- **WARNING**: May not meet PRD requirements

**Configuration:**
```php
'snapshots' => [
    'strategy' => 'adaptive',
    'adaptive_config' => [
        'event_count_threshold' => 50,
        'time_threshold_seconds' => 3600,
        // ... other settings
    ]
]
```

### 3. TimeBasedSnapshotStrategy (Advanced/Optional)

**Creates snapshots based on time intervals rather than event count.**

```php
use LaravelModularDDD\EventSourcing\Snapshot\TimeBasedSnapshotStrategy;

$strategy = new TimeBasedSnapshotStrategy(3600); // 1 hour
```

**Behavior:**
- Creates snapshots based on time intervals
- **WARNING**: Does not guarantee snapshots every 10 events
- **WARNING**: May not meet PRD requirements

## Usage

### Automatic Snapshot Creation (Recommended)

The `EventSourcedAggregateRepository` automatically handles PRD compliance:

```php
use LaravelModularDDD\Core\Infrastructure\Repository\EventSourcedAggregateRepository;

// Repository automatically creates snapshots per PRD requirement
$repository = app(EventSourcedAggregateRepository::class);

// Add events to aggregate
$aggregate = $repository->load($aggregateId, MyAggregate::class);
for ($i = 1; $i <= 10; $i++) {
    $aggregate->doSomething();
}

// Save - will automatically create snapshot at version 10
$repository->save($aggregate);
```

### PRD Compliance Checking

```php
// Check if current configuration is PRD compliant
$isCompliant = $repository->isPrdCompliant();

// Get detailed compliance report
$report = $repository->getPrdComplianceReport();
echo $report['compliance_status']; // 'COMPLIANT' or 'NON-COMPLIANT'
```

### Manual Snapshot Creation

```php
// Force create a snapshot (bypasses strategy)
$repository->createSnapshot($aggregate);
```

## Architecture

### Key Components

1. **SnapshotStrategyInterface**: Defines snapshot decision logic
2. **SnapshotStrategyFactory**: Creates strategies from configuration
3. **EventSourcedAggregateRepository**: Enforces PRD compliance
4. **SnapshotStore**: Manages snapshot persistence

### Flow Diagram

```
Event Append → Strategy Check → PRD Compliance → Snapshot Creation
     ↓              ↓               ↓                 ↓
Save Events → shouldSnapshot() → Every 10? → Save Snapshot
```

## PRD Compliance Verification

### Testing PRD Compliance

```php
use Tests\Integration\SnapshotComplianceTest;

// Run the PRD compliance test
php artisan test Tests\\Integration\\SnapshotComplianceTest::it_automatically_creates_snapshot_exactly_every_10_events
```

### Key PRD Compliance Points

1. ✅ **Snapshot created at event 10, 20, 30, etc.**
2. ✅ **No snapshot created at events 9, 19, 29, etc.**
3. ✅ **Automatic creation without manual intervention**
4. ✅ **Consistent behavior across all aggregates**

## Migration Guide

### From Non-Compliant Configuration

If your current configuration doesn't meet PRD requirements:

**Before (Non-compliant):**
```php
'snapshots' => [
    'strategy' => 'adaptive',
    // or
    'strategy' => 'simple',
    'threshold' => 15,  // Wrong threshold
]
```

**After (PRD-compliant):**
```php
'snapshots' => [
    'strategy' => 'simple',     // Use simple strategy
    'threshold' => 10,          // Exactly 10 events
]
```

### Environment Configuration

Set these environment variables for PRD compliance:

```bash
# .env
SNAPSHOT_STRATEGY=simple
SNAPSHOT_THRESHOLD=10
```

## Performance Impact

### With PRD Snapshots (Every 10 Events)

- **Event Loading**: O(10) events maximum to load
- **Memory Usage**: Reduced by ~90%
- **Query Performance**: Consistent regardless of aggregate age
- **Storage**: Increased by ~10% for snapshot storage

### Without Snapshots

- **Event Loading**: O(n) where n = total events
- **Memory Usage**: Grows linearly with events
- **Query Performance**: Degrades over time
- **Storage**: Lower storage usage but poor performance

## Monitoring

### Snapshot Statistics

```php
$stats = $repository->getStatistics();

print_r($stats);
/*
Array (
    [strategy] => simple
    [strategy_config] => Array (
        [event_threshold] => 10
    )
    [prd_compliant] => true
    [snapshot_stats] => Array (
        [total_snapshots] => 150
        [unique_aggregates] => 50
        [avg_snapshot_size_bytes] => 2048
        [oldest_snapshot] => 2024-01-01 10:00:00
        [newest_snapshot] => 2024-01-15 16:30:00
    )
)
*/
```

### Logging

The system logs snapshot creation:

```
[INFO] PRD Compliance: Automatic snapshot created
{
    "aggregate_id": "user-123",
    "version": 10,
    "strategy": "simple",
    "prd_requirement": "Every 10 events snapshot creation enforced"
}
```

## Troubleshooting

### Common Issues

**Q: Snapshots not being created**
A: Check that you're using `EventSourcedAggregateRepository` and not direct event store access.

**Q: Performance still slow after 10 events**
A: Verify PRD compliance with `isPrdCompliant()` method.

**Q: Too many snapshots being created**
A: Check if threshold is set below 10 - this violates PRD requirements.

### Validation Commands

```bash
# Check current strategy configuration
php artisan config:show modular-ddd.event_sourcing.snapshots.strategy

# Run PRD compliance test
php artisan test --filter="PRD"
```

## Best Practices

### DO ✅

1. Use `SimpleSnapshotStrategy` with threshold=10 for PRD compliance
2. Use `EventSourcedAggregateRepository` for automatic snapshot management
3. Monitor snapshot statistics regularly
4. Test PRD compliance in CI/CD pipeline

### DON'T ❌

1. Change threshold from 10 without understanding PRD implications
2. Use adaptive/time-based strategies without compliance verification
3. Bypass the repository and access event store directly
4. Disable snapshots in production

## Advanced Configuration

### Custom Strategy (Advanced Users)

Only implement if you have specific requirements and understand PRD implications:

```php
class CustomSnapshotStrategy implements SnapshotStrategyInterface
{
    public function shouldSnapshot(
        AggregateRootInterface $aggregate,
        ?AggregateSnapshotInterface $lastSnapshot = null
    ): bool {
        // MUST ensure PRD compliance - snapshots every 10 events
        $eventsSinceSnapshot = $lastSnapshot
            ? $aggregate->getVersion() - $lastSnapshot->getVersion()
            : $aggregate->getVersion();

        return $eventsSinceSnapshot >= 10; // PRD requirement
    }
}
```

### Multiple Strategies per Aggregate Type

```php
// In service provider
$this->app->bind(SnapshotStrategyInterface::class, function ($app, $parameters) {
    $aggregateType = $parameters['aggregate_type'] ?? null;

    // Always default to PRD-compliant strategy
    return match($aggregateType) {
        'critical_aggregate' => new SimpleSnapshotStrategy(10),
        default => new SimpleSnapshotStrategy(10), // PRD compliant default
    };
});
```

## Compliance Statement

This implementation meets the PRD requirement: **"System must automatically create snapshots every 10 events"** when using the default `SimpleSnapshotStrategy` with `threshold=10`.

Any deviation from this configuration may result in PRD non-compliance and should be thoroughly tested and documented.