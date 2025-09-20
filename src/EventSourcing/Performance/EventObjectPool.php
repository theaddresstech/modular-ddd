<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Performance;

use LaravelModularDDD\Core\Events\DomainEventInterface;

class EventObjectPool
{
    private array $pool = [];
    private array $poolSizes = [];
    private int $maxPoolSize;
    private array $createdObjects = [];

    public function __construct(int $maxPoolSize = 1000)
    {
        $this->maxPoolSize = $maxPoolSize;
    }

    /**
     * Get an object from the pool or create a new one
     */
    public function acquire(string $eventClass, array $constructorArgs = []): DomainEventInterface
    {
        $poolKey = $this->getPoolKey($eventClass, $constructorArgs);

        if (!empty($this->pool[$poolKey])) {
            $object = array_pop($this->pool[$poolKey]);
            $this->poolSizes[$poolKey]--;

            // Reset the object if it has a reset method
            if (method_exists($object, 'reset')) {
                $object->reset();
            }

            return $object;
        }

        // Create new object
        $object = $this->createObject($eventClass, $constructorArgs);
        $this->createdObjects[$poolKey] = ($this->createdObjects[$poolKey] ?? 0) + 1;

        return $object;
    }

    /**
     * Return an object to the pool
     */
    public function release(DomainEventInterface $object): void
    {
        $eventClass = get_class($object);
        $poolKey = $this->getPoolKey($eventClass);

        // Check if pool has space
        if (($this->poolSizes[$poolKey] ?? 0) >= $this->getMaxPoolSizeForClass($eventClass)) {
            return; // Pool is full, let garbage collector handle it
        }

        // Clean the object if it has a clean method
        if (method_exists($object, 'clean')) {
            $object->clean();
        }

        $this->pool[$poolKey][] = $object;
        $this->poolSizes[$poolKey] = ($this->poolSizes[$poolKey] ?? 0) + 1;
    }

    /**
     * Clear all pools
     */
    public function clear(): void
    {
        $this->pool = [];
        $this->poolSizes = [];
    }

    /**
     * Get pool statistics
     */
    public function getStatistics(): array
    {
        $totalPooled = array_sum($this->poolSizes);
        $totalCreated = array_sum($this->createdObjects);
        $reuseRate = $totalCreated > 0 ? (($totalCreated - $totalPooled) / $totalCreated) * 100 : 0;

        return [
            'total_pooled_objects' => $totalPooled,
            'total_created_objects' => $totalCreated,
            'reuse_rate_percentage' => $reuseRate,
            'pool_sizes_by_class' => $this->poolSizes,
            'created_objects_by_class' => $this->createdObjects,
            'memory_usage_estimate_kb' => $this->estimateMemoryUsage(),
        ];
    }

    /**
     * Warm up the pool with commonly used event types
     */
    public function warmUp(array $eventClasses, int $instancesPerClass = 10): void
    {
        foreach ($eventClasses as $eventClass) {
            for ($i = 0; $i < $instancesPerClass; $i++) {
                $object = $this->createObject($eventClass, []);
                $this->release($object);
            }
        }
    }

    private function getPoolKey(string $eventClass, array $constructorArgs = []): string
    {
        // For now, pool by class only. Could extend to include constructor args hash
        return $eventClass;
    }

    private function createObject(string $eventClass, array $constructorArgs): DomainEventInterface
    {
        try {
            $reflection = new \ReflectionClass($eventClass);

            if (empty($constructorArgs)) {
                return $reflection->newInstanceWithoutConstructor();
            }

            return $reflection->newInstanceArgs($constructorArgs);

        } catch (\ReflectionException $e) {
            throw new \RuntimeException("Failed to create object of class {$eventClass}: " . $e->getMessage());
        }
    }

    private function getMaxPoolSizeForClass(string $eventClass): int
    {
        // Could be configurable per class in the future
        return (int) ($this->maxPoolSize * 0.1); // 10% of max pool size per class
    }

    private function estimateMemoryUsage(): int
    {
        $estimatedBytes = 0;

        foreach ($this->pool as $poolKey => $objects) {
            if (!empty($objects)) {
                // Rough estimate: 1KB per object
                $estimatedBytes += count($objects) * 1024;
            }
        }

        return (int) ($estimatedBytes / 1024); // Convert to KB
    }
}