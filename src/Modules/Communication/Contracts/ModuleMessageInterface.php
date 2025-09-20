<?php

declare(strict_types=1);

namespace LaravelModularDDD\Modules\Communication\Contracts;

/**
 * Interface for module messages.
 *
 * Defines the contract for messages that can be sent between modules.
 */
interface ModuleMessageInterface
{
    /**
     * Get the action/type of the message.
     */
    public function getAction(): string;

    /**
     * Get the message data/payload.
     */
    public function getData(): array;

    /**
     * Get the unique message identifier.
     */
    public function getMessageId(): string;

    /**
     * Get the timeout for message processing.
     */
    public function getTimeout(): ?int;

    /**
     * Check if the message should be processed asynchronously.
     */
    public function isAsync(): bool;

    /**
     * Check if the message requires authentication.
     */
    public function requiresAuth(): bool;
}