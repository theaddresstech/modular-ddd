<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Middleware;

use LaravelModularDDD\CQRS\Contracts\MiddlewareInterface;
use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\Core\Domain\Contracts\AggregateRootInterface;
use Closure;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

class EventDispatchingMiddleware implements MiddlewareInterface
{
    public function handle(mixed $message, Closure $next): mixed
    {
        $result = $next($message);

        if ($message instanceof CommandInterface) {
            $this->dispatchDomainEvents($result, $message);
        }

        return $result;
    }

    public function getPriority(): int
    {
        return 10; // Low priority - dispatch events after command execution
    }

    public function shouldProcess(mixed $message): bool
    {
        return $message instanceof CommandInterface;
    }

    private function dispatchDomainEvents(mixed $result, CommandInterface $command): void
    {
        $events = [];

        // Extract events from aggregate roots
        if ($result instanceof AggregateRootInterface) {
            $events = $result->pullDomainEvents();
        } elseif (is_array($result)) {
            // Handle multiple aggregates
            foreach ($result as $item) {
                if ($item instanceof AggregateRootInterface) {
                    $events = array_merge($events, $item->pullDomainEvents());
                }
            }
        }

        if (empty($events)) {
            return;
        }

        Log::info('Dispatching domain events from command', [
            'command_id' => $command->getCommandId(),
            'command_type' => $command->getCommandName(),
            'event_count' => count($events),
        ]);

        // Dispatch each domain event
        foreach ($events as $event) {
            try {
                Event::dispatch($event);

                Log::debug('Domain event dispatched', [
                    'command_id' => $command->getCommandId(),
                    'event_type' => get_class($event),
                    'event_id' => $event->getEventId(),
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to dispatch domain event', [
                    'command_id' => $command->getCommandId(),
                    'event_type' => get_class($event),
                    'event_id' => $event->getEventId(),
                    'error' => $e->getMessage(),
                ]);

                // Don't rethrow - event dispatching failures shouldn't break command processing
            }
        }
    }
}