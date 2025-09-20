<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Contracts;

interface BatchQueryInterface extends QueryInterface
{
    /**
     * Get the IDs for batch processing
     *
     * @return array
     */
    public function getIds(): array;

    /**
     * Create an individual query for a specific ID
     *
     * @param mixed $id
     * @return QueryInterface
     */
    public function createIndividualQuery($id): QueryInterface;

    /**
     * Create a new batch query with subset of IDs
     *
     * @param array $ids
     * @return static
     */
    public function withIds(array $ids): self;
}