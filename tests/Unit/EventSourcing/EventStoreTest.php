<?php

declare(strict_types=1);

namespace Tests\Unit\EventSourcing;

use LaravelModularDDD\Tests\TestCase;
use LaravelModularDDD\EventSourcing\EventStore\MySQLEventStore;
use LaravelModularDDD\EventSourcing\EventStore\EventSerializer;
use LaravelModularDDD\EventSourcing\Exceptions\ConcurrencyException;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use LaravelModularDDD\Core\Shared\AggregateId;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Mockery;

class EventStoreTest extends TestCase
{
    private MySQLEventStore $eventStore;
    private ConnectionInterface $connection;
    private EventSerializer $serializer;
    private Builder $queryBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Mockery::mock(ConnectionInterface::class);
        $this->serializer = Mockery::mock(EventSerializer::class);
        $this->queryBuilder = Mockery::mock(Builder::class);

        $this->eventStore = new MySQLEventStore($this->connection, $this->serializer);
    }

    /** @test */
    public function it_can_append_events_to_store(): void
    {
        $aggregateId = new TestAggregateId('test-aggregate-123');
        $events = [$this->createMockEvent(), $this->createMockEvent()];

        $this->connection->shouldReceive('transaction')
            ->once()
            ->with(Mockery::type('callable'))
            ->andReturnUsing(function ($callback) {
                return $callback();
            });

        $this->connection->shouldReceive('table')
            ->with('event_store')
            ->times(3) // Once for version check, twice for inserts
            ->andReturn($this->queryBuilder);

        // Mock version check
        $this->queryBuilder->shouldReceive('where->max')
            ->once()
            ->andReturn(0);

        // Mock event inserts
        $this->serializer->shouldReceive('serialize')
            ->twice()
            ->andReturn(['data' => [], 'metadata' => []]);

        $this->queryBuilder->shouldReceive('insert')
            ->twice()
            ->andReturn(true);

        $this->eventStore->append($aggregateId, $events);
        $this->assertTrue(true); // No exception thrown
    }

    /** @test */
    public function it_prevents_concurrent_modifications(): void
    {
        $aggregateId = new TestAggregateId('test-aggregate-123');
        $events = [$this->createMockEvent()];

        $this->connection->shouldReceive('transaction')
            ->once()
            ->with(Mockery::type('callable'))
            ->andReturnUsing(function ($callback) {
                return $callback();
            });

        $this->connection->shouldReceive('table')
            ->with('event_store')
            ->twice()
            ->andReturn($this->queryBuilder);

        // Mock version check showing different expected vs actual
        $this->queryBuilder->shouldReceive('where->max')
            ->twice()
            ->andReturn(5); // Actual version is 5, but expected is 3

        $this->expectException(ConcurrencyException::class);
        $this->eventStore->append($aggregateId, $events, 3);
    }

    /** @test */
    public function it_can_load_events_for_aggregate(): void
    {
        $aggregateId = new TestAggregateId('test-aggregate-123');

        $this->connection->shouldReceive('table')
            ->with('event_store')
            ->once()
            ->andReturn($this->queryBuilder);

        $this->queryBuilder->shouldReceive('where')
            ->with('aggregate_id', $aggregateId->toString())
            ->once()
            ->andReturnSelf();

        $this->queryBuilder->shouldReceive('where')
            ->with('version', '>=', 1)
            ->once()
            ->andReturnSelf();

        $this->queryBuilder->shouldReceive('orderBy')
            ->with('version')
            ->once()
            ->andReturnSelf();

        $mockRecords = collect([
            (object)[
                'event_type' => 'test.event',
                'event_data' => '{"data": "test"}',
                'metadata' => '{"meta": "data"}',
                'occurred_at' => '2023-01-01 12:00:00',
                'version' => 1
            ]
        ]);

        $this->queryBuilder->shouldReceive('get')
            ->once()
            ->andReturn($mockRecords);

        $mockEvent = $this->createMockEvent();
        $this->serializer->shouldReceive('deserialize')
            ->once()
            ->andReturn($mockEvent);

        $eventStream = $this->eventStore->load($aggregateId);

        $this->assertFalse($eventStream->isEmpty());
        $events = $eventStream->getEvents();
        $this->assertCount(1, $events);
        $this->assertSame($mockEvent, $events[0]);
    }

    /** @test */
    public function it_returns_empty_stream_when_no_events_found(): void
    {
        $aggregateId = new TestAggregateId('non-existent-aggregate');

        $this->connection->shouldReceive('table')
            ->with('event_store')
            ->once()
            ->andReturn($this->queryBuilder);

        $this->queryBuilder->shouldReceive('where')
            ->twice()
            ->andReturnSelf();

        $this->queryBuilder->shouldReceive('orderBy')
            ->once()
            ->andReturnSelf();

        $this->queryBuilder->shouldReceive('get')
            ->once()
            ->andReturn(collect([]));

        $eventStream = $this->eventStore->load($aggregateId);

        $this->assertTrue($eventStream->isEmpty());
        $this->assertCount(0, $eventStream->getEvents());
    }

    /** @test */
    public function it_can_get_aggregate_version(): void
    {
        $aggregateId = new TestAggregateId('test-aggregate-123');

        $this->connection->shouldReceive('table')
            ->with('event_store')
            ->once()
            ->andReturn($this->queryBuilder);

        $this->queryBuilder->shouldReceive('where')
            ->with('aggregate_id', $aggregateId->toString())
            ->once()
            ->andReturnSelf();

        $this->queryBuilder->shouldReceive('max')
            ->with('version')
            ->once()
            ->andReturn(5);

        $version = $this->eventStore->getAggregateVersion($aggregateId);

        $this->assertEquals(5, $version);
    }

    /** @test */
    public function it_returns_zero_version_for_non_existent_aggregate(): void
    {
        $aggregateId = new TestAggregateId('non-existent');

        $this->connection->shouldReceive('table')
            ->with('event_store')
            ->once()
            ->andReturn($this->queryBuilder);

        $this->queryBuilder->shouldReceive('where')
            ->once()
            ->andReturnSelf();

        $this->queryBuilder->shouldReceive('max')
            ->once()
            ->andReturn(null);

        $version = $this->eventStore->getAggregateVersion($aggregateId);

        $this->assertEquals(0, $version);
    }

    /** @test */
    public function it_can_check_if_aggregate_exists(): void
    {
        $aggregateId = new TestAggregateId('existing-aggregate');

        $this->connection->shouldReceive('table')
            ->with('event_store')
            ->once()
            ->andReturn($this->queryBuilder);

        $this->queryBuilder->shouldReceive('where')
            ->with('aggregate_id', $aggregateId->toString())
            ->once()
            ->andReturnSelf();

        $this->queryBuilder->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $exists = $this->eventStore->aggregateExists($aggregateId);

        $this->assertTrue($exists);
    }

    /** @test */
    public function it_can_load_events_with_version_range(): void
    {
        $aggregateId = new TestAggregateId('test-aggregate-123');

        $this->connection->shouldReceive('table')
            ->with('event_store')
            ->once()
            ->andReturn($this->queryBuilder);

        $this->queryBuilder->shouldReceive('where')
            ->with('aggregate_id', $aggregateId->toString())
            ->once()
            ->andReturnSelf();

        $this->queryBuilder->shouldReceive('where')
            ->with('version', '>=', 5)
            ->once()
            ->andReturnSelf();

        $this->queryBuilder->shouldReceive('where')
            ->with('version', '<=', 10)
            ->once()
            ->andReturnSelf();

        $this->queryBuilder->shouldReceive('orderBy')
            ->with('version')
            ->once()
            ->andReturnSelf();

        $this->queryBuilder->shouldReceive('get')
            ->once()
            ->andReturn(collect([]));

        $eventStream = $this->eventStore->load($aggregateId, 5, 10);

        $this->assertTrue($eventStream->isEmpty());
    }

    /** @test */
    public function it_can_load_events_by_type(): void
    {
        $this->connection->shouldReceive('table')
            ->with('event_store')
            ->once()
            ->andReturn($this->queryBuilder);

        $this->queryBuilder->shouldReceive('where')
            ->with('event_type', 'test.event.type')
            ->once()
            ->andReturnSelf();

        $this->queryBuilder->shouldReceive('orderBy')
            ->with('sequence_number')
            ->once()
            ->andReturnSelf();

        $this->queryBuilder->shouldReceive('limit')
            ->with(100)
            ->once()
            ->andReturnSelf();

        $this->queryBuilder->shouldReceive('offset')
            ->with(0)
            ->once()
            ->andReturnSelf();

        $this->queryBuilder->shouldReceive('get')
            ->once()
            ->andReturn(collect([]));

        $events = $this->eventStore->loadEventsByType('test.event.type');

        $this->assertIsArray($events);
        $this->assertCount(0, $events);
    }

    private function createMockEvent(): DomainEventInterface
    {
        $event = Mockery::mock(DomainEventInterface::class);
        $event->shouldReceive('getEventType')->andReturn('test.event');
        $event->shouldReceive('getEventVersion')->andReturn(1);
        $event->shouldReceive('getOccurredAt')->andReturn(new \DateTimeImmutable());
        return $event;
    }
}

class TestAggregateId extends AggregateId {}