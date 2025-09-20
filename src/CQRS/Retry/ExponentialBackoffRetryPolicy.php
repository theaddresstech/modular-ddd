<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Retry;

class ExponentialBackoffRetryPolicy implements RetryPolicyInterface
{
    private array $retryableExceptions;

    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 1000,
        private readonly float $multiplier = 2.0,
        private readonly int $maxDelayMs = 30000,
        private readonly float $jitter = 0.1,
        array $retryableExceptions = []
    ) {
        $this->retryableExceptions = $retryableExceptions ?: [
            \PDOException::class,
            \Illuminate\Database\QueryException::class,
            \GuzzleHttp\Exception\ConnectException::class,
            \GuzzleHttp\Exception\RequestException::class,
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
        if ($attempt === 0) {
            return 0;
        }

        // Calculate exponential backoff
        $delay = $this->baseDelayMs * pow($this->multiplier, $attempt - 1);

        // Apply maximum delay cap
        $delay = min($delay, $this->maxDelayMs);

        // Add jitter to prevent thundering herd
        $jitterRange = $delay * $this->jitter;
        $jitter = mt_rand(-$jitterRange, $jitterRange);

        return (int) max(0, $delay + $jitter);
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getName(): string
    {
        return 'exponential_backoff';
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

    public function withMaxAttempts(int $maxAttempts): self
    {
        return new self(
            $maxAttempts,
            $this->baseDelayMs,
            $this->multiplier,
            $this->maxDelayMs,
            $this->jitter,
            $this->retryableExceptions
        );
    }

    public function withBaseDelay(int $baseDelayMs): self
    {
        return new self(
            $this->maxAttempts,
            $baseDelayMs,
            $this->multiplier,
            $this->maxDelayMs,
            $this->jitter,
            $this->retryableExceptions
        );
    }

    public function withRetryableExceptions(array $exceptions): self
    {
        return new self(
            $this->maxAttempts,
            $this->baseDelayMs,
            $this->multiplier,
            $this->maxDelayMs,
            $this->jitter,
            $exceptions
        );
    }
}