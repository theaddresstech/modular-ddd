<?php

declare(strict_types=1);

namespace Tests\Unit\CQRS;

use LaravelModularDDD\Tests\TestCase;
use LaravelModularDDD\CQRS\CommandBus;
use LaravelModularDDD\CQRS\Command;
use LaravelModularDDD\CQRS\Contracts\CommandBusInterface;
use LaravelModularDDD\CQRS\Contracts\CommandHandlerInterface;
use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\CQRS\Contracts\MiddlewareInterface;
use LaravelModularDDD\CQRS\Middleware\ValidationMiddleware;
use LaravelModularDDD\CQRS\Middleware\AuthorizationMiddleware;
use LaravelModularDDD\CQRS\Middleware\TransactionMiddleware;
use LaravelModularDDD\CQRS\Middleware\EventDispatchingMiddleware;
use LaravelModularDDD\CQRS\Security\CommandAuthorizationManager;
use LaravelModularDDD\CQRS\Async\AsyncStrategyInterface;
use LaravelModularDDD\CQRS\Async\Strategies\SyncStrategy;
use LaravelModularDDD\CQRS\Async\AsyncStatusRepository;
use LaravelModularDDD\Core\Application\Contracts\TransactionManagerInterface;
use LaravelModularDDD\Core\Application\Services\TransactionManager;
use LaravelModularDDD\CQRS\Exceptions\CommandHandlerNotFoundException;
use LaravelModularDDD\CQRS\Exceptions\CommandValidationException;
use LaravelModularDDD\CQRS\Exceptions\CommandAuthorizationException;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Contracts\Queue\Queue;

/**
 * Comprehensive test suite for the CQRS Command Bus.
 *
 * This tests the ACTUAL implementation to validate that commands
 * are processed correctly through the middleware chain.
 */
class RealCommandBusTest extends TestCase
{
    private CommandBusInterface $commandBus;
    private Pipeline $pipeline;
    private Queue $queue;
    private CommandAuthorizationManager $authManager;
    private AsyncStrategyInterface $asyncStrategy;
    private TransactionManagerInterface $transactionManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pipeline = app(Pipeline::class);
        $this->queue = app('queue');
        $this->authManager = new CommandAuthorizationManager(true);
        $this->asyncStrategy = new SyncStrategy(
            new AsyncStatusRepository(),
            app(CommandBusInterface::class)
        );
        $this->transactionManager = new TransactionManager(
            $this->app['db']->connection(),
            3,
            100
        );

        $this->commandBus = new CommandBus(
            $this->pipeline,
            $this->queue,
            'sync',
            $this->authManager,
            $this->asyncStrategy
        );

