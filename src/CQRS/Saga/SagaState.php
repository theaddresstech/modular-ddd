<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Saga;

enum SagaState: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case COMPENSATING = 'compensating';
    case COMPENSATED = 'compensated';
    case TIMED_OUT = 'timed_out';

    public function isActive(): bool
    {
        return in_array($this, [self::PENDING, self::RUNNING, self::COMPENSATING]);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::COMPENSATED, self::TIMED_OUT]);
    }

    public function canTransitionTo(SagaState $newState): bool
    {
        // Handle TIMED_OUT state separately since it can transition from any non-terminal state
        if ($newState === self::TIMED_OUT) {
            return !$this->isTerminal();
        }

        return match ([$this, $newState]) {
            [self::PENDING, self::RUNNING] => true,
            [self::PENDING, self::FAILED] => true,
            [self::RUNNING, self::COMPLETED] => true,
            [self::RUNNING, self::FAILED] => true,
            [self::RUNNING, self::COMPENSATING] => true,
            [self::FAILED, self::COMPENSATING] => true,
            [self::COMPENSATING, self::COMPENSATED] => true,
            [self::COMPENSATING, self::FAILED] => true,
            default => false,
        };
    }
}