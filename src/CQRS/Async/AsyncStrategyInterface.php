<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Async;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;

interface AsyncStrategyInterface
{
    /**
     * Dispatch a command asynchronously
     */
    public function dispatch(CommandInterface $command): string;

    /**
     * Get the status of an async command
     */
    public function getStatus(string $id): AsyncStatus;

    /**
     * Get the result of a completed async command
     */
    public function getResult(string $id): mixed;

    /**
     * Cancel a pending async command
     */
    public function cancel(string $id): bool;

    /**
     * Check if the strategy supports the given command
     */
    public function supports(CommandInterface $command): bool;

    /**
     * Get strategy name for identification
     */
    public function getName(): string;
}