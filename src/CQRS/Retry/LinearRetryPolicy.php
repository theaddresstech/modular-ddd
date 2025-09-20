<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Retry;

class LinearRetryPolicy implements RetryPolicyInterface
{
    private array $retryableExceptions;

    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly int $delayMs = 1000,
        array $retryableExceptions = []
    ) {
        $this->retryableExceptions = $retryableExceptions ?: [
            \PDOException::class,
            \Illuminate\Database\QueryException::class,
            \RuntimeException::class,
        ];
    }

    public function shouldRetry(\Throwable $exception, int $attempt): bool
    {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }

        return $this->isRetryableException($exception);
    }

    public function getRetryDelay(int $attempt): int
    {
        return $attempt > 0 ? $this->delayMs : 0;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getName(): string
    {
        return 'linear';
    }

    public function isRetryableException(\Throwable $exception): bool
    {
        foreach ($this->retryableExceptions as $retryableType) {
            if ($exception instanceof $retryableType) {
                return true;
            }
        }

        return false;
    }
}