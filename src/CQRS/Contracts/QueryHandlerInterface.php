<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Contracts;

interface QueryHandlerInterface
{
    /**
     * Handle the query
     */
    public function handle(QueryInterface $query): mixed;

    /**
     * Get the query type this handler processes
     */
    public function getHandledQueryType(): string;

    /**
     * Check if handler supports the given query
     */
    public function canHandle(QueryInterface $query): bool;

    /**
     * Get estimated execution time in milliseconds
     */
    public function getEstimatedExecutionTime(QueryInterface $query): int;
}