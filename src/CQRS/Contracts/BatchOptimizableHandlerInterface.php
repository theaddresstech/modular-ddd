<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Contracts;

interface BatchOptimizableHandlerInterface extends QueryHandlerInterface
{
    /**
     * Handle multiple queries of the same type efficiently
     *
     * @param QueryInterface[] $queries
     * @return array Results in the same order as queries
     */
    public function handleBatch(array $queries): array;

    /**
     * Check if batch optimization is beneficial for given queries
     *
     * @param QueryInterface[] $queries
     * @return bool
     */
    public function shouldUseBatchOptimization(array $queries): bool;
}