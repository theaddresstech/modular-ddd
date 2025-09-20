<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Projections;

use LaravelModularDDD\CQRS\Contracts\ReadModelInterface;
use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

class BatchProjectionLoader
{
    public function __construct(
        private readonly ConnectionInterface $connection
    ) {}

    /**
     * Load multiple projections efficiently
     *
     * @param string $projectionTable
     * @param AggregateIdInterface[] $aggregateIds
     * @param string $readModelClass
     * @return ReadModelInterface[] Keyed by aggregate ID string
     */
    public function loadBatch(
        string $projectionTable,
        array $aggregateIds,
        string $readModelClass
    ): array {
        if (empty($aggregateIds)) {
            return [];
        }

        $stringIds = array_map(fn($id) => $id->toString(), $aggregateIds);

        $records = $this->connection
            ->table($projectionTable)
            ->whereIn('aggregate_id', $stringIds)
            ->get();

        $projections = [];
        foreach ($records as $record) {
            $recordArray = (array) $record;
            $projections[$record->aggregate_id] = new $readModelClass($recordArray);
        }

        // Fill in missing projections with empty objects
        foreach ($stringIds as $stringId) {
            if (!isset($projections[$stringId])) {
                $projections[$stringId] = new $readModelClass([]);
            }
        }

        return $projections;
    }

    /**
     * Load projections with joins to prevent additional queries
     *
     * @param string $mainTable
     * @param array $joins Array of join configurations
     * @param AggregateIdInterface[] $aggregateIds
     * @param string $readModelClass
     * @return ReadModelInterface[] Keyed by aggregate ID string
     */
    public function loadBatchWithJoins(
        string $mainTable,
        array $joins,
        array $aggregateIds,
        string $readModelClass
    ): array {
        if (empty($aggregateIds)) {
            return [];
        }

        $stringIds = array_map(fn($id) => $id->toString(), $aggregateIds);

        $query = $this->connection->table($mainTable);

        // Apply joins
        foreach ($joins as $join) {
            $query->leftJoin(
                $join['table'],
                $join['first'],
                '=',
                $join['second']
            );
        }

        $records = $query
            ->whereIn($mainTable . '.aggregate_id', $stringIds)
            ->get();

        $projections = [];
        foreach ($records as $record) {
            $recordArray = (array) $record;
            $projections[$record->aggregate_id] = new $readModelClass($recordArray);
        }

        return $projections;
    }

    /**
     * Load aggregated projection data to prevent N+1 queries
     *
     * @param string $projectionTable
     * @param AggregateIdInterface[] $aggregateIds
     * @param array $aggregations Array of aggregation configurations
     * @return array Keyed by aggregate ID string
     */
    public function loadAggregatedData(
        string $projectionTable,
        array $aggregateIds,
        array $aggregations
    ): array {
        if (empty($aggregateIds)) {
            return [];
        }

        $stringIds = array_map(fn($id) => $id->toString(), $aggregateIds);

        $query = $this->connection
            ->table($projectionTable)
            ->whereIn('aggregate_id', $stringIds)
            ->groupBy('aggregate_id');

        // Build select with aggregations
        $selects = ['aggregate_id'];
        foreach ($aggregations as $alias => $config) {
            $selects[] = $this->connection->raw(
                strtoupper($config['function']) . '(' . $config['column'] . ') as ' . $alias
            );
        }

        $records = $query->select($selects)->get();

        $results = [];
        foreach ($records as $record) {
            $results[$record->aggregate_id] = (array) $record;
        }

        return $results;
    }

