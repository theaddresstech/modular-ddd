<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Unit\Snapshot;

use PHPUnit\Framework\TestCase;
use LaravelModularDDD\EventSourcing\Snapshot\SnapshotStrategyFactory;
use LaravelModularDDD\EventSourcing\Snapshot\SimpleSnapshotStrategy;
use LaravelModularDDD\EventSourcing\Snapshot\AdaptiveSnapshotStrategy;
use LaravelModularDDD\EventSourcing\Snapshot\TimeBasedSnapshotStrategy;

class SnapshotStrategyFactoryTest extends TestCase
{
    /** @test */
    public function it_creates_simple_strategy_by_default(): void
    {
        $strategy = SnapshotStrategyFactory::create([]);

        $this->assertInstanceOf(SimpleSnapshotStrategy::class, $strategy);
        $this->assertEquals(10, $strategy->getEventThreshold());
    }

    /** @test */
    public function it_creates_simple_strategy_with_custom_threshold(): void
    {
        $config = [
            'snapshot_strategy' => 'simple',
            'snapshot_threshold' => 20,
        ];

        $strategy = SnapshotStrategyFactory::create($config);

        $this->assertInstanceOf(SimpleSnapshotStrategy::class, $strategy);
        $this->assertEquals(20, $strategy->getEventThreshold());
    }

    /** @test */
    public function it_creates_adaptive_strategy(): void
    {
        $config = [
            'snapshot_strategy' => 'adaptive',
            'adaptive_config' => [
                'event_count_threshold' => 50,
            ],
        ];

        $strategy = SnapshotStrategyFactory::create($config);

        $this->assertInstanceOf(AdaptiveSnapshotStrategy::class, $strategy);
    }

    /** @test */
    public function it_creates_time_based_strategy(): void
    {
        $config = [
            'snapshot_strategy' => 'time_based',
            'time_interval' => 1800,
        ];

        $strategy = SnapshotStrategyFactory::create($config);

        $this->assertInstanceOf(TimeBasedSnapshotStrategy::class, $strategy);
        $this->assertEquals(1800, $strategy->getTimeInterval());
    }

    /** @test */
    public function it_falls_back_to_simple_for_unknown_strategy(): void
    {
        $config = [
            'snapshot_strategy' => 'unknown',
        ];

        $strategy = SnapshotStrategyFactory::create($config);

        $this->assertInstanceOf(SimpleSnapshotStrategy::class, $strategy);
        $this->assertEquals(10, $strategy->getEventThreshold());
    }

    /** @test */
    public function it_returns_available_strategies(): void
    {
        $strategies = SnapshotStrategyFactory::getAvailableStrategies();

        $this->assertArrayHasKey('simple', $strategies);
        $this->assertArrayHasKey('adaptive', $strategies);
        $this->assertArrayHasKey('time_based', $strategies);

        $this->assertEquals(SimpleSnapshotStrategy::class, $strategies['simple']['class']);
        $this->assertEquals(['snapshot_threshold'], $strategies['simple']['config_keys']);
    }

    /** @test */
    public function it_validates_simple_strategy_config(): void
    {
        $validConfig = [
            'snapshot_strategy' => 'simple',
            'snapshot_threshold' => 10,
        ];

        $errors = SnapshotStrategyFactory::validateConfig($validConfig);
        $this->assertEmpty($errors);

        $invalidConfig = [
            'snapshot_strategy' => 'simple',
            'snapshot_threshold' => 0,
        ];

        $errors = SnapshotStrategyFactory::validateConfig($invalidConfig);
        $this->assertContains('snapshot_threshold must be a positive integer', $errors);
    }

    /** @test */
    public function it_validates_adaptive_strategy_config(): void
    {
        $validConfig = [
            'snapshot_strategy' => 'adaptive',
            'adaptive_config' => [],
        ];

        $errors = SnapshotStrategyFactory::validateConfig($validConfig);
        $this->assertEmpty($errors);

        $invalidConfig = [
            'snapshot_strategy' => 'adaptive',
        ];

        $errors = SnapshotStrategyFactory::validateConfig($invalidConfig);
        $this->assertContains('adaptive_config must be an array', $errors);
    }

    /** @test */
    public function it_validates_time_based_strategy_config(): void
    {
        $validConfig = [
            'snapshot_strategy' => 'time_based',
            'time_interval' => 3600,
        ];

        $errors = SnapshotStrategyFactory::validateConfig($validConfig);
        $this->assertEmpty($errors);

        $invalidConfig = [
            'snapshot_strategy' => 'time_based',
            'time_interval' => 30,
        ];

        $errors = SnapshotStrategyFactory::validateConfig($invalidConfig);
        $this->assertContains('time_interval must be at least 60 seconds', $errors);
    }

    /** @test */
    public function it_validates_unknown_strategy(): void
    {
        $config = [
            'snapshot_strategy' => 'unknown',
        ];

        $errors = SnapshotStrategyFactory::validateConfig($config);
        $this->assertContains('Unknown snapshot strategy: unknown', $errors);
    }
}