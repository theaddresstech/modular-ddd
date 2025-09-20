<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS;

use LaravelModularDDD\CQRS\Contracts\QueryInterface;
use Ramsey\Uuid\Uuid;

abstract class Query implements QueryInterface
{
    private readonly string $queryId;
    private readonly int $createdAt;

    public function __construct(
        protected readonly array $filters = [],
        protected readonly array $sorting = [],
        protected readonly ?array $pagination = null
    ) {
        $this->queryId = Uuid::uuid4()->toString();
        $this->createdAt = time();
    }

    public function getQueryId(): string
    {
        return $this->queryId;
    }

    public function getQueryName(): string
    {
        return static::class;
    }

    public function getCacheKey(): string
    {
        $params = [
            'query' => static::class,
            'filters' => $this->filters,
            'sorting' => $this->sorting,
            'pagination' => $this->pagination,
        ];

        return 'query:' . hash('sha256', serialize($params));
    }

    public function getCacheTtl(): int
    {
        return 900; // 15 minutes default
    }

    public function shouldCache(): bool
    {
        return true;
    }

    public function getCacheTags(): array
    {
        return [
            'query:' . static::class,
            'complexity:' . $this->getComplexity(),
        ];
    }

    public function getComplexity(): int
    {
        // Calculate complexity based on filters and sorting
        $complexity = 1;

        // Add complexity for filters
        $complexity += count($this->filters);

        // Add complexity for sorting
        $complexity += count($this->sorting);

        // Add complexity for pagination
        if ($this->pagination) {
            $complexity += 1;
        }

        return min(10, max(1, $complexity));
    }

    public function getTimeout(): int
    {
        // Timeout based on complexity
        return min(60, max(10, $this->getComplexity() * 5));
    }

    public function getPagination(): ?array
    {
        return $this->pagination;
    }

    public function getSorting(): array
    {
        return $this->sorting;
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function toArray(): array
    {
        return [
            'query_class' => static::class,
            'query_id' => $this->queryId,
            'filters' => $this->filters,
            'sorting' => $this->sorting,
            'pagination' => $this->pagination,
            'created_at' => $this->createdAt,
            'cache_key' => $this->getCacheKey(),
            'complexity' => $this->getComplexity(),
        ];
    }

    /**
     * Create paginated version of this query
     */
    public function paginate(int $page, int $perPage = 15): static
    {
        return new static(
            $this->filters,
            $this->sorting,
            ['page' => $page, 'per_page' => $perPage]
        );
    }

    /**
     * Create sorted version of this query
     */
    public function sortBy(string $field, string $direction = 'asc'): static
    {
        $sorting = array_merge($this->sorting, [$field => $direction]);

        return new static($this->filters, $sorting, $this->pagination);
    }

    /**
     * Create filtered version of this query
     */
    public function filter(array $filters): static
    {
        $mergedFilters = array_merge($this->filters, $filters);

        return new static($mergedFilters, $this->sorting, $this->pagination);
    }

    /**
     * Check if query has pagination
     */
    public function isPaginated(): bool
    {
        return $this->pagination !== null;
    }

    /**
     * Check if query has sorting
     */
    public function isSorted(): bool
    {
        return !empty($this->sorting);
    }

    /**
     * Check if query has filters
     */
    public function isFiltered(): bool
    {
        return !empty($this->filters);
    }

    /**
     * Get page number (if paginated)
     */
    public function getPage(): int
    {
        return $this->pagination['page'] ?? 1;
    }

    /**
     * Get items per page (if paginated)
     */
    public function getPerPage(): int
    {
        return $this->pagination['per_page'] ?? 15;
    }

    /**
     * Get offset for database queries
     */
    public function getOffset(): int
    {
        if (!$this->isPaginated()) {
            return 0;
        }

        return ($this->getPage() - 1) * $this->getPerPage();
    }
}