<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Integration\ModuleCommunication;

use LaravelModularDDD\Tests\TestCase;
use LaravelModularDDD\Tests\Integration\ModuleCommunication\Traits\ModuleCommunicationTestingTrait;
use LaravelModularDDD\Tests\Integration\ModuleCommunication\Factories\TestModuleFactory;
use LaravelModularDDD\Tests\Integration\ModuleCommunication\TestModules\UserModule;
use LaravelModularDDD\Tests\Integration\ModuleCommunication\TestModules\OrderModule;
use LaravelModularDDD\Tests\Integration\ModuleCommunication\TestModules\NotificationModule;
use LaravelModularDDD\Core\Communication\MessageBus\MessageBus;
use LaravelModularDDD\Core\Communication\EventBus\EventBus;
use LaravelModularDDD\Core\Communication\Contracts\MessageInterface;
use LaravelModularDDD\Core\Communication\Contracts\EventInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Integration tests for inter-module communication functionality.
 *
 * Tests the complete module communication flow including:
 * - Inter-module message routing
 * - Event publishing and subscription
 * - Async message/event processing
 * - Error handling and retry mechanisms
 * - Cross-module transaction coordination
 * - Message ordering and delivery guarantees
 *
 * @group integration
 * @group module-communication
 */
class ModuleCommunicationIntegrationTest extends TestCase
{
    use RefreshDatabase, ModuleCommunicationTestingTrait;

