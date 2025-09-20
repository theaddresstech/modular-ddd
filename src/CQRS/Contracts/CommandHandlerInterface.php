<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Contracts;

interface CommandHandlerInterface
{
    /**
     * Handle the command
     */
    public function handle(CommandInterface $command): mixed;

    /**
     * Get the command type this handler processes
     */
    public function getHandledCommandType(): string;

    /**
     * Check if handler supports the given command
     */
    public function canHandle(CommandInterface $command): bool;

    /**
     * Get handler priority (higher = preferred handler)
     */
    public function getPriority(): int;
}