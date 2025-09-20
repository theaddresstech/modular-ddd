<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Async;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AsyncStatusRepository
{
    private const TTL = 3600; // 1 hour
    private const PREFIX = 'async_command:';

    /**
     * Store command status
     */
    public function setStatus(string $id, AsyncStatus $status, array $metadata = []): void
    {
        $data = [
            'status' => $status->value,
            'metadata' => $metadata,
            'updated_at' => now()->toISOString(),
        ];

        Cache::put(self::PREFIX . "status:{$id}", $data, self::TTL);

        Log::debug('Async command status updated', [
            'command_id' => $id,
            'status' => $status->value,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get command status
     */
    public function getStatus(string $id): AsyncStatus
    {
        $data = Cache::get(self::PREFIX . "status:{$id}");

        if (!$data) {
            return AsyncStatus::PENDING;
        }

        return AsyncStatus::from($data['status']);
    }

    /**
     * Store command result
     */
    public function setResult(string $id, mixed $result): void
    {
        Cache::put(self::PREFIX . "result:{$id}", $result, self::TTL);
        $this->setStatus($id, AsyncStatus::COMPLETED, ['result_stored' => true]);
    }

    /**
     * Get command result
     */
    public function getResult(string $id): mixed
    {
        return Cache::get(self::PREFIX . "result:{$id}");
    }

    /**
     * Store command error
     */
    public function setError(string $id, \Throwable $error): void
    {
        $errorData = [
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTraceAsString(),
        ];

        Cache::put(self::PREFIX . "error:{$id}", $errorData, self::TTL);
        $this->setStatus($id, AsyncStatus::FAILED, ['error_stored' => true]);
    }

    /**
     * Get command error
     */
    public function getError(string $id): ?array
    {
        return Cache::get(self::PREFIX . "error:{$id}");
    }

    /**
     * Remove all data for a command
     */
    public function cleanup(string $id): void
    {
        Cache::forget(self::PREFIX . "status:{$id}");
        Cache::forget(self::PREFIX . "result:{$id}");
        Cache::forget(self::PREFIX . "error:{$id}");
    }

    /**
     * Get detailed status information
     */
    public function getDetailedStatus(string $id): array
    {
        $status = $this->getStatus($id);
        $result = null;
        $error = null;

        if ($status === AsyncStatus::COMPLETED) {
            $result = $this->getResult($id);
        } elseif ($status === AsyncStatus::FAILED) {
            $error = $this->getError($id);
        }

        return [
            'id' => $id,
            'status' => $status->value,
            'result' => $result,
            'error' => $error,
            'is_completed' => $status->isCompleted(),
            'is_successful' => $status->isSuccessful(),
            'is_failed' => $status->isFailed(),
        ];
    }
}