    private MessageBus $messageBus;
    private EventBus $eventBus;
    private TestModuleFactory $moduleFactory;
    private UserModule $userModule;
    private OrderModule $orderModule;
    private NotificationModule $notificationModule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpModuleCommunicationInfrastructure();
        $this->moduleFactory = new TestModuleFactory();
        $this->initializeTestModules();
    }

    protected function tearDown(): void
    {
        $this->cleanupModuleCommunicationData();
        parent::tearDown();
    }

    /**
     * @test
     * @group message-routing
     */
    public function test_it_routes_messages_between_modules_correctly(): void
    {
        // Arrange
        $userCreatedMessage = $this->moduleFactory->createUserCreatedMessage([
            'user_id' => 'user_123',
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);

        // Act: Send message from User module to Order module
        $this->messageBus->send($userCreatedMessage, 'order');

        // Process any queued messages
        $this->processQueuedMessages();

        // Assert: Order module received and processed the message
        $this->assertTrue($this->orderModule->hasReceivedMessage($userCreatedMessage->getMessageId()));

        // Verify message was processed correctly
        $processedMessage = $this->orderModule->getProcessedMessage($userCreatedMessage->getMessageId());
        $this->assertEquals('user_123', $processedMessage['user_id']);
        $this->assertEquals('UserCreated', $processedMessage['message_type']);
    }

    /**
     * @test
     * @group event-publishing
     */
    public function test_it_publishes_and_subscribes_to_events_across_modules(): void
    {
        // Arrange
        $orderPlacedEvent = $this->moduleFactory->createOrderPlacedEvent([
            'order_id' => 'order_456',
            'user_id' => 'user_123',
            'total_amount' => 99.99,
            'items' => [
                ['product_id' => 'prod_1', 'quantity' => 2, 'price' => 29.99],
                ['product_id' => 'prod_2', 'quantity' => 1, 'price' => 39.99],
            ],
        ]);

        // Act: Publish event from Order module
        $this->eventBus->publish($orderPlacedEvent);

        // Process any queued events
        $this->processQueuedEvents();

        // Assert: Multiple modules subscribed to the event
        $this->assertTrue($this->userModule->hasReceivedEvent($orderPlacedEvent->getEventId()));
        $this->assertTrue($this->notificationModule->hasReceivedEvent($orderPlacedEvent->getEventId()));

        // Verify each module processed the event appropriately
        $userEventData = $this->userModule->getProcessedEvent($orderPlacedEvent->getEventId());
        $this->assertEquals('order_456', $userEventData['order_id']);

        $notificationEventData = $this->notificationModule->getProcessedEvent($orderPlacedEvent->getEventId());
        $this->assertEquals('order_confirmation', $notificationEventData['notification_type']);
    }

    /**
     * @test
     * @group async-processing
     */
    public function test_it_handles_async_message_processing_correctly(): void
    {
        // Arrange
        Queue::fake();

        $heavyProcessingMessage = $this->moduleFactory->createHeavyProcessingMessage([
            'operation_type' => 'bulk_user_import',
            'data_size' => 10000,
            'async' => true,
        ]);

        // Act: Send async message
        $this->messageBus->sendAsync($heavyProcessingMessage, 'user');

        // Assert: Message was queued
        Queue::assertPushed(\LaravelModularDDD\Core\Communication\Jobs\ProcessMessageJob::class);

        // Process the queue manually for testing
        $job = new \LaravelModularDDD\Core\Communication\Jobs\ProcessMessageJob(
            $heavyProcessingMessage,
            'user'
        );
        $job->handle($this->messageBus);

        // Verify message was processed asynchronously
        $this->assertTrue($this->userModule->hasReceivedMessage($heavyProcessingMessage->getMessageId()));
    }

    /**
     * @test
     * @group error-handling
     */
    public function test_it_handles_message_processing_errors_with_retry_mechanism(): void
    {
        // Arrange
        $failingMessage = $this->moduleFactory->createFailingMessage([
            'should_fail' => true,
            'max_retries' => 3,
        ]);

        // Configure order module to fail initially
        $this->orderModule->setShouldFail(true, 2); // Fail first 2 attempts

        // Act: Send message that will initially fail
        $this->messageBus->send($failingMessage, 'order');

        // Process with retries
        $this->processMessageWithRetries($failingMessage, 'order', 3);

        // Assert: Message eventually succeeded after retries
        $this->assertTrue($this->orderModule->hasReceivedMessage($failingMessage->getMessageId()));

        // Verify retry attempts were logged
        $retryCount = $this->orderModule->getRetryCount($failingMessage->getMessageId());
        $this->assertEquals(2, $retryCount); // Failed twice, succeeded on third attempt
    }

    /**
     * @test
     * @group cross-module-transactions
     */
    public function test_it_coordinates_cross_module_transactions(): void
    {
        // Arrange
        $transactionMessage = $this->moduleFactory->createTransactionMessage([
            'transaction_id' => 'txn_789',
            'operations' => [
                ['module' => 'user', 'action' => 'update_balance', 'amount' => -50.00],
                ['module' => 'order', 'action' => 'create_order', 'amount' => 50.00],
            ],
        ]);

        // Act: Execute cross-module transaction
        $this->messageBus->executeTransaction($transactionMessage);

        // Assert: Both modules participated in transaction
        $this->assertTrue($this->userModule->hasParticipatedInTransaction('txn_789'));
        $this->assertTrue($this->orderModule->hasParticipatedInTransaction('txn_789'));

        // Verify transaction state consistency
        $userTxnState = $this->userModule->getTransactionState('txn_789');
        $orderTxnState = $this->orderModule->getTransactionState('txn_789');

        $this->assertEquals('committed', $userTxnState['status']);
        $this->assertEquals('committed', $orderTxnState['status']);
    }

    /**
     * @test
     * @group cross-module-rollback
     */
    public function test_it_handles_cross_module_transaction_rollback(): void
    {
        // Arrange
        $failingTransactionMessage = $this->moduleFactory->createTransactionMessage([
            'transaction_id' => 'txn_fail_456',
            'operations' => [
                ['module' => 'user', 'action' => 'update_balance', 'amount' => -100.00],
                ['module' => 'order', 'action' => 'create_order', 'amount' => 100.00, 'should_fail' => true],
            ],
        ]);

        // Configure order module to fail the transaction
        $this->orderModule->setShouldFailTransaction(true);

        // Act & Assert: Transaction should fail and rollback
        $this->expectException(\LaravelModularDDD\Core\Communication\Exceptions\TransactionFailedException::class);

        try {
            $this->messageBus->executeTransaction($failingTransactionMessage);
        } catch (\Exception $e) {
            // Verify rollback occurred in both modules
            $userTxnState = $this->userModule->getTransactionState('txn_fail_456');
            $orderTxnState = $this->orderModule->getTransactionState('txn_fail_456');

            $this->assertEquals('rolled_back', $userTxnState['status']);
            $this->assertEquals('rolled_back', $orderTxnState['status']);

            throw $e;
        }
    }

    /**
     * @test
     * @group message-ordering
     */
    public function test_it_maintains_message_ordering_within_module(): void
    {
        // Arrange
        $messages = [];
        for ($i = 1; $i <= 5; $i++) {
            $messages[] = $this->moduleFactory->createSequentialMessage([
                'sequence_number' => $i,
                'user_id' => 'user_123',
                'action' => "step_{$i}",
            ]);
        }

        // Act: Send messages in sequence
        foreach ($messages as $message) {
            $this->messageBus->send($message, 'user');
        }

        $this->processQueuedMessages();

        // Assert: Messages were processed in order
        $processedSequence = $this->userModule->getProcessedSequence('user_123');
        $expectedSequence = [1, 2, 3, 4, 5];

        $this->assertEquals($expectedSequence, $processedSequence);
    }

    /**
     * @test
     * @group delivery-guarantees
     */
    public function test_it_provides_at_least_once_delivery_guarantees(): void
    {
        // Arrange
        $criticalMessage = $this->moduleFactory->createCriticalMessage([
            'message_id' => 'critical_msg_123',
            'priority' => 'high',
            'delivery_guarantee' => 'at_least_once',
        ]);

        // Simulate message delivery failure and recovery
        $this->orderModule->simulateTemporaryFailure();

        // Act: Send critical message
        $this->messageBus->send($criticalMessage, 'order');

        // Process with persistence and retry
        $this->processMessageWithPersistence($criticalMessage, 'order');

        // Assert: Message was eventually delivered despite failures
        $this->assertTrue($this->orderModule->hasReceivedMessage($criticalMessage->getMessageId()));

        // Verify delivery attempt history
        $deliveryAttempts = $this->messageBus->getDeliveryAttempts($criticalMessage->getMessageId());
        $this->assertGreaterThan(1, count($deliveryAttempts));
    }

    /**
     * @test
     * @group event-replay
     */
    public function test_it_supports_event_replay_for_module_recovery(): void
    {
        // Arrange: Publish several events
        $events = [];
        for ($i = 1; $i <= 3; $i++) {
            $event = $this->moduleFactory->createTestEvent([
                'event_number' => $i,
                'data' => "test_data_{$i}",
            ]);
            $events[] = $event;
            $this->eventBus->publish($event);
        }

        $this->processQueuedEvents();

        // Simulate notification module going offline and missing events
        $this->notificationModule->reset();

        // Act: Replay events for notification module
        $this->eventBus->replayEvents('notification', $events[0]->getOccurredAt());

        // Assert: All events were replayed to notification module
        foreach ($events as $event) {
            $this->assertTrue($this->notificationModule->hasReceivedEvent($event->getEventId()));
        }
    }

    /**
     * @test
     * @group performance
     */
    public function test_it_handles_high_volume_message_processing(): void
    {
        // Arrange
        $messageCount = 100;
        $messages = [];

        for ($i = 1; $i <= $messageCount; $i++) {
            $messages[] = $this->moduleFactory->createBulkMessage([
                'batch_id' => 'batch_001',
                'message_index' => $i,
                'data' => "bulk_data_{$i}",
            ]);
        }

        // Act: Send all messages
        $startTime = microtime(true);

        foreach ($messages as $message) {
            $this->messageBus->send($message, 'user');
        }

        $this->processBulkMessages();

        $processingTime = microtime(true) - $startTime;

        // Assert: All messages processed within reasonable time
        $this->assertLessThan(5.0, $processingTime); // Should complete in under 5 seconds

        // Verify all messages were processed
        $processedCount = $this->userModule->getProcessedMessageCount('batch_001');
        $this->assertEquals($messageCount, $processedCount);
    }

    /**
     * @test
     * @group module-isolation
     */
    public function test_it_maintains_module_isolation_and_independence(): void
    {
        // Arrange
        $userMessage = $this->moduleFactory->createUserSpecificMessage([
            'user_id' => 'user_123',
            'sensitive_data' => 'private_info',
        ]);

        // Configure user module to fail
        $this->userModule->setShouldFail(true);

        // Act: Send message to failing user module
        try {
            $this->messageBus->send($userMessage, 'user');
        } catch (\Exception $e) {
            // Expected failure
        }

        // Send different message to order module
        $orderMessage = $this->moduleFactory->createOrderMessage([
            'order_id' => 'order_456',
            'status' => 'pending',
        ]);

        $this->messageBus->send($orderMessage, 'order');
        $this->processQueuedMessages();

        // Assert: Order module operates independently of user module failure
        $this->assertTrue($this->orderModule->hasReceivedMessage($orderMessage->getMessageId()));
        $this->assertFalse($this->userModule->hasReceivedMessage($userMessage->getMessageId()));

        // Verify modules remain isolated
        $this->assertFalse($this->orderModule->hasAccessToMessage($userMessage->getMessageId()));
    }

    /**
     * @test
     * @group circuit-breaker
     */
    public function test_it_implements_circuit_breaker_for_failing_modules(): void
    {
        // Arrange
        $messages = [];
        for ($i = 1; $i <= 10; $i++) {
            $messages[] = $this->moduleFactory->createTestMessage([
                'message_id' => "msg_{$i}",
                'data' => "test_{$i}",
            ]);
        }

        // Configure order module to fail consistently
        $this->orderModule->setShouldFail(true, 10); // Fail all attempts

        // Act: Send messages that will trigger circuit breaker
        $failureCount = 0;
        foreach ($messages as $message) {
            try {
                $this->messageBus->send($message, 'order');
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        // Assert: Circuit breaker opened after threshold failures
        $circuitState = $this->messageBus->getCircuitState('order');
        $this->assertEquals('open', $circuitState['state']);
        $this->assertGreaterThanOrEqual(5, $failureCount); // Threshold should be around 5

        // Test circuit breaker recovery
        $this->orderModule->setShouldFail(false);
        sleep(1); // Wait for half-open state

        $recoveryMessage = $this->moduleFactory->createTestMessage([
            'message_id' => 'recovery_msg',
            'data' => 'recovery_test',
        ]);

        $this->messageBus->send($recoveryMessage, 'order');

        // Assert: Circuit recovered and message processed
        $this->assertTrue($this->orderModule->hasReceivedMessage($recoveryMessage->getMessageId()));
    }

    /**
     * @test
     * @group message-deduplication
     */
    public function test_it_handles_message_deduplication(): void
    {
        // Arrange
        $duplicateMessage = $this->moduleFactory->createDuplicateMessage([
            'message_id' => 'duplicate_test_123',
            'user_id' => 'user_456',
            'action' => 'update_profile',
        ]);

        // Act: Send the same message multiple times
        $this->messageBus->send($duplicateMessage, 'user');
        $this->messageBus->send($duplicateMessage, 'user'); // Duplicate
        $this->messageBus->send($duplicateMessage, 'user'); // Another duplicate

        $this->processQueuedMessages();

        // Assert: Message was processed only once
        $processCount = $this->userModule->getMessageProcessCount($duplicateMessage->getMessageId());
        $this->assertEquals(1, $processCount);

        // Verify deduplication was logged
        $duplicationAttempts = $this->messageBus->getDuplicationAttempts($duplicateMessage->getMessageId());
        $this->assertEquals(2, $duplicationAttempts); // 2 duplicate attempts
    }

    private function setUpModuleCommunicationInfrastructure(): void
    {
        // Initialize message and event buses
        $this->messageBus = new MessageBus([
            'retry_attempts' => 3,
            'retry_delay_ms' => 100,
            'circuit_breaker_threshold' => 5,
            'enable_deduplication' => true,
        ]);

        $this->eventBus = new EventBus([
            'async_processing' => true,
            'replay_support' => true,
            'delivery_guarantee' => 'at_least_once',
        ]);

        // Set up message/event storage
        $this->createMessageEventTables();
    }

    private function initializeTestModules(): void
    {
        $this->userModule = new UserModule($this->messageBus, $this->eventBus);
        $this->orderModule = new OrderModule($this->messageBus, $this->eventBus);
        $this->notificationModule = new NotificationModule($this->messageBus, $this->eventBus);

        // Register modules with buses
        $this->messageBus->registerModule('user', $this->userModule);
        $this->messageBus->registerModule('order', $this->orderModule);
        $this->messageBus->registerModule('notification', $this->notificationModule);

        // Set up event subscriptions
        $this->eventBus->subscribe('OrderPlaced', $this->userModule);
        $this->eventBus->subscribe('OrderPlaced', $this->notificationModule);
        $this->eventBus->subscribe('UserCreated', $this->orderModule);
    }

    private function createMessageEventTables(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->create('module_messages', function ($table) {
            $table->id();
            $table->string('message_id')->unique();
            $table->string('source_module');
            $table->string('target_module');
            $table->string('message_type');
            $table->json('payload');
            $table->json('metadata');
            $table->string('status');
            $table->integer('retry_count')->default(0);
            $table->timestamp('sent_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['target_module', 'status']);
            $table->index(['message_type', 'sent_at']);
        });

        $this->app['db']->connection()->getSchemaBuilder()->create('module_events', function ($table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('event_type');
            $table->string('source_module');
            $table->json('payload');
            $table->json('metadata');
            $table->timestamp('occurred_at');
            $table->timestamp('published_at');
            $table->timestamps();

            $table->index(['event_type', 'occurred_at']);
            $table->index(['source_module', 'published_at']);
        });
    }

    private function cleanupModuleCommunicationData(): void
    {
        // Clear database tables
        if ($this->app['db']->connection()->getSchemaBuilder()->hasTable('module_messages')) {
            $this->app['db']->table('module_messages')->truncate();
        }

        if ($this->app['db']->connection()->getSchemaBuilder()->hasTable('module_events')) {
            $this->app['db']->table('module_events')->truncate();
        }

        // Clear caches
        Cache::flush();

        // Reset modules
        $this->userModule?->reset();
        $this->orderModule?->reset();
        $this->notificationModule?->reset();
    }
}