        // Add middleware chain
        $this->commandBus->addMiddleware(new ValidationMiddleware());
        $this->commandBus->addMiddleware(new AuthorizationMiddleware($this->authManager));
        $this->commandBus->addMiddleware(new TransactionMiddleware($this->transactionManager));
        $this->commandBus->addMiddleware(new EventDispatchingMiddleware());
    }

    /** @test */
    public function it_can_handle_basic_command_successfully(): void
    {
        // Arrange
        $command = new TestRealCommand('test-id', 'John Doe', 'john@example.com');
        $handler = new TestRealCommandHandler();

        // Register the handler
        $this->commandBus->registerHandler(TestRealCommand::class, $handler);

        // Act
        $result = $this->commandBus->handle($command);

        // Assert
        $this->assertEquals('Command handled successfully', $result);
        $this->assertTrue($handler->wasHandled());
        $this->assertEquals($command, $handler->getHandledCommand());
    }

    /** @test */
    public function it_throws_exception_when_no_handler_registered(): void
    {
        // Arrange
        $command = new TestRealCommand('test-id', 'John', 'john@example.com');

        // Act & Assert
        $this->expectException(CommandHandlerNotFoundException::class);
        $this->commandBus->handle($command);
    }

    /** @test */
    public function it_validates_commands_through_middleware(): void
    {
        // Arrange
        $invalidCommand = new TestRealCommand('', '', ''); // Invalid data
        $handler = new TestRealCommandHandler();
        $this->commandBus->registerHandler(TestRealCommand::class, $handler);

        // Act & Assert
        $this->expectException(CommandValidationException::class);
        $this->commandBus->handle($invalidCommand);
    }

    /** @test */
    public function it_authorizes_commands_through_middleware(): void
    {
        // Arrange
        $command = new TestUnauthorizedCommand('test');
        $handler = new TestUnauthorizedCommandHandler();
        $this->commandBus->registerHandler(TestUnauthorizedCommand::class, $handler);

        // Act & Assert
        $this->expectException(CommandAuthorizationException::class);
        $this->commandBus->handle($command);
    }

    /** @test */
    public function it_processes_commands_within_transactions(): void
    {
        // Arrange
        $command = new TestTransactionalCommand('test-id', 'Test Data');
        $handler = new TestTransactionalCommandHandler();
        $this->commandBus->registerHandler(TestTransactionalCommand::class, $handler);

        // Act
        $result = $this->commandBus->handle($command);

        // Assert
        $this->assertEquals('Transactional command handled', $result);
        $this->assertTrue($handler->wasInTransaction());
    }

    /** @test */
    public function it_rolls_back_transaction_on_handler_failure(): void
    {
        // Arrange
        $command = new TestFailingCommand('test-id');
        $handler = new TestFailingCommandHandler();
        $this->commandBus->registerHandler(TestFailingCommand::class, $handler);

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Command handler failed');

        try {
            $this->commandBus->handle($command);
        } catch (\RuntimeException $e) {
            // Verify the transaction was rolled back
            $this->assertFalse($handler->wasCommitted());
            throw $e;
        }
    }

    /** @test */
    public function it_can_handle_commands_asynchronously(): void
    {
        // Arrange
        $command = new TestAsyncCommand('async-test-id', 'Async Data');
        $handler = new TestAsyncCommandHandler();
        $this->commandBus->registerHandler(TestAsyncCommand::class, $handler);

        // Act
        $jobId = $this->commandBus->handleAsync($command);

        // Assert
        $this->assertNotEmpty($jobId);
        $this->assertIsString($jobId);
    }

    /** @test */
    public function it_dispatches_events_after_successful_command_handling(): void
    {
        // Arrange
        $command = new TestEventDispatchingCommand('event-test-id');
        $handler = new TestEventDispatchingCommandHandler();
        $this->commandBus->registerHandler(TestEventDispatchingCommand::class, $handler);

        // Track events
        $dispatchedEvents = [];
        $this->app['events']->listen('*', function ($eventName, $payload) use (&$dispatchedEvents) {
            $dispatchedEvents[] = $eventName;
        });

        // Act
        $this->commandBus->handle($command);

        // Assert
        $this->assertContains('LaravelModularDDD\CQRS\Events\CommandExecutedEvent', $dispatchedEvents);
    }

    /** @test */
    public function it_processes_multiple_commands_efficiently(): void
    {
        // Arrange
        $handler = new TestBatchCommandHandler();
        $this->commandBus->registerHandler(TestBatchCommand::class, $handler);

        $commands = [];
        for ($i = 1; $i <= 100; $i++) {
            $commands[] = new TestBatchCommand("batch-{$i}", "Data {$i}");
        }

        // Act & Assert
        $this->assertExecutionTimeWithinLimits(function () use ($commands) {
            foreach ($commands as $command) {
                $this->commandBus->handle($command);
            }
        }, 2000); // 2 seconds max for 100 commands

        $this->assertEquals(100, $handler->getHandledCount());
    }

    /** @test */
    public function it_handles_command_with_complex_data_structures(): void
    {
        // Arrange
        $complexData = [
            'user' => [
                'id' => 'user-123',
                'profile' => [
                    'name' => 'John Doe',
                    'addresses' => [
                        ['type' => 'home', 'street' => '123 Main St'],
                        ['type' => 'work', 'street' => '456 Office Blvd'],
                    ],
                ],
            ],
            'metadata' => [
                'source' => 'api',
                'timestamp' => time(),
                'tags' => ['important', 'user-update'],
            ],
        ];

        $command = new TestComplexCommand('complex-id', $complexData);
        $handler = new TestComplexCommandHandler();
        $this->commandBus->registerHandler(TestComplexCommand::class, $handler);

        // Act
        $result = $this->commandBus->handle($command);

        // Assert
        $this->assertEquals('Complex command handled', $result);
        $this->assertEquals($complexData, $handler->getReceivedData());
    }

    /** @test */
    public function it_maintains_command_correlation_and_causation_ids(): void
    {
        // Arrange
        $command = new TestCorrelatedCommand(
            'corr-test-id',
            'correlation-123',
            'causation-456'
        );
        $handler = new TestCorrelatedCommandHandler();
        $this->commandBus->registerHandler(TestCorrelatedCommand::class, $handler);

        // Act
        $this->commandBus->handle($command);

        // Assert
        $this->assertEquals('correlation-123', $handler->getCorrelationId());
        $this->assertEquals('causation-456', $handler->getCausationId());
    }

    /** @test */
    public function it_handles_middleware_order_correctly(): void
    {
        // Arrange
        $middleware1 = new TestOrderingMiddleware('first');
        $middleware2 = new TestOrderingMiddleware('second');
        $middleware3 = new TestOrderingMiddleware('third');

        $freshCommandBus = new CommandBus(
            $this->pipeline,
            $this->queue,
            'sync',
            $this->authManager,
            $this->asyncStrategy
        );

        $freshCommandBus->addMiddleware($middleware1);
        $freshCommandBus->addMiddleware($middleware2);
        $freshCommandBus->addMiddleware($middleware3);

        $command = new TestOrderingCommand('order-test');
        $handler = new TestOrderingCommandHandler();
        $freshCommandBus->registerHandler(TestOrderingCommand::class, $handler);

        // Act
        $freshCommandBus->handle($command);

        // Assert
        $order = TestOrderingMiddleware::getExecutionOrder();
        $this->assertEquals(['first', 'second', 'third'], $order);
    }

    /** @test */
    public function it_uses_memory_efficiently_for_large_command_batches(): void
    {
        // Arrange
        $handler = new TestMemoryEfficientHandler();
        $this->commandBus->registerHandler(TestMemoryCommand::class, $handler);

        // Act - Process many commands
        for ($i = 1; $i <= 1000; $i++) {
            $command = new TestMemoryCommand("mem-{$i}", str_repeat('x', 1000)); // 1KB per command
            $this->commandBus->handle($command);
        }

        // Assert
        $this->assertMemoryUsageWithinLimits(128); // 128MB max
        $this->assertEquals(1000, $handler->getHandledCount());
    }

    /** @test */
    public function it_provides_detailed_error_information_on_failures(): void
    {
        // Arrange
        $command = new TestDetailedErrorCommand('error-test');
        $handler = new TestDetailedErrorCommandHandler();
        $this->commandBus->registerHandler(TestDetailedErrorCommand::class, $handler);

        // Act & Assert
        try {
            $this->commandBus->handle($command);
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Detailed error information', $e->getMessage());
            $this->assertStringContainsString('error-test', $e->getMessage());
        }
    }
}

