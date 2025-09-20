<?php

declare(strict_types=1);

namespace Tests\Unit\CQRS;

use LaravelModularDDD\Tests\TestCase;
use LaravelModularDDD\CQRS\CommandBus;
use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\CQRS\Contracts\CommandHandlerInterface;
use LaravelModularDDD\CQRS\Exceptions\CommandHandlerNotFoundException;
use LaravelModularDDD\CQRS\Exceptions\ValidationException;
use LaravelModularDDD\CQRS\Monitoring\PerformanceMonitor;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Validation\Validator;
use Mockery;

class CommandBusTest extends TestCase
{
    private CommandBus $commandBus;
    private Pipeline $pipeline;
    private Queue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pipeline = Mockery::mock(Pipeline::class);
        $this->queue = Mockery::mock(Queue::class);
        $this->commandBus = new CommandBus($this->pipeline, $this->queue);
    }

    /** @test */
    public function it_can_execute_command_with_registered_handler(): void
    {
        $command = new TestCommand('test-data');
        $handler = new TestCommandHandler();

        $this->commandBus->registerHandler($handler);

        $this->performanceMonitor->shouldReceive('startCommand')
            ->once()
            ->with($command);

        $this->performanceMonitor->shouldReceive('endCommand')
            ->once()
            ->with($command, Mockery::type('float'), true);

        $result = $this->commandBus->execute($command);

        $this->assertEquals('handled: test-data', $result);
    }

    /** @test */
    public function it_throws_exception_when_no_handler_found(): void
    {
        $command = new TestCommand('test-data');

        $this->expectException(CommandHandlerNotFoundException::class);
        $this->expectExceptionMessage('No handler registered for command: TestCommand');

        $this->commandBus->execute($command);
    }

    /** @test */
    public function it_can_check_if_command_can_be_handled(): void
    {
        $command = new TestCommand('test-data');
        $handler = new TestCommandHandler();

        $this->assertFalse($this->commandBus->canHandle($command));

        $this->commandBus->registerHandler($handler);

        $this->assertTrue($this->commandBus->canHandle($command));
    }

    /** @test */
    public function it_validates_commands_before_execution(): void
    {
        $command = new ValidatableTestCommand('');
        $handler = new TestCommandHandler();

        $this->commandBus->registerHandler($handler);

        $this->expectException(ValidationException::class);

        $this->commandBus->execute($command);
    }

    /** @test */
    public function it_executes_valid_commands(): void
    {
        $command = new ValidatableTestCommand('valid-data');
        $handler = new TestCommandHandler();

        $this->commandBus->registerHandler($handler);

        $this->performanceMonitor->shouldReceive('startCommand')->once();
        $this->performanceMonitor->shouldReceive('endCommand')->once();

        $result = $this->commandBus->execute($command);

        $this->assertEquals('handled: valid-data', $result);
    }

    /** @test */
    public function it_records_performance_metrics(): void
    {
        $command = new TestCommand('test-data');
        $handler = new TestCommandHandler();

        $this->commandBus->registerHandler($handler);

        $this->performanceMonitor->shouldReceive('startCommand')
            ->once()
            ->with($command);

        $this->performanceMonitor->shouldReceive('endCommand')
            ->once()
            ->with($command, Mockery::type('float'), true);

        $this->commandBus->execute($command);
    }

    /** @test */
    public function it_records_metrics_for_failed_commands(): void
    {
        $command = new TestCommand('fail');
        $handler = new FailingCommandHandler();

        $this->commandBus->registerHandler($handler);

        $this->performanceMonitor->shouldReceive('startCommand')
            ->once()
            ->with($command);

        $this->performanceMonitor->shouldReceive('endCommand')
            ->once()
            ->with($command, Mockery::type('float'), false);

        $this->expectException(\RuntimeException::class);

        $this->commandBus->execute($command);
    }

    /** @test */
    public function it_can_get_execution_statistics(): void
    {
        $stats = $this->commandBus->getStatistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_commands', $stats);
        $this->assertArrayHasKey('successful_commands', $stats);
        $this->assertArrayHasKey('failed_commands', $stats);
        $this->assertArrayHasKey('avg_execution_time_ms', $stats);
    }

    /** @test */
    public function it_supports_multiple_handlers_for_different_commands(): void
    {
        $command1 = new TestCommand('data1');
        $command2 = new AnotherTestCommand('data2');

        $handler1 = new TestCommandHandler();
        $handler2 = new AnotherTestCommandHandler();

        $this->commandBus->registerHandler($handler1);
        $this->commandBus->registerHandler($handler2);

        $this->performanceMonitor->shouldReceive('startCommand')->twice();
        $this->performanceMonitor->shouldReceive('endCommand')->twice();

        $result1 = $this->commandBus->execute($command1);
        $result2 = $this->commandBus->execute($command2);

        $this->assertEquals('handled: data1', $result1);
        $this->assertEquals('another handled: data2', $result2);
    }
}

