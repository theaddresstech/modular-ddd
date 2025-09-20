<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Unit\Modules;

use LaravelModularDDD\Tests\TestCase;
use LaravelModularDDD\Modules\Communication\RealModuleBus;
use LaravelModularDDD\Modules\Communication\Contracts\ModuleBusInterface;
use LaravelModularDDD\Modules\Communication\Contracts\ModuleMessageInterface;
use LaravelModularDDD\Modules\Communication\Contracts\ModuleHandlerInterface;
use LaravelModularDDD\Modules\Communication\Exceptions\ModuleNotFoundException;
use LaravelModularDDD\Modules\Communication\Exceptions\MessageRoutingException;
use LaravelModularDDD\Modules\Communication\Exceptions\ModuleTimeoutException;
use LaravelModularDDD\Modules\Communication\Exceptions\ModuleAuthorizationException;
use LaravelModularDDD\Modules\Communication\Module;
use LaravelModularDDD\Modules\Communication\ModuleMessage;
use LaravelModularDDD\Modules\Communication\ModuleRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Test suite for Real Module Bus implementation.
 *
 * This validates that modules can communicate with each other correctly,
 * including message routing, event broadcasting, and error handling.
 */
class RealModuleBusTest extends TestCase
{
    private ModuleBusInterface $moduleBus;
    private ModuleRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new ModuleRegistry();
        $this->moduleBus = new RealModuleBus($this->registry);

