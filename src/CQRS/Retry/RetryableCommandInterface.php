<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Retry;

interface RetryableCommandInterface
{
    /**
     * Get the retry policy for this command
     */
    public function getRetryPolicy(): RetryPolicyInterface;

    /**
     * Check if command execution should be retried for the given exception
     */
    public function shouldRetryForException(\Throwable $exception): bool;

    /**
     * Get maximum number of retry attempts for this command
     */
    public function getMaxRetryAttempts(): int;
}