<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Retry;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\CQRS\Contracts\CommandBusInterface;
use LaravelModularDDD\CQRS\Events\CommandExecutedEvent;
use LaravelModularDDD\CQRS\Events\CommandFailedEvent;
use LaravelModularDDD\CQRS\Contracts\CommandHandlerInterface;
use LaravelModularDDD\CQRS\Exceptions\CommandHandlerNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;

class RetryableCommandBus implements CommandBusInterface
{
    private array $retryPolicies = [];

    public function __construct(
        private readonly CommandBusInterface $decoratedBus,
        private readonly RetryPolicyInterface $defaultRetryPolicy
    ) {}

    public function dispatch(CommandInterface $command): mixed
    {
        $retryPolicy = $this->getRetryPolicyForCommand($command);
        $attempt = 0;
        $lastException = null;

        while ($attempt <= $retryPolicy->getMaxAttempts()) {
            try {
                $startTime = microtime(true);
                $result = $this->decoratedBus->dispatch($command);
                $executionTime = microtime(true) - $startTime;

                // Fire success event
                Event::dispatch(new CommandExecutedEvent($command, $result, $executionTime, [
                    'attempt' => $attempt + 1,
                    'retry_policy' => $retryPolicy->getName(),
                ]));

                if ($attempt > 0) {
                    Log::info('Command succeeded after retry', [
                        'command_type' => get_class($command),
                        'attempt' => $attempt + 1,
                        'total_attempts' => $attempt + 1,
                        'retry_policy' => $retryPolicy->getName(),
                    ]);
                }

                return $result;

            } catch (\Throwable $e) {
                $lastException = $e;
                $attempt++;

                if ($retryPolicy->shouldRetry($e, $attempt)) {
                    $delay = $retryPolicy->getRetryDelay($attempt);

                    Log::warning('Command failed, will retry', [
                        'command_type' => get_class($command),
                        'attempt' => $attempt,
                        'max_attempts' => $retryPolicy->getMaxAttempts(),
                        'error' => $e->getMessage(),
                        'retry_delay_ms' => $delay,
                        'retry_policy' => $retryPolicy->getName(),
                    ]);

                    if ($delay > 0) {
                        usleep($delay * 1000); // Convert ms to microseconds
                    }

                    continue;
                }

                // All retries exhausted or non-retryable exception
                $executionTime = microtime(true) - ($startTime ?? microtime(true));

                Event::dispatch(new CommandFailedEvent($command, $e, $executionTime, [
                    'total_attempts' => $attempt,
                    'retry_policy' => $retryPolicy->getName(),
                    'retries_exhausted' => $attempt > 1,
                ]));

                Log::error('Command failed after all retries', [
                    'command_type' => get_class($command),
                    'total_attempts' => $attempt,
                    'final_error' => $e->getMessage(),
                    'retry_policy' => $retryPolicy->getName(),
                ]);

                throw $e;
            }
        }

        // This should never be reached, but just in case
        throw $lastException ?? new \RuntimeException('Unexpected retry loop termination');
    }

    public function registerHandler(string $commandClass, CommandHandlerInterface $handler): void
    {
        if ($this->decoratedBus instanceof \LaravelModularDDD\CQRS\CommandBus) {
            $this->decoratedBus->registerHandler($commandClass, $handler);
        }
    }

    public function addMiddleware(callable $middleware): void
    {
        if ($this->decoratedBus instanceof \LaravelModularDDD\CQRS\CommandBus) {
            $this->decoratedBus->addMiddleware($middleware);
        }
    }

    /**
     * Register retry policy for specific command type
     */
    public function registerRetryPolicy(string $commandClass, RetryPolicyInterface $retryPolicy): void
    {
        $this->retryPolicies[$commandClass] = $retryPolicy;
    }

    /**
     * Get retry statistics
     */
    public function getRetryStatistics(): array
    {
        // This would typically come from metrics collection
        return [
            'registered_policies' => count($this->retryPolicies),
            'default_policy' => $this->defaultRetryPolicy->getName(),
            'command_policies' => array_map(
                fn($policy) => $policy->getName(),
                $this->retryPolicies
            ),
        ];
    }

    private function getRetryPolicyForCommand(CommandInterface $command): RetryPolicyInterface
    {
        $commandClass = get_class($command);

        // Check for exact match first
        if (isset($this->retryPolicies[$commandClass])) {
            return $this->retryPolicies[$commandClass];
        }

        // Check for parent class matches
        foreach ($this->retryPolicies as $registeredClass => $policy) {
            if (is_subclass_of($commandClass, $registeredClass)) {
                return $policy;
            }
        }

        // Check if command implements RetryableCommand interface
        if ($command instanceof RetryableCommandInterface) {
            return $command->getRetryPolicy();
        }

        return $this->defaultRetryPolicy;
    }
}