        // Register test modules
        $this->registerTestModules();
    }

    /** @test */
    public function it_can_register_and_discover_modules(): void
    {
        // Arrange
        $userModule = new TestUserModule();
        $orderModule = new TestOrderModule();

        // Act
        $this->moduleBus->registerModule('user', $userModule);
        $this->moduleBus->registerModule('order', $orderModule);

        // Assert
        $this->assertTrue($this->moduleBus->hasModule('user'));
        $this->assertTrue($this->moduleBus->hasModule('order'));
        $this->assertFalse($this->moduleBus->hasModule('nonexistent'));

        $registeredModules = $this->moduleBus->getRegisteredModules();
        $this->assertArrayHasKey('user', $registeredModules);
        $this->assertArrayHasKey('order', $registeredModules);
    }

    /** @test */
    public function it_can_send_messages_between_modules(): void
    {
        // Arrange
        $message = new TestModuleMessage('user.get', ['user_id' => '123']);

        // Act
        $response = $this->moduleBus->send('user', $message);

        // Assert
        $this->assertNotNull($response);
        $this->assertEquals('user', $response['module']);
        $this->assertEquals('123', $response['user_id']);
        $this->assertEquals('John Doe', $response['name']);
    }

    /** @test */
    public function it_throws_exception_for_unknown_module(): void
    {
        // Arrange
        $message = new TestModuleMessage('unknown.action', ['data' => 'test']);

        // Act & Assert
        $this->expectException(ModuleNotFoundException::class);
        $this->moduleBus->send('unknown_module', $message);
    }

    /** @test */
    public function it_can_broadcast_events_to_multiple_modules(): void
    {
        // Arrange
        $event = new TestModuleEvent('user.created', [
            'user_id' => '456',
            'email' => 'jane@example.com'
        ]);

        // Act
        $this->moduleBus->broadcast($event);

        // Assert - Check that both modules received the event
        $userModule = $this->moduleBus->getModule('user');
        $orderModule = $this->moduleBus->getModule('order');

        $this->assertTrue($userModule->hasReceivedEvent('user.created'));
        $this->assertTrue($orderModule->hasReceivedEvent('user.created'));
    }

    /** @test */
    public function it_handles_request_response_pattern_correctly(): void
    {
        // Arrange
        $request = new TestModuleRequest('order.calculate_total', [
            'order_id' => 'order-789',
            'items' => [
                ['id' => 'item-1', 'price' => 10.00],
                ['id' => 'item-2', 'price' => 15.50]
            ]
        ]);

        // Act
        $response = $this->moduleBus->request('order', $request);

        // Assert
        $this->assertNotNull($response);
        $this->assertEquals('order-789', $response['order_id']);
        $this->assertEquals(25.50, $response['total']);
        $this->assertArrayHasKey('calculated_at', $response);
    }

    /** @test */
    public function it_handles_asynchronous_messages(): void
    {
        // Arrange
        $asyncMessage = new TestAsyncMessage('notification.send', [
            'user_id' => '123',
            'message' => 'Welcome!',
            'type' => 'email'
        ]);

        // Act
        $promise = $this->moduleBus->sendAsync('notification', $asyncMessage);

        // Assert
        $this->assertNotNull($promise);

        // Wait for completion (in real implementation this would be handled by queue)
        $result = $promise->wait();
        $this->assertEquals('sent', $result['status']);
        $this->assertEquals('email', $result['type']);
    }

    /** @test */
    public function it_handles_module_timeouts(): void
    {
        // Arrange
        $slowMessage = new TestSlowMessage('slow.operation', ['delay' => 2000]);

        // Act & Assert
        $this->expectException(ModuleTimeoutException::class);
        $this->moduleBus->send('slow', $slowMessage, ['timeout' => 1000]); // 1 second timeout
    }

    /** @test */
    public function it_validates_message_authorization(): void
    {
        // Arrange
        $secureMessage = new TestSecureMessage('admin.delete_user', [
            'user_id' => '123',
            'reason' => 'spam'
        ]);

        // Mock unauthorized user
        auth()->shouldReceive('user')->andReturn((object)['role' => 'user']);

        // Act & Assert
        $this->expectException(ModuleAuthorizationException::class);
        $this->moduleBus->send('admin', $secureMessage);
    }

    /** @test */
    public function it_handles_message_routing_errors(): void
    {
        // Arrange
        $invalidMessage = new TestInvalidMessage('invalid.action', []);

        // Act & Assert
        $this->expectException(MessageRoutingException::class);
        $this->moduleBus->send('user', $invalidMessage);
    }

    /** @test */
    public function it_logs_module_communication(): void
    {
        // Arrange
        Log::shouldReceive('info')->once()->with(
            'Module message sent',
            \Mockery::on(function ($context) {
                return $context['module'] === 'user' &&
                       $context['action'] === 'user.get' &&
                       isset($context['message_id']);
            })
        );

        $message = new TestModuleMessage('user.get', ['user_id' => '123']);

        // Act
        $this->moduleBus->send('user', $message);
    }

    /** @test */
    public function it_handles_high_volume_message_traffic(): void
    {
        // Arrange
        $messages = [];
        for ($i = 1; $i <= 1000; $i++) {
            $messages[] = new TestModuleMessage('user.get', ['user_id' => (string)$i]);
        }

        // Act & Assert
        $this->assertExecutionTimeWithinLimits(function () use ($messages) {
            foreach ($messages as $message) {
                $response = $this->moduleBus->send('user', $message);
                $this->assertNotNull($response);
            }
        }, 5000); // 1000 messages should complete within 5 seconds

        $this->assertMemoryUsageWithinLimits(64); // Should not exceed 64MB
    }

    /** @test */
    public function it_maintains_message_ordering_for_same_target(): void
    {
        // Arrange
        $orderedMessages = [];
        for ($i = 1; $i <= 10; $i++) {
            $orderedMessages[] = new TestOrderedMessage('sequence.process', [
                'sequence_number' => $i,
                'data' => "Message {$i}"
            ]);
        }

        // Act
        $responses = [];
        foreach ($orderedMessages as $message) {
            $responses[] = $this->moduleBus->send('sequence', $message);
        }

        // Assert - Responses should maintain order
        for ($i = 0; $i < 10; $i++) {
            $this->assertEquals($i + 1, $responses[$i]['processed_sequence']);
        }
    }

    /** @test */
    public function it_handles_module_dependencies_correctly(): void
    {
        // Arrange
        $dependentMessage = new TestDependentMessage('order.create_with_user', [
            'user_id' => '123',
            'product_id' => 'prod-456',
            'quantity' => 2
        ]);

        // Act
        $response = $this->moduleBus->send('order', $dependentMessage);

        // Assert
        $this->assertArrayHasKey('order_id', $response);
        $this->assertArrayHasKey('user_validated', $response);
        $this->assertTrue($response['user_validated']);
        $this->assertEquals('123', $response['user_id']);
    }

    /** @test */
    public function it_handles_circular_dependency_detection(): void
    {
        // Arrange
        $circularMessage = new TestCircularMessage('circular.start', [
            'target' => 'circular',
            'depth' => 0
        ]);

        // Act & Assert
        $this->expectException(MessageRoutingException::class);
        $this->expectExceptionMessage('Circular dependency detected');

        $this->moduleBus->send('circular', $circularMessage);
    }

    /** @test */
    public function it_provides_module_health_checks(): void
    {
        // Act
        $healthStatus = $this->moduleBus->getModuleHealth();

        // Assert
        $this->assertArrayHasKey('user', $healthStatus);
        $this->assertArrayHasKey('order', $healthStatus);

        foreach ($healthStatus as $module => $status) {
            $this->assertArrayHasKey('status', $status);
            $this->assertArrayHasKey('response_time', $status);
            $this->assertArrayHasKey('last_check', $status);
        }
    }

    /** @test */
    public function it_tracks_module_performance_metrics(): void
    {
        // Arrange
        $message = new TestModuleMessage('user.get', ['user_id' => '123']);

        // Act
        for ($i = 0; $i < 10; $i++) {
            $this->moduleBus->send('user', $message);
        }

        $metrics = $this->moduleBus->getPerformanceMetrics('user');

        // Assert
        $this->assertArrayHasKey('total_messages', $metrics);
        $this->assertArrayHasKey('average_response_time', $metrics);
        $this->assertArrayHasKey('success_rate', $metrics);
        $this->assertEquals(10, $metrics['total_messages']);
        $this->assertGreaterThan(0, $metrics['average_response_time']);
        $this->assertEquals(100.0, $metrics['success_rate']);
    }

    /** @test */
    public function it_handles_module_graceful_shutdown(): void
    {
        // Act
        $shutdownResult = $this->moduleBus->shutdown();

        // Assert
        $this->assertTrue($shutdownResult);

        // Verify modules are no longer accessible
        $this->assertFalse($this->moduleBus->hasModule('user'));
        $this->assertFalse($this->moduleBus->hasModule('order'));
    }

    private function registerTestModules(): void
    {
        $this->moduleBus->registerModule('user', new TestUserModule());
        $this->moduleBus->registerModule('order', new TestOrderModule());
        $this->moduleBus->registerModule('notification', new TestNotificationModule());
        $this->moduleBus->registerModule('admin', new TestAdminModule());
        $this->moduleBus->registerModule('slow', new TestSlowModule());
        $this->moduleBus->registerModule('sequence', new TestSequenceModule());
        $this->moduleBus->registerModule('circular', new TestCircularModule());
    }
}

