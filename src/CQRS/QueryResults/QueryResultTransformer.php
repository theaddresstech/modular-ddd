<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\QueryResults;

use LaravelModularDDD\CQRS\Contracts\QueryInterface;

interface QueryResultTransformer
{
    /**
     * Transform the query result
     */
    public function transform(mixed $result, QueryInterface $query): mixed;

    /**
     * Check if this transformer supports the given query
     */
    public function supports(QueryInterface $query): bool;

    /**
     * Get the transformer priority (higher = executed first)
     */
    public function getPriority(): int;
}