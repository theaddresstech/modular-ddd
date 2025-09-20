<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Saga\Persistence;

use LaravelModularDDD\CQRS\Saga\SagaInterface;
use LaravelModularDDD\CQRS\Saga\SagaState;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DatabaseSagaRepository implements SagaRepositoryInterface
{
    private string $table;

    public function __construct(string $table = 'sagas')
    {
        $this->table = $table;
    }

    public function save(SagaInterface $saga): void
    {
        $data = [
            'saga_id' => $saga->getSagaId(),
            'saga_type' => $saga->getSagaType(),
            'state' => $saga->getState()->value,
            'metadata' => json_encode($saga->getMetadata()),
            'timeout_at' => now()->addSeconds($saga->getTimeout()),
            'updated_at' => now(),
        ];

        DB::table($this->table)->updateOrInsert(
            ['saga_id' => $saga->getSagaId()],
            array_merge($data, ['created_at' => now()])
        );
    }

    public function findById(string $sagaId): ?SagaInterface
    {
        $record = DB::table($this->table)
            ->where('saga_id', $sagaId)
            ->first();

        if (!$record) {
            return null;
        }

        return $this->hydrateSaga($record);
    }

    public function findActiveSagas(): array
    {
        $records = DB::table($this->table)
            ->whereIn('state', [
                SagaState::PENDING->value,
                SagaState::RUNNING->value,
                SagaState::COMPENSATING->value,
            ])
            ->get();

        return $records->map(fn($record) => $this->hydrateSaga($record))->toArray();
    }

    public function findByState(SagaState $state): array
    {
        $records = DB::table($this->table)
            ->where('state', $state->value)
            ->get();

        return $records->map(fn($record) => $this->hydrateSaga($record))->toArray();
    }

    public function findByType(string $sagaType): array
    {
        $records = DB::table($this->table)
            ->where('saga_type', $sagaType)
            ->get();

        return $records->map(fn($record) => $this->hydrateSaga($record))->toArray();
    }

    public function findTimedOutSagas(): array
    {
        $records = DB::table($this->table)
            ->where('timeout_at', '<', now())
            ->whereIn('state', [
                SagaState::PENDING->value,
                SagaState::RUNNING->value,
                SagaState::COMPENSATING->value,
            ])
            ->get();

        return $records->map(fn($record) => $this->hydrateSaga($record))->toArray();
    }

    public function delete(string $sagaId): void
    {
        DB::table($this->table)
            ->where('saga_id', $sagaId)
            ->delete();
    }

    public function getStatistics(): array
    {
        $stats = DB::table($this->table)
            ->select('state', DB::raw('count(*) as count'))
            ->groupBy('state')
            ->get()
            ->pluck('count', 'state')
            ->toArray();

        $typeStats = DB::table($this->table)
            ->select('saga_type', DB::raw('count(*) as count'))
            ->groupBy('saga_type')
            ->get()
            ->pluck('count', 'saga_type')
            ->toArray();

        $avgDuration = DB::table($this->table)
            ->whereIn('state', [SagaState::COMPLETED->value, SagaState::FAILED->value])
            ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as avg_duration'))
            ->value('avg_duration');

        return [
            'by_state' => $stats,
            'by_type' => $typeStats,
            'total' => array_sum($stats),
            'active' => ($stats[SagaState::PENDING->value] ?? 0) +
                       ($stats[SagaState::RUNNING->value] ?? 0) +
                       ($stats[SagaState::COMPENSATING->value] ?? 0),
            'completed' => $stats[SagaState::COMPLETED->value] ?? 0,
            'failed' => $stats[SagaState::FAILED->value] ?? 0,
            'average_duration_seconds' => $avgDuration ? (float) $avgDuration : 0,
        ];
    }

    public function cleanupOldSagas(int $daysOld = 30): int
    {
        return DB::table($this->table)
            ->whereIn('state', [
                SagaState::COMPLETED->value,
                SagaState::COMPENSATED->value,
                SagaState::FAILED->value,
            ])
            ->where('updated_at', '<', now()->subDays($daysOld))
            ->delete();
    }

    private function hydrateSaga($record): SagaInterface
    {
        $sagaClass = $record->saga_type;

        if (!class_exists($sagaClass)) {
            throw new \RuntimeException("Saga class {$sagaClass} not found");
        }

        if (!is_subclass_of($sagaClass, 'LaravelModularDDD\CQRS\Saga\AbstractSaga')) {
            throw new \RuntimeException("Saga class {$sagaClass} must extend AbstractSaga");
        }

        // Create saga instance using safe hydration
        $saga = $sagaClass::fromPersistedState(
            $record->saga_id,
            SagaState::from($record->state),
            json_decode($record->metadata, true) ?? []
        );

        return $saga;
    }
}