<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\QueryHandlers;

use LaravelModularDDD\CQRS\Contracts\QueryInterface;
use LaravelModularDDD\CQRS\Contracts\BatchOptimizableHandlerInterface;
use LaravelModularDDD\CQRS\Projections\BatchProjectionLoader;
use LaravelModularDDD\Core\Application\Repository\BatchAggregateRepository;

abstract class BatchOptimizedQueryHandler implements BatchOptimizableHandlerInterface
{
    public function __construct(
        protected readonly BatchProjectionLoader $projectionLoader,
        protected readonly BatchAggregateRepository $aggregateRepository
    ) {}

    public function handle(QueryInterface $query): mixed
    {
        return $this->handleSingle($query);
    }

    public function handleBatch(array $queries): array
    {
        if (empty($queries)) {
            return [];
        }

        // Extract identifiers from all queries
        $identifiers = [];
        foreach ($queries as $query) {
            $id = $this->extractIdentifier($query);
            if ($id !== null) {
                $identifiers[] = $id;
            }
        }

        if (empty($identifiers)) {
            // Fall back to individual handling
            return array_map([$this, 'handleSingle'], $queries);
        }

        // Remove duplicates while preserving order
        $uniqueIdentifiers = array_unique($identifiers, SORT_REGULAR);

        // Batch load data
        $batchData = $this->loadBatchData($uniqueIdentifiers);

        // Map results back to queries
        $results = [];
        foreach ($queries as $index => $query) {
            $id = $this->extractIdentifier($query);
            if ($id !== null && isset($batchData[$this->getIdentifierKey($id)])) {
                $results[] = $this->buildResult($query, $batchData[$this->getIdentifierKey($id)]);
            } else {
                $results[] = $this->buildEmptyResult($query);
            }
        }

        return $results;
    }

    public function shouldUseBatchOptimization(array $queries): bool
    {
        // Use batch optimization for 2 or more queries
        return count($queries) >= 2;
    }

    /**
     * Handle a single query
     */
    abstract protected function handleSingle(QueryInterface $query): mixed;

    /**
     * Extract identifier from query for batch loading
     */
    abstract protected function extractIdentifier(QueryInterface $query);

    /**
     * Load data for multiple identifiers efficiently
     *
     * @param array $identifiers
     * @return array Keyed by identifier
     */
    abstract protected function loadBatchData(array $identifiers): array;

    /**
     * Build result from batch-loaded data
     */
    abstract protected function buildResult(QueryInterface $query, $data): mixed;

    /**
     * Build empty result when data is not found
     */
    abstract protected function buildEmptyResult(QueryInterface $query): mixed;

    /**
     * Convert identifier to string key for array indexing
     */
    protected function getIdentifierKey($identifier): string
    {
        if (is_object($identifier) && method_exists($identifier, 'toString')) {
            return $identifier->toString();
        }

        return (string) $identifier;
    }
}

/**
 * Example implementation for aggregate queries
 */
class AggregateQueryHandler extends BatchOptimizedQueryHandler
{
    protected function handleSingle(QueryInterface $query): mixed
    {
        // Individual query handling logic
        $aggregateId = $this->extractIdentifier($query);
        if ($aggregateId === null) {
            return null;
        }

        $data = $this->loadBatchData([$aggregateId]);
        return $data[$this->getIdentifierKey($aggregateId)] ?? null;
    }

    protected function extractIdentifier(QueryInterface $query)
    {
        // Extract aggregate ID from query
        if (method_exists($query, 'getAggregateId')) {
            return $query->getAggregateId();
        }

        return null;
    }

    protected function loadBatchData(array $identifiers): array
    {
        // Load projections in batch
        $projections = $this->projectionLoader->loadBatch(
            'example_projections',
            $identifiers,
            ExampleReadModel::class
        );

        // Optionally load related data
        $relatedData = $this->projectionLoader->loadRelatedProjections(
            'example_projections',
            'related_projections',
            'relation_key',
            $identifiers,
            RelatedReadModel::class
        );

        // Combine data
        $result = [];
        foreach ($identifiers as $id) {
            $key = $this->getIdentifierKey($id);
            $result[$key] = [
                'projection' => $projections[$key] ?? null,
                'related' => $relatedData[$key] ?? [],
            ];
        }

        return $result;
    }

    protected function buildResult(QueryInterface $query, $data): mixed
    {
        // Build specific result object based on query type and data
        return new QueryResult([
            'projection' => $data['projection'],
            'related' => $data['related'],
            'query_metadata' => $this->getQueryMetadata($query),
        ]);
    }

    protected function buildEmptyResult(QueryInterface $query): mixed
    {
        return new QueryResult([
            'projection' => null,
            'related' => [],
            'query_metadata' => $this->getQueryMetadata($query),
        ]);
    }

    private function getQueryMetadata(QueryInterface $query): array
    {
        return [
            'query_type' => get_class($query),
            'executed_at' => now(),
        ];
    }
}

/**
 * Example read model classes
 */
class ExampleReadModel
{
    public function __construct(
        private readonly array $data
    ) {}

    public function toArray(): array
    {
        return $this->data;
    }
}

class RelatedReadModel
{
    public function __construct(
        private readonly array $data
    ) {}

    public function toArray(): array
    {
        return $this->data;
    }
}

class QueryResult
{
    public function __construct(
        private readonly array $data
    ) {}

    public function getProjection(): ?ExampleReadModel
    {
        return $this->data['projection'];
    }

    public function getRelated(): array
    {
        return $this->data['related'];
    }

    public function getMetadata(): array
    {
        return $this->data['query_metadata'];
    }

    public function toArray(): array
    {
        return $this->data;
    }
}