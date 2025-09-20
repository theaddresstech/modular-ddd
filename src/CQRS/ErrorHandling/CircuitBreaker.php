<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\ErrorHandling;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    public function __construct(
        private readonly string $name,
        private readonly int $failureThreshold = 5,
        private readonly int $recoveryTimeoutSeconds = 60,
        private readonly int $requestVolumeThreshold = 10,
        private readonly float $errorPercentageThreshold = 50.0
    ) {}

    /**
     * Execute operation with circuit breaker protection
     */
    public function execute(callable $operation): mixed
    {
        $state = $this->getState();

        switch ($state) {
            case self::STATE_OPEN:
                if ($this->shouldAttemptReset()) {
                    $this->setState(self::STATE_HALF_OPEN);
                    return $this->executeOperation($operation);
                }
                throw new CircuitBreakerOpenException("Circuit breaker '{$this->name}' is open");

            case self::STATE_HALF_OPEN:
                return $this->executeOperation($operation);

            case self::STATE_CLOSED:
            default:
                return $this->executeOperation($operation);
        }
    }

    /**
     * Get current circuit breaker state
     */
    public function getState(): string
    {
        return Cache::get($this->getStateKey(), self::STATE_CLOSED);
    }

    /**
     * Get circuit breaker statistics
     */
    public function getStatistics(): array
    {
        return [
            'name' => $this->name,
            'state' => $this->getState(),
            'failure_count' => $this->getFailureCount(),
            'success_count' => $this->getSuccessCount(),
            'request_count' => $this->getRequestCount(),
            'error_percentage' => $this->getErrorPercentage(),
            'last_failure_time' => $this->getLastFailureTime(),
            'configuration' => [
                'failure_threshold' => $this->failureThreshold,
                'recovery_timeout_seconds' => $this->recoveryTimeoutSeconds,
                'request_volume_threshold' => $this->requestVolumeThreshold,
                'error_percentage_threshold' => $this->errorPercentageThreshold,
            ],
        ];
    }

    /**
     * Reset circuit breaker state
     */
    public function reset(): void
    {
        $this->setState(self::STATE_CLOSED);
        $this->resetCounters();

        Log::info("Circuit breaker '{$this->name}' has been reset");
    }

    /**
     * Force open the circuit breaker
     */
    public function forceOpen(): void
    {
        $this->setState(self::STATE_OPEN);
        $this->recordFailure();

        Log::warning("Circuit breaker '{$this->name}' has been forced open");
    }

    private function executeOperation(callable $operation): mixed
    {
        $startTime = microtime(true);

        try {
            $result = $operation();
            $this->recordSuccess();
            return $result;

        } catch (\Throwable $e) {
            $this->recordFailure();

            Log::warning("Circuit breaker '{$this->name}' recorded failure", [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);

            throw $e;
        }
    }

    private function recordSuccess(): void
    {
        $this->incrementSuccessCount();
        $this->incrementRequestCount();

        $state = $this->getState();

        if ($state === self::STATE_HALF_OPEN) {
            $this->setState(self::STATE_CLOSED);
            $this->resetCounters();

            Log::info("Circuit breaker '{$this->name}' closed after successful recovery");
        }
    }

    private function recordFailure(): void
    {
        $this->incrementFailureCount();
        $this->incrementRequestCount();
        $this->setLastFailureTime();

        $this->checkThresholds();
    }

    private function checkThresholds(): void
    {
        $state = $this->getState();
        $failureCount = $this->getFailureCount();
        $requestCount = $this->getRequestCount();
        $errorPercentage = $this->getErrorPercentage();

        if ($state !== self::STATE_OPEN) {
            $shouldOpen = false;

            // Check failure threshold
            if ($failureCount >= $this->failureThreshold) {
                $shouldOpen = true;
            }

            // Check error percentage (only if we have enough requests)
            if ($requestCount >= $this->requestVolumeThreshold &&
                $errorPercentage >= $this->errorPercentageThreshold) {
                $shouldOpen = true;
            }

            if ($shouldOpen) {
                $this->setState(self::STATE_OPEN);

                Log::error("Circuit breaker '{$this->name}' opened", [
                    'failure_count' => $failureCount,
                    'request_count' => $requestCount,
                    'error_percentage' => $errorPercentage,
                ]);
            }
        }
    }

    private function shouldAttemptReset(): bool
    {
        $lastFailureTime = $this->getLastFailureTime();

        if (!$lastFailureTime) {
            return true;
        }

        return (time() - $lastFailureTime) >= $this->recoveryTimeoutSeconds;
    }

    private function setState(string $state): void
    {
        Cache::put($this->getStateKey(), $state, 3600);
    }

    private function incrementFailureCount(): void
    {
        Cache::increment($this->getFailureCountKey(), 1);
        Cache::put($this->getFailureCountKey(), Cache::get($this->getFailureCountKey(), 0), 3600);
    }

    private function incrementSuccessCount(): void
    {
        Cache::increment($this->getSuccessCountKey(), 1);
        Cache::put($this->getSuccessCountKey(), Cache::get($this->getSuccessCountKey(), 0), 3600);
    }

    private function incrementRequestCount(): void
    {
        Cache::increment($this->getRequestCountKey(), 1);
        Cache::put($this->getRequestCountKey(), Cache::get($this->getRequestCountKey(), 0), 3600);
    }

    private function setLastFailureTime(): void
    {
        Cache::put($this->getLastFailureTimeKey(), time(), 3600);
    }

    private function resetCounters(): void
    {
        Cache::forget($this->getFailureCountKey());
        Cache::forget($this->getSuccessCountKey());
        Cache::forget($this->getRequestCountKey());
        Cache::forget($this->getLastFailureTimeKey());
    }

    private function getFailureCount(): int
    {
        return Cache::get($this->getFailureCountKey(), 0);
    }

    private function getSuccessCount(): int
    {
        return Cache::get($this->getSuccessCountKey(), 0);
    }

    private function getRequestCount(): int
    {
        return Cache::get($this->getRequestCountKey(), 0);
    }

    private function getLastFailureTime(): ?int
    {
        return Cache::get($this->getLastFailureTimeKey());
    }

    private function getErrorPercentage(): float
    {
        $requestCount = $this->getRequestCount();

        if ($requestCount === 0) {
            return 0.0;
        }

        $failureCount = $this->getFailureCount();
        return ($failureCount / $requestCount) * 100;
    }

    private function getStateKey(): string
    {
        return "circuit_breaker:{$this->name}:state";
    }

    private function getFailureCountKey(): string
    {
        return "circuit_breaker:{$this->name}:failures";
    }

    private function getSuccessCountKey(): string
    {
        return "circuit_breaker:{$this->name}:successes";
    }

    private function getRequestCountKey(): string
    {
        return "circuit_breaker:{$this->name}:requests";
    }

    private function getLastFailureTimeKey(): string
    {
        return "circuit_breaker:{$this->name}:last_failure";
    }
}

class CircuitBreakerOpenException extends \Exception
{
}