// Test implementations
class TestCommand implements CommandInterface
{
    public function __construct(private string $data) {}

    public function getData(): string
    {
        return $this->data;
    }

    public function getCommandId(): string
    {
        return uniqid('test_', true);
    }

    public function getCommandName(): string
    {
        return 'TestCommand';
    }

    public function getValidationRules(): array
    {
        return [];
    }

    public function getMetadata(): array
    {
        return [];
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function getTimeout(): int
    {
        return 30;
    }

    public function shouldRetry(): bool
    {
        return false;
    }

    public function getMaxRetries(): int
    {
        return 0;
    }

    public function toArray(): array
    {
        return ['data' => $this->data];
    }
}

class ValidatableTestCommand implements CommandInterface
{
    public function __construct(private string $data) {}

    public function getData(): string
    {
        return $this->data;
    }

    public function getCommandId(): string
    {
        return uniqid('validatable_', true);
    }

    public function getCommandName(): string
    {
        return 'ValidatableTestCommand';
    }

    public function getValidationRules(): array
    {
        return [
            'data' => 'required|min:3'
        ];
    }

    public function getMetadata(): array
    {
        return [];
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function getTimeout(): int
    {
        return 30;
    }

    public function shouldRetry(): bool
    {
        return false;
    }

    public function getMaxRetries(): int
    {
        return 0;
    }

    public function toArray(): array
    {
        return ['data' => $this->data];
    }
}

class AnotherTestCommand implements CommandInterface
{
    public function __construct(private string $data) {}

    public function getData(): string
    {
        return $this->data;
    }

    public function getCommandId(): string
    {
        return uniqid('another_', true);
    }

    public function getCommandName(): string
    {
        return 'AnotherTestCommand';
    }

    public function getValidationRules(): array
    {
        return [];
    }

    public function getMetadata(): array
    {
        return [];
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function getTimeout(): int
    {
        return 30;
    }

    public function shouldRetry(): bool
    {
        return false;
    }

    public function getMaxRetries(): int
    {
        return 0;
    }

    public function toArray(): array
    {
        return ['data' => $this->data];
    }
}

class TestCommandHandler implements CommandHandlerInterface
{
    public function handle(CommandInterface $command): mixed
    {
        if ($command instanceof TestCommand || $command instanceof ValidatableTestCommand) {
            return 'handled: ' . $command->getData();
        }

        throw new \InvalidArgumentException('Unsupported command type');
    }

    public function getHandledCommandType(): string
    {
        return TestCommand::class;
    }

    public function canHandle(CommandInterface $command): bool
    {
        return $command instanceof TestCommand || $command instanceof ValidatableTestCommand;
    }

    public function getPriority(): int
    {
        return 0;
    }
}

class AnotherTestCommandHandler implements CommandHandlerInterface
{
    public function handle(CommandInterface $command): mixed
    {
        if ($command instanceof AnotherTestCommand) {
            return 'another handled: ' . $command->getData();
        }

        throw new \InvalidArgumentException('Unsupported command type');
    }

    public function getHandledCommandType(): string
    {
        return AnotherTestCommand::class;
    }

    public function canHandle(CommandInterface $command): bool
    {
        return $command instanceof AnotherTestCommand;
    }

    public function getPriority(): int
    {
        return 0;
    }
}

class FailingCommandHandler implements CommandHandlerInterface
{
    public function handle(CommandInterface $command): mixed
    {
        throw new \RuntimeException('Command failed');
    }

    public function getHandledCommandType(): string
    {
        return TestCommand::class;
    }

    public function canHandle(CommandInterface $command): bool
    {
        return $command instanceof TestCommand;
    }

    public function getPriority(): int
    {
        return 0;
    }
}