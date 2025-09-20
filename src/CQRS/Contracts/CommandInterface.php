<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Contracts;

interface CommandInterface
{
    /**
     * Get unique command identifier
     */
    public function getCommandId(): string;

    /**
     * Get command name/type
     */
    public function getCommandName(): string;

    /**
     * Get command validation rules
     */
    public function getValidationRules(): array;

    /**
     * Get command metadata
     */
    public function getMetadata(): array;

    /**
     * Get command priority (higher = more priority)
     */
    public function getPriority(): int;

    /**
     * Get command timeout in seconds
     */
    public function getTimeout(): int;

    /**
     * Check if command should be retried on failure
     */
    public function shouldRetry(): bool;

    /**
     * Get maximum retry attempts
     */
    public function getMaxRetries(): int;

    /**
     * Convert command to array for serialization
     */
    public function toArray(): array;
}