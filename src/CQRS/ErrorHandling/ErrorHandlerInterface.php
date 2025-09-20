<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\ErrorHandling;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;

interface ErrorHandlerInterface
{
    /**
     * Handle command execution error
     */
    public function handle(CommandInterface $command, \Throwable $exception, array $context = []): void;

    /**
     * Check if handler can handle this type of error
     */
    public function canHandle(\Throwable $exception): bool;

    /**
     * Get handler priority (higher = executed first)
     */
    public function getPriority(): int;

    /**
     * Get handler name
     */
    public function getName(): string;
}