<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Contracts;

interface QueryBusInterface
{
    /**
     * Execute a query
     */
    public function execute(QueryInterface $query): mixed;

    /**
     * Register a query handler
     */
    public function registerHandler(QueryHandlerInterface $handler): void;

    /**
     * Get handler for query
     */
    public function getHandler(QueryInterface $query): QueryHandlerInterface;

    /**
     * Check if query can be handled
     */
    public function canHandle(QueryInterface $query): bool;

    /**
     * Clear cache for specific tags
     */
    public function invalidateCache(array $tags): void;

    /**
     * Warm up cache with query
     */
    public function warmCache(QueryInterface $query): void;

    /**
     * Execute multiple queries efficiently
     *
     * @param QueryInterface[] $queries
     * @return array Results keyed by query index
     */
    public function executeBatch(array $queries): array;
}