<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Middleware;

use LaravelModularDDD\CQRS\Contracts\MiddlewareInterface;
use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\CQRS\Exceptions\CommandValidationException;
use Closure;
use Illuminate\Support\Facades\Validator;

class ValidationMiddleware implements MiddlewareInterface
{
    public function handle(mixed $message, Closure $next): mixed
    {
        if ($message instanceof CommandInterface) {
            $this->validateCommand($message);
        }

        return $next($message);
    }

    public function getPriority(): int
    {
        return 100; // High priority - validate first
    }

    public function shouldProcess(mixed $message): bool
    {
        return $message instanceof CommandInterface;
    }

    private function validateCommand(CommandInterface $command): void
    {
        $rules = $command->getValidationRules();

        if (empty($rules)) {
            return;
        }

        $data = $this->extractValidationData($command);
        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new CommandValidationException(
                $command->getCommandName(),
                $validator->errors()
            );
        }
    }

    private function extractValidationData(CommandInterface $command): array
    {
        $reflection = new \ReflectionClass($command);
        $data = [];

        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($command);

            // Convert objects to arrays for validation
            if (is_object($value) && method_exists($value, 'toArray')) {
                $value = $value->toArray();
            }

            $data[$property->getName()] = $value;
        }

        return $data;
    }
}