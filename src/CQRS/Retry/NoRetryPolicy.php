<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Retry;

class NoRetryPolicy implements RetryPolicyInterface
{
    public function shouldRetry(\Throwable $exception, int $attempt): bool
    {
        return false;
    }

    public function getRetryDelay(int $attempt): int
    {
        return 0;
    }

    public function getMaxAttempts(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'no_retry';
    }

    public function isRetryableException(\Throwable $exception): bool
    {
        return false;
    }
}