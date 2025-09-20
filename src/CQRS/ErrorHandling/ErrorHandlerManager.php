<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\ErrorHandling;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use Illuminate\Support\Facades\Log;

class ErrorHandlerManager
{
    private array $handlers = [];

    public function __construct()
    {
        // Register default handlers
        $this->registerHandler(new LoggingErrorHandler());
    }

    /**
     * Register error handler
     */
    public function registerHandler(ErrorHandlerInterface $handler): void
    {
        $this->handlers[] = $handler;

        // Sort by priority (highest first)
        usort($this->handlers, fn($a, $b) => $b->getPriority() <=> $a->getPriority());
    }

    /**
     * Handle command execution error
     */
    public function handleError(CommandInterface $command, \Throwable $exception, array $context = []): void
    {
        $handledBy = [];

        foreach ($this->handlers as $handler) {
            if ($handler->canHandle($exception)) {
                try {
                    $handler->handle($command, $exception, $context);
                    $handledBy[] = $handler->getName();

                } catch (\Throwable $e) {
                    Log::error('Error handler failed', [
                        'handler' => $handler->getName(),
                        'original_error' => $exception->getMessage(),
                        'handler_error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if (empty($handledBy)) {
            Log::warning('No error handlers could handle exception', [
                'exception_type' => get_class($exception),
                'command_type' => get_class($command),
                'registered_handlers' => count($this->handlers),
            ]);
        } else {
            Log::debug('Error handled by handlers', [
                'handlers' => $handledBy,
                'command_type' => get_class($command),
                'exception_type' => get_class($exception),
            ]);
        }
    }

    /**
     * Get registered handlers
     */
    public function getHandlers(): array
    {
        return $this->handlers;
    }

    /**
     * Get handler statistics
     */
    public function getStatistics(): array
    {
        return [
            'total_handlers' => count($this->handlers),
            'handlers' => array_map(fn($h) => [
                'name' => $h->getName(),
                'priority' => $h->getPriority(),
            ], $this->handlers),
        ];
    }

    /**
     * Remove handler by name
     */
    public function removeHandler(string $handlerName): bool
    {
        $originalCount = count($this->handlers);

        $this->handlers = array_filter(
            $this->handlers,
            fn($handler) => $handler->getName() !== $handlerName
        );

        return count($this->handlers) < $originalCount;
    }

    /**
     * Clear all handlers
     */
    public function clearHandlers(): void
    {
        $this->handlers = [];
    }
}