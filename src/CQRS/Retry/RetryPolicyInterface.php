<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Retry;

interface RetryPolicyInterface
{
    /**
     * Determine if command should be retried
     */
    public function shouldRetry(\Throwable $exception, int $attempt): bool;

    /**
     * Calculate delay before next retry in milliseconds
     */
    public function getRetryDelay(int $attempt): int;

    /**
     * Get maximum number of retry attempts
     */
    public function getMaxAttempts(): int;

    /**
     * Get retry policy name
     */
    public function getName(): string;

    /**
     * Check if exception type is retryable
     */
    public function isRetryableException(\Throwable $exception): bool;
}