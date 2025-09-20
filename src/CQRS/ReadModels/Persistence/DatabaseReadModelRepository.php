<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\ReadModels\Persistence;

use LaravelModularDDD\CQRS\ReadModels\ReadModel;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DatabaseReadModelRepository implements ReadModelRepositoryInterface
{
    private string $table;

    public function __construct(string $table = 'read_models')
    {
        $this->table = $table;
    }

    public function save(ReadModel $readModel): void
    {
        $data = [
            'id' => $readModel->getId(),
            'type' => $readModel->getType(),
            'data' => json_encode($readModel->getData()),
            'version' => $readModel->getVersion(),
            'metadata' => json_encode($readModel->getMetadata()),
            'checksum' => $readModel->getChecksum(),
            'updated_at' => $readModel->getLastUpdated()->format('Y-m-d H:i:s'),
        ];

        DB::table($this->table)->updateOrInsert(
            ['id' => $readModel->getId()],
            array_merge($data, ['created_at' => now()])
        );
    }

    public function findById(string $id): ?ReadModel
    {
        $record = DB::table($this->table)
            ->where('id', $id)
            ->first();

        return $record ? $this->hydrateReadModel($record) : null;
    }

    public function findByTypeAndAggregateId(string $type, string $aggregateId): ?ReadModel
    {
        $record = DB::table($this->table)
            ->where('type', $type)
            ->where('id', $aggregateId)
            ->first();

        return $record ? $this->hydrateReadModel($record) : null;
    }

    public function findByAggregateId(string $aggregateId): array
    {
        $records = DB::table($this->table)
            ->where('id', $aggregateId)
            ->get();

        return $records->map(fn($record) => $this->hydrateReadModel($record))->toArray();
    }

    public function findByType(string $type): array
    {
        $records = DB::table($this->table)
            ->where('type', $type)
            ->get();

        return $records->map(fn($record) => $this->hydrateReadModel($record))->toArray();
    }

    public function search(array $criteria): array
    {
        $query = DB::table($this->table);

        foreach ($criteria as $field => $value) {
            if ($field === 'data') {
                // JSON search for data field
                foreach ($value as $dataKey => $dataValue) {
                    $query->whereRaw("JSON_EXTRACT(data, '$.{$dataKey}') = ?", [$dataValue]);
                }
            } elseif (is_array($value)) {
                $query->whereIn($field, $value);
            } else {
                $query->where($field, $value);
            }
        }

        $records = $query->get();

        return $records->map(fn($record) => $this->hydrateReadModel($record))->toArray();
    }

    public function delete(string $id): void
    {
        DB::table($this->table)
            ->where('id', $id)
            ->delete();
    }

    public function deleteByAggregateId(string $aggregateId): int
    {
        return DB::table($this->table)
            ->where('id', $aggregateId)
            ->delete();
    }

    public function deleteOlderThan(int $daysOld): int
    {
        return DB::table($this->table)
            ->where('updated_at', '<', now()->subDays($daysOld))
            ->delete();
    }

    public function getStatistics(): array
    {
        $total = DB::table($this->table)->count();

        $byType = DB::table($this->table)
            ->select('type', DB::raw('count(*) as count'))
            ->groupBy('type')
            ->get()
            ->pluck('count', 'type')
            ->toArray();

        $averageSize = DB::table($this->table)
            ->select(DB::raw('AVG(LENGTH(data)) as avg_size'))
            ->value('avg_size');

        $oldestUpdated = DB::table($this->table)
            ->min('updated_at');

        $newestUpdated = DB::table($this->table)
            ->max('updated_at');

        return [
            'total' => $total,
            'by_type' => $byType,
            'average_size' => $averageSize ? (float) $averageSize : 0,
            'oldest_updated' => $oldestUpdated,
            'newest_updated' => $newestUpdated,
        ];
    }

    public function countByType(string $type): int
    {
        return DB::table($this->table)
            ->where('type', $type)
            ->count();
    }

    public function paginate(int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;

        $records = DB::table($this->table)
            ->offset($offset)
            ->limit($perPage)
            ->orderBy('updated_at', 'desc')
            ->get();

        $total = DB::table($this->table)->count();

        return [
            'data' => $records->map(fn($record) => $this->hydrateReadModel($record))->toArray(),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
                'has_next' => $page * $perPage < $total,
                'has_previous' => $page > 1,
            ],
        ];
    }

    private function hydrateReadModel($record): ReadModel
    {
        return ReadModel::fromArray([
            'id' => $record->id,
            'type' => $record->type,
            'data' => json_decode($record->data, true) ?? [],
            'version' => $record->version,
            'last_updated' => $record->updated_at,
            'metadata' => json_decode($record->metadata, true) ?? [],
        ]);
    }
}