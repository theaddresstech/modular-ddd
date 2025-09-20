<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\ReadModels;

use LaravelModularDDD\Core\Events\DomainEventInterface;
use Illuminate\Support\Str;

abstract class AbstractReadModelGenerator implements ReadModelGeneratorInterface
{
    protected array $eventHandlers = [];
    protected int $priority = 100;

    public function __construct()
    {
        $this->registerEventHandlers();
    }

    public function generate(string $aggregateId, array $events): ReadModel
    {
        $readModel = $this->createEmptyReadModel($aggregateId);

        return $this->update($readModel, $events);
    }

    public function update(ReadModel $readModel, array $events): ReadModel
    {
        $version = $readModel->getVersion();

        foreach ($events as $event) {
            if ($this->supports($event)) {
                $readModel = $this->applyEvent($readModel, $event);
                $version++;
            }
        }

        return $readModel->withVersion($version);
    }

    public function supports(DomainEventInterface $event): bool
    {
        $eventType = get_class($event);
        return isset($this->eventHandlers[$eventType]);
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function validate(ReadModel $readModel): bool
    {
        $schema = $this->getSchema();

        foreach ($schema['required'] ?? [] as $requiredField) {
            if (!$readModel->has($requiredField)) {
                return false;
            }
        }

        foreach ($schema['fields'] ?? [] as $field => $rules) {
            if ($readModel->has($field) && !$this->validateField($readModel->get($field), $rules)) {
                return false;
            }
        }

        return true;
    }

    public function getSchema(): array
    {
        return [
            'fields' => [],
            'required' => [],
            'indexes' => [],
        ];
    }

    protected function createEmptyReadModel(string $aggregateId): ReadModel
    {
        return new ReadModel(
            $aggregateId,
            $this->getReadModelType(),
            $this->getInitialData(),
            0,
            new \DateTimeImmutable(),
            $this->getInitialMetadata()
        );
    }

    protected function applyEvent(ReadModel $readModel, DomainEventInterface $event): ReadModel
    {
        $eventType = get_class($event);
        $handler = $this->eventHandlers[$eventType] ?? null;

        if (!$handler) {
            return $readModel;
        }

        if (!method_exists($this, $handler)) {
            throw new \RuntimeException("Event handler method {$handler} not found");
        }

        return $this->$handler($readModel, $event);
    }

    protected function registerEventHandler(string $eventClass, string $methodName): void
    {
        $this->eventHandlers[$eventClass] = $methodName;
    }

    protected function getInitialData(): array
    {
        return [];
    }

    protected function getInitialMetadata(): array
    {
        return [
            'generator' => static::class,
            'created_at' => now()->toISOString(),
        ];
    }

    protected function validateField(mixed $value, array $rules): bool
    {
        foreach ($rules as $rule => $constraint) {
            if (!$this->applyValidationRule($value, $rule, $constraint)) {
                return false;
            }
        }

        return true;
    }

    protected function applyValidationRule(mixed $value, string $rule, mixed $constraint): bool
    {
        return match ($rule) {
            'type' => $this->validateType($value, $constraint),
            'min' => is_numeric($value) && $value >= $constraint,
            'max' => is_numeric($value) && $value <= $constraint,
            'min_length' => is_string($value) && strlen($value) >= $constraint,
            'max_length' => is_string($value) && strlen($value) <= $constraint,
            'in' => in_array($value, $constraint),
            'not_empty' => !empty($value),
            'regex' => is_string($value) && preg_match($constraint, $value),
            default => true,
        };
    }

    protected function validateType(mixed $value, string $expectedType): bool
    {
        return match ($expectedType) {
            'string' => is_string($value),
            'int', 'integer' => is_int($value),
            'float', 'double' => is_float($value),
            'bool', 'boolean' => is_bool($value),
            'array' => is_array($value),
            'object' => is_object($value),
            'null' => is_null($value),
            'numeric' => is_numeric($value),
            default => gettype($value) === $expectedType,
        };
    }

    /**
     * Override this method to register event handlers
     */
    abstract protected function registerEventHandlers(): void;
}