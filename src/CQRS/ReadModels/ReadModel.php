<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\ReadModels;

class ReadModel
{
    public function __construct(
        private readonly string $id,
        private readonly string $type,
        private array $data,
        private int $version = 0,
        private ?\DateTimeImmutable $lastUpdated = null,
        private array $metadata = []
    ) {
        $this->lastUpdated = $lastUpdated ?? new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getLastUpdated(): \DateTimeImmutable
    {
        return $this->lastUpdated;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    public function has(string $key): bool
    {
        return data_get($this->data, $key) !== null;
    }

    public function set(string $key, mixed $value): self
    {
        data_set($this->data, $key, $value);
        return $this;
    }

    public function merge(array $data): self
    {
        $this->data = array_merge_recursive($this->data, $data);
        return $this;
    }

    public function increment(string $key, int $amount = 1): self
    {
        $current = $this->get($key, 0);
        if (!is_numeric($current)) {
            throw new \InvalidArgumentException("Cannot increment non-numeric value at key: {$key}");
        }

        $this->set($key, $current + $amount);
        return $this;
    }

    public function append(string $key, mixed $value): self
    {
        $current = $this->get($key, []);
        if (!is_array($current)) {
            throw new \InvalidArgumentException("Cannot append to non-array value at key: {$key}");
        }

        $current[] = $value;
        $this->set($key, $current);
        return $this;
    }

    public function remove(string $key): self
    {
        if (str_contains($key, '.')) {
            $keys = explode('.', $key);
            $lastKey = array_pop($keys);
            $target = &$this->data;

            foreach ($keys as $k) {
                if (!isset($target[$k]) || !is_array($target[$k])) {
                    return $this;
                }
                $target = &$target[$k];
            }

            unset($target[$lastKey]);
        } else {
            unset($this->data[$key]);
        }

        return $this;
    }

    public function withVersion(int $version): self
    {
        return new self(
            $this->id,
            $this->type,
            $this->data,
            $version,
            new \DateTimeImmutable(),
            $this->metadata
        );
    }

    public function withMetadata(array $metadata): self
    {
        return new self(
            $this->id,
            $this->type,
            $this->data,
            $this->version,
            $this->lastUpdated,
            array_merge($this->metadata, $metadata)
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'data' => $this->data,
            'version' => $this->version,
            'last_updated' => $this->lastUpdated->format(\DateTimeInterface::ATOM),
            'metadata' => $this->metadata,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['type'],
            $data['data'] ?? [],
            $data['version'] ?? 0,
            isset($data['last_updated'])
                ? new \DateTimeImmutable($data['last_updated'])
                : null,
            $data['metadata'] ?? []
        );
    }

    public function isEmpty(): bool
    {
        return empty($this->data);
    }

    public function size(): int
    {
        return count($this->data);
    }

    public function getChecksum(): string
    {
        return md5(json_encode($this->data));
    }
}