    /**
     * Load related projection data in batch
     *
     * @param string $mainProjectionTable
     * @param string $relatedProjectionTable
     * @param string $relationKey
     * @param AggregateIdInterface[] $aggregateIds
     * @param string $relatedReadModelClass
     * @return array Multi-dimensional: aggregate_id => [related_projections]
     */
    public function loadRelatedProjections(
        string $mainProjectionTable,
        string $relatedProjectionTable,
        string $relationKey,
        array $aggregateIds,
        string $relatedReadModelClass
    ): array {
        if (empty($aggregateIds)) {
            return [];
        }

        $stringIds = array_map(fn($id) => $id->toString(), $aggregateIds);

        // First, get the relation keys from main projections
        $mainRecords = $this->connection
            ->table($mainProjectionTable)
            ->select('aggregate_id', $relationKey)
            ->whereIn('aggregate_id', $stringIds)
            ->whereNotNull($relationKey)
            ->get();

        if ($mainRecords->isEmpty()) {
            return [];
        }

        // Extract all unique relation keys
        $relationKeys = $mainRecords->pluck($relationKey)->unique()->filter()->toArray();

        if (empty($relationKeys)) {
            return [];
        }

        // Load all related projections in one query
        $relatedRecords = $this->connection
            ->table($relatedProjectionTable)
            ->whereIn($relationKey, $relationKeys)
            ->get();

        // Group related records by relation key
        $relatedByKey = [];
        foreach ($relatedRecords as $record) {
            $key = $record->{$relationKey};
            if (!isset($relatedByKey[$key])) {
                $relatedByKey[$key] = [];
            }
            $relatedByKey[$key][] = new $relatedReadModelClass((array) $record);
        }

        // Map back to aggregate IDs
        $results = [];
        foreach ($mainRecords as $mainRecord) {
            $aggregateId = $mainRecord->aggregate_id;
            $relationKeyValue = $mainRecord->{$relationKey};

            $results[$aggregateId] = $relatedByKey[$relationKeyValue] ?? [];
        }

        // Ensure all requested aggregate IDs are in results
        foreach ($stringIds as $stringId) {
            if (!isset($results[$stringId])) {
                $results[$stringId] = [];
            }
        }

        return $results;
    }

    /**
     * Load hierarchical projection data efficiently
     *
     * @param string $projectionTable
     * @param string $parentIdColumn
     * @param AggregateIdInterface[] $rootAggregateIds
     * @param string $readModelClass
     * @param int $maxDepth
     * @return array Hierarchical structure
     */
    public function loadHierarchicalProjections(
        string $projectionTable,
        string $parentIdColumn,
        array $rootAggregateIds,
        string $readModelClass,
        int $maxDepth = 5
    ): array {
        if (empty($rootAggregateIds)) {
            return [];
        }

        $allProjections = [];
        $currentLevelIds = array_map(fn($id) => $id->toString(), $rootAggregateIds);

        for ($depth = 0; $depth < $maxDepth && !empty($currentLevelIds); $depth++) {
            // Load current level
            $records = $this->connection
                ->table($projectionTable)
                ->whereIn($parentIdColumn, $currentLevelIds)
                ->get();

            if ($records->isEmpty()) {
                break;
            }

            $nextLevelIds = [];
            foreach ($records as $record) {
                $projection = new $readModelClass((array) $record);
                $parentId = $record->{$parentIdColumn};

                if (!isset($allProjections[$parentId])) {
                    $allProjections[$parentId] = [];
                }
                $allProjections[$parentId][] = $projection;

                // Prepare for next level
                $nextLevelIds[] = $record->aggregate_id;
            }

            $currentLevelIds = array_unique($nextLevelIds);
        }

        return $allProjections;
    }

    /**
     * Load projection statistics for multiple aggregates
     *
     * @param string $projectionTable
     * @param AggregateIdInterface[] $aggregateIds
     * @param array $metrics
     * @return array Statistics keyed by aggregate ID
     */
    public function loadProjectionStatistics(
        string $projectionTable,
        array $aggregateIds,
        array $metrics
    ): array {
        if (empty($aggregateIds) || empty($metrics)) {
            return [];
        }

        $stringIds = array_map(fn($id) => $id->toString(), $aggregateIds);

        $selects = ['aggregate_id'];
        foreach ($metrics as $metric) {
            switch ($metric['type']) {
                case 'count':
                    $selects[] = $this->connection->raw("COUNT({$metric['column']}) as {$metric['alias']}");
                    break;
                case 'sum':
                    $selects[] = $this->connection->raw("SUM({$metric['column']}) as {$metric['alias']}");
                    break;
                case 'avg':
                    $selects[] = $this->connection->raw("AVG({$metric['column']}) as {$metric['alias']}");
                    break;
                case 'max':
                    $selects[] = $this->connection->raw("MAX({$metric['column']}) as {$metric['alias']}");
                    break;
                case 'min':
                    $selects[] = $this->connection->raw("MIN({$metric['column']}) as {$metric['alias']}");
                    break;
            }
        }

        $records = $this->connection
            ->table($projectionTable)
            ->select($selects)
            ->whereIn('aggregate_id', $stringIds)
            ->groupBy('aggregate_id')
            ->get();

        $statistics = [];
        foreach ($records as $record) {
            $statistics[$record->aggregate_id] = (array) $record;
        }

        return $statistics;
    }
}