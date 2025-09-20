<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Contracts;

interface QueryInterface
{
    /**
     * Get unique query identifier
     */
    public function getQueryId(): string;

    /**
     * Get query name/type
     */
    public function getQueryName(): string;

    /**
     * Get cache key for this query
     */
    public function getCacheKey(): string;

    /**
     * Get cache TTL in seconds
     */
    public function getCacheTtl(): int;

    /**
     * Check if query result should be cached
     */
    public function shouldCache(): bool;

    /**
     * Get cache tags for invalidation
     */
    public function getCacheTags(): array;

    /**
     * Get query complexity score (1-10, 10 = most complex)
     */
    public function getComplexity(): int;

    /**
     * Get query timeout in seconds
     */
    public function getTimeout(): int;

    /**
     * Get pagination parameters
     */
    public function getPagination(): ?array;

    /**
     * Get sorting parameters
     */
    public function getSorting(): array;

    /**
     * Get filtering parameters
     */
    public function getFilters(): array;

    /**
     * Convert query to array for caching/logging
     */
    public function toArray(): array;
}