// Test Commands and Handlers

class TestRealCommand extends Command
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $email
    ) {}

    public function rules(): array
    {
        return [
            'id' => 'required|string|min:1',
            'name' => 'required|string|min:1',
            'email' => 'required|email',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}

class TestRealCommandHandler implements CommandHandlerInterface
{
    private bool $handled = false;
    private ?TestRealCommand $handledCommand = null;

    public function handle($command): string
    {
        $this->handled = true;
        $this->handledCommand = $command;
        return 'Command handled successfully';
    }

    public function wasHandled(): bool
    {
        return $this->handled;
    }

    public function getHandledCommand(): ?TestRealCommand
    {
        return $this->handledCommand;
    }

    public function getHandledCommandType(): string
    {
        return TestRealCommand::class;
    }

    public function canHandle(CommandInterface $command): bool
    {
        return $command instanceof TestRealCommand;
    }

    public function getPriority(): int
    {
        return 0;
    }
}

class TestUnauthorizedCommand extends Command
{
    public function __construct(public readonly string $data) {}

    public function authorize(): bool
    {
        return false; // Always unauthorized
    }
}

class TestUnauthorizedCommandHandler implements CommandHandlerInterface
{
    public function handle($command): string
    {
        return 'Should not reach here';
    }

    public function getHandledCommandType(): string
    {
        return TestUnauthorizedCommand::class;
    }

    public function canHandle(CommandInterface $command): bool
    {
        return $command instanceof TestUnauthorizedCommand;
    }

    public function getPriority(): int
    {
        return 0;
    }
}

class TestTransactionalCommand extends Command
{
    public function __construct(
        public readonly string $id,
        public readonly string $data
    ) {}
}

class TestTransactionalCommandHandler implements CommandHandlerInterface
{
    private bool $inTransaction = false;

    public function handle($command): string
    {
        $this->inTransaction = app('db')->transactionLevel() > 0;
        return 'Transactional command handled';
    }

    public function wasInTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function getHandledCommandType(): string
    {
        return TestTransactionalCommand::class;
    }

    public function canHandle(CommandInterface $command): bool
    {
        return $command instanceof TestTransactionalCommand;
    }

    public function getPriority(): int
    {
        return 0;
    }
}

class TestFailingCommand extends Command
{
    public function __construct(public readonly string $id) {}
}

class TestFailingCommandHandler implements CommandHandlerInterface
{
    private bool $committed = false;

    public function handle($command): string
    {
        $this->committed = true;
        throw new \RuntimeException('Command handler failed');
    }

    public function wasCommitted(): bool
    {
        return $this->committed;
    }

    public function getHandledCommandType(): string
    {
        return TestFailingCommand::class;
    }

    public function canHandle(CommandInterface $command): bool
    {
        return $command instanceof TestFailingCommand;
    }

    public function getPriority(): int
    {
        return 0;
    }
}

class TestAsyncCommand extends Command
{
    public function __construct(
        public readonly string $id,
        public readonly string $data
    ) {}
}

class TestAsyncCommandHandler implements CommandHandlerInterface
{
    public function handle($command): string
    {
        return 'Async command handled';
    }

    public function getHandledCommandType(): string
    {
        return TestAsyncCommand::class;
    }

    public function canHandle(CommandInterface $command): bool
    {
        return $command instanceof TestAsyncCommand;
    }

    public function getPriority(): int
    {
        return 0;
    }
}

class TestEventDispatchingCommand extends Command
{
    public function __construct(public readonly string $id) {}
}

class TestEventDispatchingCommandHandler implements CommandHandlerInterface
{
    public function handle($command): string
    {
        return 'Event dispatching command handled';
    }

    public function getHandledCommandType(): string
    {
        return TestEventDispatchingCommand::class;
    }

    public function canHandle(CommandInterface $command): bool
    {
        return $command instanceof TestEventDispatchingCommand;
    }

    public function getPriority(): int
    {
        return 0;
    }
}

class TestBatchCommand extends Command
{
    public function __construct(
        public readonly string $id,
        public readonly string $data
    ) {}
}

class TestBatchCommandHandler implements CommandHandlerInterface
{
    private int $handledCount = 0;

    public function handle($command): string
    {
        $this->handledCount++;
        return 'Batch command handled';
    }

    public function getHandledCount(): int
    {
        return $this->handledCount;
    }

    public function getHandledCommandType(): string
    {
        return TestBatchCommand::class;
    }

    public function canHandle(CommandInterface $command): bool
    {
        return $command instanceof TestBatchCommand;
    }

    public function getPriority(): int
    {
        return 0;
    }
}

class TestComplexCommand extends Command
{
    public function __construct(
        public readonly string $id,
        public readonly array $complexData
    ) {}
}

class TestComplexCommandHandler implements CommandHandlerInterface
{
    private array $receivedData = [];

    public function handle($command): string
    {
        $this->receivedData = $command->complexData;
        return 'Complex command handled';
    }

    public function getReceivedData(): array
    {
        return $this->receivedData;
    }

    public function getHandledCommandType(): string
    {
        return TestComplexCommand::class;
    }

    public function canHandle(CommandInterface $command): bool
    {
        return $command instanceof TestComplexCommand;
    }

    public function getPriority(): int
    {
        return 0;
    }
}

class TestCorrelatedCommand extends Command
{
    public function __construct(
        public readonly string $id,
        public readonly string $correlationId,
        public readonly string $causationId
    ) {}

    public function getMetadata(): array
    {
        return [
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
        ];
    }
}

class TestCorrelatedCommandHandler implements CommandHandlerInterface
{
    private ?string $correlationId = null;
    private ?string $causationId = null;

    public function handle($command): string
    {
        $metadata = $command->getMetadata();
        $this->correlationId = $metadata['correlation_id'] ?? null;
        $this->causationId = $metadata['causation_id'] ?? null;
        return 'Correlated command handled';
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    public function getCausationId(): ?string
    {
        return $this->causationId;
    }

    public function getHandledCommandType(): string
    {
        return TestCorrelatedCommand::class;
    }

    public function canHandle(CommandInterface $command): bool
    {
        return $command instanceof TestCorrelatedCommand;
    }

    public function getPriority(): int
    {
        return 0;
    }
}

class TestOrderingCommand extends Command
{
    public function __construct(public readonly string $id) {}
}

class TestOrderingCommandHandler implements CommandHandlerInterface
{
    public function handle($command): string
    {
        return 'Ordering command handled';
    }

    public function getHandledCommandType(): string
    {
        return TestOrderingCommand::class;
    }

    public function canHandle(CommandInterface $command): bool
    {
        return $command instanceof TestOrderingCommand;
    }

    public function getPriority(): int
    {
        return 0;
    }
}

class TestOrderingMiddleware implements MiddlewareInterface
{
    private static array $executionOrder = [];

    public function __construct(private string $name) {}

    public function handle(mixed $message, \Closure $next): mixed
    {
        self::$executionOrder[] = $this->name;
        return $next($message);
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function shouldProcess(mixed $message): bool
    {
        return true;
    }

    public static function getExecutionOrder(): array
    {
        return self::$executionOrder;
    }

    public static function resetOrder(): void
    {
        self::$executionOrder = [];
    }
}

class TestMemoryCommand extends Command
{
    public function __construct(
        public readonly string $id,
        public readonly string $data
    ) {}
}

class TestMemoryEfficientHandler implements CommandHandlerInterface
{
    private int $handledCount = 0;

    public function handle($command): string
    {
        $this->handledCount++;
        // Simulate processing without storing references
        unset($command);
        return 'Memory command handled';
    }

    public function getHandledCount(): int
    {
        return $this->handledCount;
    }

    public function getHandledCommandType(): string
    {
        return TestMemoryCommand::class;
    }

    public function canHandle(CommandInterface $command): bool
    {
        return $command instanceof TestMemoryCommand;
    }

    public function getPriority(): int
    {
        return 0;
    }
}

class TestDetailedErrorCommand extends Command
{
    public function __construct(public readonly string $id) {}
}

class TestDetailedErrorCommandHandler implements CommandHandlerInterface
{
    public function handle($command): string
    {
        throw new \RuntimeException("Detailed error information for command: {$command->id}");
    }

    public function getHandledCommandType(): string
    {
        return TestDetailedErrorCommand::class;
    }

    public function canHandle(CommandInterface $command): bool
    {
        return $command instanceof TestDetailedErrorCommand;
    }

    public function getPriority(): int
    {
        return 0;
    }
}