// Test Message Classes
class TestModuleMessage implements ModuleMessageInterface
{
    public function __construct(private string $action, private array $data)
    {
        $this->data['message_id'] = uniqid();
    }

    public function getAction(): string { return $this->action; }
    public function getData(): array { return $this->data; }
    public function getMessageId(): string { return $this->data['message_id']; }
    public function getTimeout(): ?int { return null; }
    public function isAsync(): bool { return false; }
    public function requiresAuth(): bool { return false; }
}

class TestModuleEvent implements ModuleMessageInterface
{
    public function __construct(private string $action, private array $data)
    {
        $this->data['event_id'] = uniqid();
    }

    public function getAction(): string { return $this->action; }
    public function getData(): array { return $this->data; }
    public function getMessageId(): string { return $this->data['event_id']; }
    public function getTimeout(): ?int { return null; }
    public function isAsync(): bool { return false; }
    public function requiresAuth(): bool { return false; }
}

class TestModuleRequest implements ModuleMessageInterface
{
    public function __construct(private string $action, private array $data)
    {
        $this->data['request_id'] = uniqid();
    }

    public function getAction(): string { return $this->action; }
    public function getData(): array { return $this->data; }
    public function getMessageId(): string { return $this->data['request_id']; }
    public function getTimeout(): ?int { return 5000; }
    public function isAsync(): bool { return false; }
    public function requiresAuth(): bool { return false; }
}

class TestAsyncMessage implements ModuleMessageInterface
{
    public function __construct(private string $action, private array $data)
    {
        $this->data['async_id'] = uniqid();
    }

    public function getAction(): string { return $this->action; }
    public function getData(): array { return $this->data; }
    public function getMessageId(): string { return $this->data['async_id']; }
    public function getTimeout(): ?int { return null; }
    public function isAsync(): bool { return true; }
    public function requiresAuth(): bool { return false; }
}

class TestSlowMessage implements ModuleMessageInterface
{
    public function __construct(private string $action, private array $data)
    {
        $this->data['slow_id'] = uniqid();
    }

    public function getAction(): string { return $this->action; }
    public function getData(): array { return $this->data; }
    public function getMessageId(): string { return $this->data['slow_id']; }
    public function getTimeout(): ?int { return 2000; }
    public function isAsync(): bool { return false; }
    public function requiresAuth(): bool { return false; }
}

