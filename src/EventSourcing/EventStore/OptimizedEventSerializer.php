<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\EventStore;

use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use LaravelModularDDD\EventSourcing\Exceptions\EventStoreException;
use LaravelModularDDD\EventSourcing\Performance\EventObjectPool;
use LaravelModularDDD\EventSourcing\Performance\EventDeserializationCache;

class OptimizedEventSerializer extends EventSerializer
{
    private EventObjectPool $objectPool;
    private EventDeserializationCache $deserializationCache;
    private bool $enableObjectPooling;
    private bool $enableDeserialization;

    public function __construct(
        ?EventObjectPool $objectPool = null,
        ?EventDeserializationCache $deserializationCache = null,
        bool $enableObjectPooling = true,
        bool $enableDeserializationCache = true
    ) {
        $this->objectPool = $objectPool ?? new EventObjectPool();
        $this->deserializationCache = $deserializationCache ?? new EventDeserializationCache();
        $this->enableObjectPooling = $enableObjectPooling;
        $this->enableDeserialization = $enableDeserializationCache;
    }

    /**
     * Optimized deserialization with caching and object pooling
     */
    public function deserialize(array $data): DomainEventInterface
    {
        // Generate cache key for this event data
        $cacheKey = $this->deserializationCache->generateCacheKey($data);

        // Try to get from cache first
        if ($this->enableDeserialization) {
            $cachedEvent = $this->deserializationCache->get($cacheKey);
            if ($cachedEvent !== null) {
                return $cachedEvent;
            }
        }

        // Deserialize the event
        $event = $this->deserializeWithOptimizations($data);

        // Cache the deserialized event
        if ($this->enableDeserialization) {
            $this->deserializationCache->put($cacheKey, $event);
        }

        return $event;
    }

    /**
     * Batch deserialize with optimizations
     */
    public function deserializeBatch(array $data): array
    {
        $events = [];
        $uncachedData = [];
        $cacheKeys = [];

        // First pass: check cache for all events
        if ($this->enableDeserialization) {
            foreach ($data as $index => $eventData) {
                $cacheKey = $this->deserializationCache->generateCacheKey($eventData);
                $cacheKeys[$index] = $cacheKey;

                $cachedEvent = $this->deserializationCache->get($cacheKey);
                if ($cachedEvent !== null) {
                    $events[$index] = $cachedEvent;
                } else {
                    $uncachedData[$index] = $eventData;
                }
            }
        } else {
            $uncachedData = $data;
        }

        // Second pass: deserialize uncached events
        foreach ($uncachedData as $index => $eventData) {
            $event = $this->deserializeWithOptimizations($eventData);
            $events[$index] = $event;

            // Cache the deserialized event
            if ($this->enableDeserialization && isset($cacheKeys[$index])) {
                $this->deserializationCache->put($cacheKeys[$index], $event);
            }
        }

        // Sort events by original index to maintain order
        ksort($events);

        return array_values($events);
    }

    /**
     * Release event object back to pool when done
     */
    public function releaseEvent(DomainEventInterface $event): void
    {
        if ($this->enableObjectPooling) {
            $this->objectPool->release($event);
        }
    }

    /**
     * Get performance statistics
     */
    public function getPerformanceStatistics(): array
    {
        return [
            'object_pool' => $this->enableObjectPooling ? $this->objectPool->getStatistics() : null,
            'deserialization_cache' => $this->enableDeserialization ? $this->deserializationCache->getStatistics() : null,
            'optimizations_enabled' => [
                'object_pooling' => $this->enableObjectPooling,
                'deserialization_cache' => $this->enableDeserialization,
            ],
        ];
    }

    /**
     * Warm up performance optimizations
     */
    public function warmUp(array $commonEventClasses): void
    {
        if ($this->enableObjectPooling) {
            $this->objectPool->warmUp($commonEventClasses, 20);
        }
    }

    /**
     * Clear all caches and reset pools
     */
    public function clearOptimizations(): void
    {
        if ($this->enableObjectPooling) {
            $this->objectPool->clear();
        }

        if ($this->enableDeserialization) {
            $this->deserializationCache->flush();
        }
    }

    private function deserializeWithOptimizations(array $data): DomainEventInterface
    {
        try {
            $eventClass = $data['event_type'];

            if (!class_exists($eventClass)) {
                throw new \InvalidArgumentException("Event class {$eventClass} does not exist");
            }

            if (!is_subclass_of($eventClass, DomainEventInterface::class)) {
                throw new \InvalidArgumentException(
                    "Event class {$eventClass} must implement DomainEventInterface"
                );
            }

            // Try to get from object pool first
            if ($this->enableObjectPooling) {
                try {
                    $event = $this->objectPool->acquire($eventClass);

                    // If the event has a fromArray method, use it to populate
                    if (method_exists($event, 'populateFromArray')) {
                        $event->populateFromArray($data);
                        return $event;
                    }
                } catch (\Exception $e) {
                    // Fall back to regular instantiation
                }
            }

            // Fall back to regular instantiation
            return $eventClass::fromArray($data);

        } catch (\Exception $e) {
            throw EventStoreException::eventDeserializationFailed($e->getMessage());
        }
    }

    /**
     * Create an optimized version of the base serializer
     */
    public static function createOptimized(array $config = []): self
    {
        $objectPoolSize = $config['object_pool_size'] ?? 1000;
        $cacheSize = $config['cache_size'] ?? 1000;
        $cacheTtl = $config['cache_ttl'] ?? 3600;

        $objectPool = new EventObjectPool($objectPoolSize);
        $deserializationCache = new EventDeserializationCache($cacheSize, 'event_deserial', $cacheTtl);

        return new self(
            $objectPool,
            $deserializationCache,
            $config['enable_object_pooling'] ?? true,
            $config['enable_deserialization_cache'] ?? true
        );
    }
}