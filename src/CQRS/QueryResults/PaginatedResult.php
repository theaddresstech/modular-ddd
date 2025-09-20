<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\QueryResults;

class PaginatedResult
{
    public function __construct(
        private readonly array $data,
        private readonly int $total,
        private readonly int $page,
        private readonly int $perPage,
        private readonly ?string $nextPageToken = null,
        private readonly ?string $previousPageToken = null
    ) {}

    public function getData(): array
    {
        return $this->data;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getPerPage(): int
    {
        return $this->perPage;
    }

    public function getTotalPages(): int
    {
        return (int) ceil($this->total / $this->perPage);
    }

    public function hasNextPage(): bool
    {
        return $this->page < $this->getTotalPages();
    }

    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }

    public function getNextPage(): ?int
    {
        return $this->hasNextPage() ? $this->page + 1 : null;
    }

    public function getPreviousPage(): ?int
    {
        return $this->hasPreviousPage() ? $this->page - 1 : null;
    }

    public function getFrom(): int
    {
        return ($this->page - 1) * $this->perPage + 1;
    }

    public function getTo(): int
    {
        return min($this->total, $this->page * $this->perPage);
    }

    public function getNextPageToken(): ?string
    {
        return $this->nextPageToken;
    }

    public function getPreviousPageToken(): ?string
    {
        return $this->previousPageToken;
    }

    public function isEmpty(): bool
    {
        return empty($this->data);
    }

    public function isFirstPage(): bool
    {
        return $this->page === 1;
    }

    public function isLastPage(): bool
    {
        return $this->page === $this->getTotalPages();
    }

    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'pagination' => [
                'total' => $this->total,
                'page' => $this->page,
                'per_page' => $this->perPage,
                'total_pages' => $this->getTotalPages(),
                'from' => $this->getFrom(),
                'to' => $this->getTo(),
                'has_next_page' => $this->hasNextPage(),
                'has_previous_page' => $this->hasPreviousPage(),
                'next_page' => $this->getNextPage(),
                'previous_page' => $this->getPreviousPage(),
                'next_page_token' => $this->nextPageToken,
                'previous_page_token' => $this->previousPageToken,
            ],
        ];
    }

    /**
     * Create from Laravel paginator
     */
    public static function fromLaravelPaginator($paginator): self
    {
        return new self(
            $paginator->items(),
            $paginator->total(),
            $paginator->currentPage(),
            $paginator->perPage()
        );
    }

    /**
     * Create simple paginated result
     */
    public static function create(
        array $data,
        int $total,
        int $page,
        int $perPage
    ): self {
        return new self($data, $total, $page, $perPage);
    }

    /**
     * Create cursor-based paginated result
     */
    public static function createWithTokens(
        array $data,
        int $total,
        int $page,
        int $perPage,
        ?string $nextPageToken = null,
        ?string $previousPageToken = null
    ): self {
        return new self($data, $total, $page, $perPage, $nextPageToken, $previousPageToken);
    }
}