<?php

declare(strict_types=1);

namespace LaravelModularDDD\Modules\Communication;

use Illuminate\Support\Str;

/**
 * ModuleMessage
 *
 * Represents a message sent between modules.
 * Contains routing information, payload, and metadata.
 */
final class ModuleMessage
{
    private string $id;
    private string $sourceModule;
    private string $targetModule;
    private string $messageType;
    private array $payload;
    private array $metadata;
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $sourceModule,
        string $targetModule,
        string $messageType,
        array $payload = [],
        array $metadata = []
    ) {
        $this->id = Str::uuid()->toString();
        $this->sourceModule = $sourceModule;
        $this->targetModule = $targetModule;
        $this->messageType = $messageType;
        $this->payload = $payload;
        $this->metadata = $metadata;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function create(
        string $sourceModule,
        string $targetModule,
        string $messageType,
        array $payload = [],
        array $metadata = []
    ): self {
        return new self($sourceModule, $targetModule, $messageType, $payload, $metadata);
    }

    public static function command(
        string $sourceModule,
        string $targetModule,
        string $command,
        array $data = [],
        array $metadata = []
    ): self {
        return new self(
            $sourceModule,
            $targetModule,
            'command.' . $command,
            $data,
            array_merge(['type' => 'command'], $metadata)
        );
    }

    public static function query(
        string $sourceModule,
        string $targetModule,
        string $query,
        array $parameters = [],
        array $metadata = []
    ): self {
        return new self(
            $sourceModule,
            $targetModule,
            'query.' . $query,
            $parameters,
            array_merge(['type' => 'query'], $metadata)
        );
    }

    public static function request(
        string $sourceModule,
        string $targetModule,
        string $action,
        array $data = [],
        array $metadata = []
    ): self {
        return new self(
            $sourceModule,
            $targetModule,
            'request.' . $action,
            $data,
            array_merge(['type' => 'request'], $metadata)
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSourceModule(): string
    {
        return $this->sourceModule;
    }

    public function getTargetModule(): string
    {
        return $this->targetModule;
    }

    public function getMessageType(): string
    {
        return $this->messageType;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function withMetadata(array $metadata): self
    {
        $message = clone $this;
        $message->metadata = array_merge($this->metadata, $metadata);
        return $message;
    }

    public function withPayload(array $payload): self
    {
        $message = clone $this;
        $message->payload = array_merge($this->payload, $payload);
        return $message;
    }

    public function isCommand(): bool
    {
        return str_starts_with($this->messageType, 'command.');
    }

    public function isQuery(): bool
    {
        return str_starts_with($this->messageType, 'query.');
    }

    public function isRequest(): bool
    {
        return str_starts_with($this->messageType, 'request.');
    }

    public function getTimeout(): int
    {
        return $this->metadata['timeout'] ?? 30; // 30 seconds default
    }

    public function getRetries(): int
    {
        return $this->metadata['retries'] ?? 3;
    }

    public function getPriority(): int
    {
        return $this->metadata['priority'] ?? 0;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source_module' => $this->sourceModule,
            'target_module' => $this->targetModule,
            'message_type' => $this->messageType,
            'payload' => $this->payload,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt->format('c'),
        ];
    }

    public static function fromArray(array $data): self
    {
        $message = new self(
            $data['source_module'],
            $data['target_module'],
            $data['message_type'],
            $data['payload'] ?? [],
            $data['metadata'] ?? []
        );

        $message->id = $data['id'];
        $message->createdAt = new \DateTimeImmutable($data['created_at']);

        return $message;
    }

    public function __toString(): string
    {
        return sprintf(
            '%s:%s->%s[%s]',
            $this->messageType,
            $this->sourceModule,
            $this->targetModule,
            $this->id
        );
    }
}