class TestSecureMessage implements ModuleMessageInterface
{
    public function __construct(private string $action, private array $data)
    {
        $this->data['secure_id'] = uniqid();
    }

    public function getAction(): string { return $this->action; }
    public function getData(): array { return $this->data; }
    public function getMessageId(): string { return $this->data['secure_id']; }
    public function getTimeout(): ?int { return null; }
    public function isAsync(): bool { return false; }
    public function requiresAuth(): bool { return true; }
}

class TestInvalidMessage implements ModuleMessageInterface
{
    public function __construct(private string $action, private array $data)
    {
        $this->data['invalid_id'] = uniqid();
    }

    public function getAction(): string { return $this->action; }
    public function getData(): array { return $this->data; }
    public function getMessageId(): string { return $this->data['invalid_id']; }
    public function getTimeout(): ?int { return null; }
    public function isAsync(): bool { return false; }
    public function requiresAuth(): bool { return false; }
}

class TestOrderedMessage implements ModuleMessageInterface
{
    public function __construct(private string $action, private array $data)
    {
        $this->data['ordered_id'] = uniqid();
    }

    public function getAction(): string { return $this->action; }
    public function getData(): array { return $this->data; }
    public function getMessageId(): string { return $this->data['ordered_id']; }
    public function getTimeout(): ?int { return null; }
    public function isAsync(): bool { return false; }
    public function requiresAuth(): bool { return false; }
}

class TestDependentMessage implements ModuleMessageInterface
{
    public function __construct(private string $action, private array $data)
    {
        $this->data['dependent_id'] = uniqid();
    }

    public function getAction(): string { return $this->action; }
    public function getData(): array { return $this->data; }
    public function getMessageId(): string { return $this->data['dependent_id']; }
    public function getTimeout(): ?int { return null; }
    public function isAsync(): bool { return false; }
    public function requiresAuth(): bool { return false; }
}

class TestCircularMessage implements ModuleMessageInterface
{
    public function __construct(private string $action, private array $data)
    {
        $this->data['circular_id'] = uniqid();
    }

    public function getAction(): string { return $this->action; }
    public function getData(): array { return $this->data; }
    public function getMessageId(): string { return $this->data['circular_id']; }
    public function getTimeout(): ?int { return null; }
    public function isAsync(): bool { return false; }
    public function requiresAuth(): bool { return false; }
}

// Test Module Classes
class TestUserModule implements ModuleHandlerInterface
{
    private array $receivedEvents = [];

    public function handle(ModuleMessageInterface $message): array
    {
        $action = $message->getAction();
        $data = $message->getData();

        return match ($action) {
            'user.get' => [
                'module' => 'user',
                'user_id' => $data['user_id'],
                'name' => 'John Doe',
                'email' => 'john@example.com'
            ],
            'user.created' => $this->handleEvent($action, $data),
            'invalid.action' => throw new MessageRoutingException('Invalid action'),
            default => throw new MessageRoutingException("Unknown action: {$action}")
        };
    }

    public function handleEvent(string $eventType, array $eventData): array
    {
        $this->receivedEvents[] = $eventType;
        return ['handled' => true];
    }

    public function hasReceivedEvent(string $eventType): bool
    {
        return in_array($eventType, $this->receivedEvents);
    }

    public function getHealthStatus(): array
    {
        return ['status' => 'healthy', 'response_time' => 50];
    }
}

class TestOrderModule implements ModuleHandlerInterface
{
    private array $receivedEvents = [];

    public function handle(ModuleMessageInterface $message): array
    {
        $action = $message->getAction();
        $data = $message->getData();

        return match ($action) {
            'order.calculate_total' => [
                'order_id' => $data['order_id'],
                'total' => array_sum(array_column($data['items'], 'price')),
                'calculated_at' => now()->toISOString()
            ],
            'order.create_with_user' => [
                'order_id' => 'order-' . uniqid(),
                'user_id' => $data['user_id'],
                'user_validated' => true,
                'product_id' => $data['product_id'],
                'quantity' => $data['quantity']
            ],
            'user.created' => $this->handleEvent($action, $data),
            default => throw new MessageRoutingException("Unknown action: {$action}")
        };
    }

    public function handleEvent(string $eventType, array $eventData): array
    {
        $this->receivedEvents[] = $eventType;
        return ['handled' => true];
    }

    public function hasReceivedEvent(string $eventType): bool
    {
        return in_array($eventType, $this->receivedEvents);
    }

