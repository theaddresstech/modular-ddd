<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\ErrorHandling;

class RetryPolicy
{
    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 1000,
        private readonly float $multiplier = 2.0,
        private readonly int $maxDelayMs = 30000,
        private readonly array $retryableExceptions = []
    ) {}

    public function shouldRetry(\Throwable $exception, int $attempt): bool
    {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }

        return $this->isRetryableException($exception);
    }

    public function getRetryDelay(int $attempt): int
    {
        if ($attempt === 0) {
            return 0;
        }

        // Exponential backoff with jitter
        $delay = $this->baseDelayMs * pow($this->multiplier, $attempt - 1);
        $delay = min($delay, $this->maxDelayMs);

        // Add 10% jitter to prevent thundering herd
        $jitter = $delay * 0.1 * (mt_rand() / mt_getrandmax());

        return (int) ($delay + $jitter);
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    private function isRetryableException(\Throwable $exception): bool
    {
        if (empty($this->retryableExceptions)) {
            // Default retryable exceptions
            return $exception instanceof \PDOException ||
                   $exception instanceof \Illuminate\Database\QueryException ||
                   $exception instanceof \GuzzleHttp\Exception\ConnectException ||
                   $exception instanceof \RuntimeException;
        }

        foreach ($this->retryableExceptions as $retryableType) {
            if ($exception instanceof $retryableType) {
                return true;
            }
        }

        return false;
    }

    public static function exponentialBackoff(int $maxAttempts = 3, int $baseDelayMs = 1000): self
    {
        return new self($maxAttempts, $baseDelayMs, 2.0, 30000);
    }

    public static function linear(int $maxAttempts = 3, int $delayMs = 1000): self
    {
        return new self($maxAttempts, $delayMs, 1.0, $delayMs);
    }

    public static function noRetry(): self
    {
        return new self(1, 0, 1.0, 0);
    }
}