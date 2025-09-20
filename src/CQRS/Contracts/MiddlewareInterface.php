<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Contracts;

use Closure;

interface MiddlewareInterface
{
    /**
     * Handle the command or query through middleware
     */
    public function handle(mixed $message, Closure $next): mixed;

    /**
     * Get middleware priority (higher = executed first)
     */
    public function getPriority(): int;

    /**
     * Check if middleware should process this message
     */
    public function shouldProcess(mixed $message): bool;
}