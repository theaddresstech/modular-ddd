<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\ErrorHandling;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use Illuminate\Support\Facades\Log;

class LoggingErrorHandler implements ErrorHandlerInterface
{
    public function __construct(
        private readonly string $logLevel = 'error',
        private readonly int $priority = 100
    ) {}

    public function handle(CommandInterface $command, \Throwable $exception, array $context = []): void
    {
        $logData = [
            'command_type' => get_class($command),
            'command_id' => method_exists($command, 'getId') ? $command->getId() : null,
            'error_message' => $exception->getMessage(),
            'error_code' => $exception->getCode(),
            'error_file' => $exception->getFile(),
            'error_line' => $exception->getLine(),
            'stack_trace' => $exception->getTraceAsString(),
            'context' => $context,
            'timestamp' => now()->toISOString(),
        ];

        Log::log($this->logLevel, 'Command execution failed', $logData);
    }

    public function canHandle(\Throwable $exception): bool
    {
        return true; // Log all exceptions
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getName(): string
    {
        return 'logging';
    }
}