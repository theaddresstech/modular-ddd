<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\QueryResults;

use LaravelModularDDD\CQRS\Contracts\QueryInterface;

class PaginationTransformer implements QueryResultTransformer
{
    public function transform(mixed $result, QueryInterface $query): mixed
    {
        if (!$query->isPaginated()) {
            return $result;
        }

        // Handle different result types
        if ($result instanceof \Illuminate\Contracts\Pagination\Paginator) {
            return PaginatedResult::fromLaravelPaginator($result);
        }

        if (is_array($result)) {
            return $this->transformArrayResult($result, $query);
        }

        if ($result instanceof \Illuminate\Database\Eloquent\Collection) {
            return $this->transformCollectionResult($result, $query);
        }

        // If result is already paginated, return as-is
        if ($result instanceof PaginatedResult) {
            return $result;
        }

        return $result;
    }

    public function supports(QueryInterface $query): bool
    {
        return $query->isPaginated();
    }

    public function getPriority(): int
    {
        return 80; // High priority for pagination
    }

    private function transformArrayResult(array $result, QueryInterface $query): PaginatedResult
    {
        $page = $query->getPage();
        $perPage = $query->getPerPage();
        $offset = $query->getOffset();

        // If result has metadata structure
        if (isset($result['data']) && isset($result['total'])) {
            return PaginatedResult::create(
                $result['data'],
                $result['total'],
                $page,
                $perPage
            );
        }

        // Simple array pagination
        $total = count($result);
        $paginatedData = array_slice($result, $offset, $perPage);

        return PaginatedResult::create($paginatedData, $total, $page, $perPage);
    }

    private function transformCollectionResult($collection, QueryInterface $query): PaginatedResult
    {
        $page = $query->getPage();
        $perPage = $query->getPerPage();
        $offset = $query->getOffset();

        $total = $collection->count();
        $paginatedData = $collection->slice($offset, $perPage)->values()->toArray();

        return PaginatedResult::create($paginatedData, $total, $page, $perPage);
    }
}