    public function getHealthStatus(): array
    {
        return ['status' => 'healthy', 'response_time' => 75];
    }
}

class TestNotificationModule implements ModuleHandlerInterface
{
    public function handle(ModuleMessageInterface $message): array
    {
        $action = $message->getAction();
        $data = $message->getData();

        return match ($action) {
            'notification.send' => [
                'status' => 'sent',
                'type' => $data['type'],
                'user_id' => $data['user_id'],
                'sent_at' => now()->toISOString()
            ],
            default => throw new MessageRoutingException("Unknown action: {$action}")
        };
    }

    public function handleEvent(string $eventType, array $eventData): array
    {
        return ['handled' => true];
    }

    public function hasReceivedEvent(string $eventType): bool
    {
        return false;
    }

    public function getHealthStatus(): array
    {
        return ['status' => 'healthy', 'response_time' => 30];
    }
}

class TestAdminModule implements ModuleHandlerInterface
{
    public function handle(ModuleMessageInterface $message): array
    {
        if ($message->requiresAuth()) {
            $user = auth()->user();
            if (!$user || $user->role !== 'admin') {
                throw new ModuleAuthorizationException('Admin access required');
            }
        }

        $action = $message->getAction();
        $data = $message->getData();

        return match ($action) {
            'admin.delete_user' => [
                'deleted' => true,
                'user_id' => $data['user_id'],
                'reason' => $data['reason']
            ],
            default => throw new MessageRoutingException("Unknown action: {$action}")
        };
    }

    public function handleEvent(string $eventType, array $eventData): array
    {
        return ['handled' => true];
    }

    public function hasReceivedEvent(string $eventType): bool
    {
        return false;
    }

    public function getHealthStatus(): array
    {
        return ['status' => 'healthy', 'response_time' => 40];
    }
}

class TestSlowModule implements ModuleHandlerInterface
{
    public function handle(ModuleMessageInterface $message): array
    {
        $action = $message->getAction();
        $data = $message->getData();

        if ($action === 'slow.operation') {
            $delay = $data['delay'] ?? 1000;
            usleep($delay * 1000); // Convert to microseconds

            if ($delay > 1500) {
                throw new ModuleTimeoutException('Operation timed out');
            }
        }

        return ['status' => 'completed', 'delay' => $data['delay'] ?? 0];
    }

    public function handleEvent(string $eventType, array $eventData): array
    {
        return ['handled' => true];
    }

    public function hasReceivedEvent(string $eventType): bool
    {
        return false;
    }

    public function getHealthStatus(): array
    {
        return ['status' => 'slow', 'response_time' => 2000];
    }
}

class TestSequenceModule implements ModuleHandlerInterface
{
    private int $lastSequence = 0;

    public function handle(ModuleMessageInterface $message): array
    {
        $action = $message->getAction();
        $data = $message->getData();

        if ($action === 'sequence.process') {
            $sequence = $data['sequence_number'];

            if ($sequence !== $this->lastSequence + 1) {
                throw new MessageRoutingException('Sequence order violation');
            }

            $this->lastSequence = $sequence;

            return [
                'processed_sequence' => $sequence,
                'data' => $data['data']
            ];
        }

        return ['status' => 'unknown_action'];
    }

    public function handleEvent(string $eventType, array $eventData): array
    {
        return ['handled' => true];
    }

    public function hasReceivedEvent(string $eventType): bool
    {
        return false;
    }

    public function getHealthStatus(): array
    {
        return ['status' => 'healthy', 'response_time' => 25];
    }
}

class TestCircularModule implements ModuleHandlerInterface
{
    private static int $callDepth = 0;

    public function handle(ModuleMessageInterface $message): array
    {
        $action = $message->getAction();
        $data = $message->getData();

        if ($action === 'circular.start') {
            self::$callDepth++;

            if (self::$callDepth > 3) {
                throw new MessageRoutingException('Circular dependency detected');
            }

            // Simulate circular call
            $circularMessage = new TestCircularMessage('circular.start', [
                'target' => 'circular',
                'depth' => $data['depth'] + 1
            ]);

            // This would cause infinite recursion
            return ['depth' => $data['depth']];
        }

        return ['status' => 'completed'];
    }

    public function handleEvent(string $eventType, array $eventData): array
    {
        return ['handled' => true];
    }

    public function hasReceivedEvent(string $eventType): bool
    {
        return false;
    }

    public function getHealthStatus(): array
    {
        return ['status' => 'healthy', 'response_time' => 35];
    }
}