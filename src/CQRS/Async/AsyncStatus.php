<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Async;

enum AsyncStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case TIMEOUT = 'timeout';

    public function isCompleted(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::CANCELLED, self::TIMEOUT]);
    }

    public function isSuccessful(): bool
    {
        return $this === self::COMPLETED;
    }

    public function isFailed(): bool
    {
        return in_array($this, [self::FAILED, self::CANCELLED, self::TIMEOUT]);
    }
}