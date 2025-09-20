<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\ErrorHandling;

use RuntimeException;

/**
 * Exception thrown when a circuit breaker is open
 */
class CircuitBreakerOpenException extends RuntimeException
{
    public function __construct(string $message = 'Circuit breaker is open', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}