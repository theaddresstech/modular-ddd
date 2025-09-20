<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\ErrorHandling;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\CQRS\Contracts\QueryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DeadLetterQueue
{
    private const TABLE_NAME = 'dead_letter_queue';

    public function __construct(
        private readonly string $table = self::TABLE_NAME
    ) {}

    /**
     * Add failed command to dead letter queue
     */
    public function addFailedCommand(
        CommandInterface $command,
        \Throwable $exception,
        array $context = []
    ): void {
        $this->addToQueue('command', $command, $exception, $context);
    }

    /**
     * Add failed query to dead letter queue
     */
    public function addFailedQuery(
        QueryInterface $query,
        \Throwable $exception,
        array $context = []
    ): void {
        $this->addToQueue('query', $query, $exception, $context);
    }

    /**
     * Get failed items from dead letter queue
     */
    public function getFailedItems(
        ?string $type = null,
        int $limit = 100,
        ?string $errorType = null
    ): array {
        $query = DB::table($this->table)
            ->orderBy('failed_at', 'desc')
            ->limit($limit);

        if ($type) {
            $query->where('type', $type);
        }

        if ($errorType) {
            $query->where('error_type', $errorType);
        }

        return $query->get()->toArray();
    }

    /**
     * Retry failed item
     */
    public function retry(string $id): bool
    {
        $item = DB::table($this->table)->where('id', $id)->first();

        if (!$item) {
            return false;
        }

        try {
            // Deserialize the payload
            $payload = json_decode($item->payload, true);

            // Mark as retrying
            $this->markAsRetrying($id);

            // The actual retry logic would be implemented by the caller
            // This method just manages the dead letter queue state

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to retry dead letter queue item', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            // Increment retry count
            $this->incrementRetryCount($id, $e);

            return false;
        }
    }

    /**
     * Mark item as successfully retried
     */
    public function markAsRetried(string $id): void
    {
        DB::table($this->table)
            ->where('id', $id)
            ->update([
                'status' => 'retried',
                'retried_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Purge old items from dead letter queue
     */
    public function purgeOldItems(int $daysOld = 30): int
    {
        return DB::table($this->table)
            ->where('failed_at', '<', now()->subDays($daysOld))
            ->delete();
    }

    /**
     * Get dead letter queue statistics
     */
    public function getStatistics(): array
    {
        $total = DB::table($this->table)->count();

        $byType = DB::table($this->table)
            ->select('type', DB::raw('count(*) as count'))
            ->groupBy('type')
            ->get()
            ->pluck('count', 'type')
            ->toArray();

        $byStatus = DB::table($this->table)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status')
            ->toArray();

        $topErrors = DB::table($this->table)
            ->select('error_type', DB::raw('count(*) as count'))
            ->groupBy('error_type')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get()
            ->toArray();

        return [
            'total_items' => $total,
            'by_type' => $byType,
            'by_status' => $byStatus,
            'top_error_types' => $topErrors,
            'oldest_item' => DB::table($this->table)->min('failed_at'),
            'newest_item' => DB::table($this->table)->max('failed_at'),
        ];
    }

    /**
     * Clear all items from dead letter queue
     */
    public function clear(): int
    {
        return DB::table($this->table)->delete();
    }

    private function addToQueue(
        string $type,
        object $item,
        \Throwable $exception,
        array $context
    ): void {
        $data = [
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'type' => $type,
            'item_class' => get_class($item),
            'payload' => json_encode($this->serializeItem($item)),
            'error_type' => get_class($exception),
            'error_message' => $exception->getMessage(),
            'error_trace' => $exception->getTraceAsString(),
            'context' => json_encode($context),
            'status' => 'failed',
            'retry_count' => 0,
            'failed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table($this->table)->insert($data);

        Log::error("Item added to dead letter queue", [
            'type' => $type,
            'item_class' => get_class($item),
            'error' => $exception->getMessage(),
        ]);
    }

    private function serializeItem(object $item): array
    {
        // Try to serialize using toArray method if available
        if (method_exists($item, 'toArray')) {
            return $item->toArray();
        }

        // Fall back to reflection-based serialization
        $reflection = new \ReflectionClass($item);
        $properties = [];

        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $properties[$property->getName()] = $property->getValue($item);
        }

        return $properties;
    }

    private function markAsRetrying(string $id): void
    {
        DB::table($this->table)
            ->where('id', $id)
            ->update([
                'status' => 'retrying',
                'retry_count' => DB::raw('retry_count + 1'),
                'updated_at' => now(),
            ]);
    }

    private function incrementRetryCount(string $id, \Exception $exception): void
    {
        DB::table($this->table)
            ->where('id', $id)
            ->update([
                'status' => 'failed',
                'retry_count' => DB::raw('retry_count + 1'),
                'last_retry_error' => $exception->getMessage(),
                'updated_at' => now(),
            ]);
    }
}