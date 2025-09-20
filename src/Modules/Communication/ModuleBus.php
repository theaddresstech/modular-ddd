<?php

declare(strict_types=1);

namespace LaravelModularDDD\Modules\Communication;

use LaravelModularDDD\Modules\Communication\Contracts\ModuleBusInterface;
use LaravelModularDDD\Modules\Communication\Exceptions\MessageDeliveryException;
use LaravelModularDDD\Modules\Communication\Exceptions\ModuleBusException;
use LaravelModularDDD\CQRS\Contracts\CommandBusInterface;
use LaravelModularDDD\CQRS\Contracts\QueryBusInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

class ModuleBus implements ModuleBusInterface
{
    private array $messageHandlers = [];
    private array $eventSubscribers = [];
    private array $statistics = [
        'messages_sent' => 0,
        'messages_failed' => 0,
        'events_published' => 0,
        'events_failed' => 0,
        'handlers_registered' => 0,
        'subscribers_registered' => 0,
    ];
    private array $pendingMessages = [];
    private array $pendingEvents = [];

    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
        private readonly Dispatcher $eventDispatcher,
        private readonly Queue $queue,
        private readonly LoggerInterface $logger,
        private readonly array $config = []
    ) {}

    public function send(ModuleMessage $message): mixed
    {
        try {
            $this->statistics['messages_sent']++;

            if (!$this->canDeliver($message)) {
                throw MessageDeliveryException::noHandlerFound($message);
            }

            $result = $this->routeMessage($message);

            $this->logger->info('Module message sent successfully', [
                'message_id' => $message->getId(),
                'source' => $message->getSourceModule(),
                'target' => $message->getTargetModule(),
                'type' => $message->getMessageType(),
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->statistics['messages_failed']++;

            $this->logger->error('Failed to send module message', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
                'message' => $message->toArray(),
            ]);

            throw ModuleBusException::messageSendFailed($message, $e);
        }
    }

    public function sendAsync(ModuleMessage $message): string
    {
        $jobId = Str::uuid()->toString();

        $this->pendingMessages[$jobId] = $message;

        $this->queue->push(Jobs\ModuleMessageJob::class, [
            'job_id' => $jobId,
            'message' => $message->toArray(),
        ]);

        $this->logger->info('Module message queued for async processing', [
            'job_id' => $jobId,
            'message_id' => $message->getId(),
        ]);

        return $jobId;
    }

    public function publish(ModuleEvent $event): void
    {
        try {
            $this->statistics['events_published']++;

            $this->dispatchToSubscribers($event);
            $this->eventDispatcher->dispatch('module.event.published', $event);

            $this->logger->info('Module event published successfully', [
                'event_id' => $event->getId(),
                'source' => $event->getSourceModule(),
                'type' => $event->getEventType(),
            ]);
        } catch (\Exception $e) {
            $this->statistics['events_failed']++;

            $this->logger->error('Failed to publish module event', [
                'event_id' => $event->getId(),
                'error' => $e->getMessage(),
                'event' => $event->toArray(),
            ]);

            throw ModuleBusException::eventPublishFailed($event, $e);
        }
    }

    public function publishAsync(ModuleEvent $event): string
    {
        $jobId = Str::uuid()->toString();

        $this->pendingEvents[$jobId] = $event;

        $this->queue->push(Jobs\ModuleEventJob::class, [
            'job_id' => $jobId,
            'event' => $event->toArray(),
        ]);

        $this->logger->info('Module event queued for async processing', [
            'job_id' => $jobId,
            'event_id' => $event->getId(),
        ]);

        return $jobId;
    }

    public function subscribe(string $eventType, callable $handler): void
    {
        if (!isset($this->eventSubscribers[$eventType])) {
            $this->eventSubscribers[$eventType] = [];
        }

        $this->eventSubscribers[$eventType][] = $handler;
        $this->statistics['subscribers_registered']++;

        $this->logger->debug('Event subscriber registered', [
            'event_type' => $eventType,
            'handler' => $this->getHandlerSignature($handler),
        ]);
    }

    public function unsubscribe(string $eventType, callable $handler): void
    {
        if (!isset($this->eventSubscribers[$eventType])) {
            return;
        }

        $handlerSignature = $this->getHandlerSignature($handler);

        $this->eventSubscribers[$eventType] = array_filter(
            $this->eventSubscribers[$eventType],
            fn($subscriber) => $this->getHandlerSignature($subscriber) !== $handlerSignature
        );

        if (empty($this->eventSubscribers[$eventType])) {
            unset($this->eventSubscribers[$eventType]);
        }

        $this->logger->debug('Event subscriber unregistered', [
            'event_type' => $eventType,
            'handler' => $handlerSignature,
        ]);
    }

    public function registerHandler(string $modulePattern, callable $handler): void
    {
        $this->messageHandlers[$modulePattern] = $handler;
        $this->statistics['handlers_registered']++;

        $this->logger->debug('Message handler registered', [
            'module_pattern' => $modulePattern,
            'handler' => $this->getHandlerSignature($handler),
        ]);
    }

    public function removeHandler(string $modulePattern): void
    {
        if (isset($this->messageHandlers[$modulePattern])) {
            unset($this->messageHandlers[$modulePattern]);

            $this->logger->debug('Message handler removed', [
                'module_pattern' => $modulePattern,
            ]);
        }
    }

    public function canDeliver(ModuleMessage $message): bool
    {
        $targetModule = $message->getTargetModule();

        // Check for exact match first
        if (isset($this->messageHandlers[$targetModule])) {
            return true;
        }

        // Check for pattern matches
        foreach ($this->messageHandlers as $pattern => $handler) {
            if ($this->matchesPattern($targetModule, $pattern)) {
                return true;
            }
        }

        // Check if it's a command or query that can be routed to existing buses
        if ($message->isCommand() || $message->isQuery()) {
            return true;
        }

        return false;
    }

    public function getStatistics(): array
    {
        return array_merge($this->statistics, [
            'pending_messages' => count($this->pendingMessages),
            'pending_events' => count($this->pendingEvents),
            'registered_handlers' => count($this->messageHandlers),
            'registered_subscribers' => array_sum(array_map('count', $this->eventSubscribers)),
        ]);
    }

    public function flush(): void
    {
        $this->pendingMessages = [];
        $this->pendingEvents = [];

        $this->logger->info('Module bus flushed', [
            'action' => 'flush_pending_operations',
        ]);
    }

    private function routeMessage(ModuleMessage $message): mixed
    {
        $targetModule = $message->getTargetModule();

        // Try direct handler first
        $handler = $this->findHandler($targetModule);
        if ($handler) {
            return $handler($message);
        }

        // Route to appropriate bus based on message type
        if ($message->isCommand()) {
            return $this->commandBus->handle($this->convertToCommand($message));
        }

        if ($message->isQuery()) {
            return $this->queryBus->handle($this->convertToQuery($message));
        }

        throw MessageDeliveryException::noHandlerFound($message);
    }

    private function findHandler(string $targetModule): ?callable
    {
        // Exact match
        if (isset($this->messageHandlers[$targetModule])) {
            return $this->messageHandlers[$targetModule];
        }

        // Pattern match
        foreach ($this->messageHandlers as $pattern => $handler) {
            if ($this->matchesPattern($targetModule, $pattern)) {
                return $handler;
            }
        }

        return null;
    }

    private function matchesPattern(string $target, string $pattern): bool
    {
        // Simple glob-style pattern matching
        $pattern = str_replace(['*', '?'], ['.*', '.'], $pattern);
        return (bool) preg_match("/^{$pattern}$/", $target);
    }

    private function dispatchToSubscribers(ModuleEvent $event): void
    {
        $eventType = $event->getEventType();

        // Dispatch to exact type subscribers
        if (isset($this->eventSubscribers[$eventType])) {
            foreach ($this->eventSubscribers[$eventType] as $handler) {
                try {
                    $handler($event);
                } catch (\Exception $e) {
                    $this->logger->error('Event subscriber failed', [
                        'event_id' => $event->getId(),
                        'error' => $e->getMessage(),
                        'handler' => $this->getHandlerSignature($handler),
                    ]);
                }
            }
        }

        // Dispatch to wildcard subscribers
        if (isset($this->eventSubscribers['*'])) {
            foreach ($this->eventSubscribers['*'] as $handler) {
                try {
                    $handler($event);
                } catch (\Exception $e) {
                    $this->logger->error('Wildcard event subscriber failed', [
                        'event_id' => $event->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function convertToCommand(ModuleMessage $message): object
    {
        // This would need to be implemented based on your command structure
        // For now, return a generic command object
        return new class($message) {
            public function __construct(private ModuleMessage $message) {}
            public function getMessage(): ModuleMessage { return $this->message; }
        };
    }

    private function convertToQuery(ModuleMessage $message): object
    {
        // This would need to be implemented based on your query structure
        // For now, return a generic query object
        return new class($message) {
            public function __construct(private ModuleMessage $message) {}
            public function getMessage(): ModuleMessage { return $this->message; }
        };
    }

    private function getHandlerSignature(callable $handler): string
    {
        if (is_array($handler)) {
            return is_object($handler[0])
                ? get_class($handler[0]) . '::' . $handler[1]
                : $handler[0] . '::' . $handler[1];
        }

        if (is_object($handler)) {
            return get_class($handler);
        }

        return (string) $handler;
    }
}