<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Contracts;

interface CommandBusInterface
{
    /**
     * Dispatch a command synchronously
     */
    public function dispatch(CommandInterface $command): mixed;

    /**
     * Dispatch a command asynchronously
     */
    public function dispatchAsync(CommandInterface $command): string;

    /**
     * Queue a command for later processing
     */
    public function queue(CommandInterface $command, ?string $queue = null): string;

    /**
     * Register a command handler
     */
    public function registerHandler(CommandHandlerInterface $handler): void;

    /**
     * Add middleware to the command processing pipeline
     */
    public function addMiddleware(MiddlewareInterface $middleware): void;

    /**
     * Get handler for command
     */
    public function getHandler(CommandInterface $command): CommandHandlerInterface;

    /**
     * Check if command can be handled
     */
    public function canHandle(CommandInterface $command): bool;
}