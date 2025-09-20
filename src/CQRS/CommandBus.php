<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS;

use LaravelModularDDD\CQRS\Contracts\CommandBusInterface;
use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\CQRS\Contracts\CommandHandlerInterface;
use LaravelModularDDD\CQRS\Contracts\MiddlewareInterface;
use LaravelModularDDD\CQRS\Exceptions\CommandHandlerNotFoundException;
use LaravelModularDDD\CQRS\Exceptions\CommandValidationException;
use LaravelModularDDD\CQRS\Security\CommandAuthorizationManager;
use LaravelModularDDD\CQRS\Async\AsyncStrategyInterface;
use LaravelModularDDD\CQRS\Jobs\ProcessCommandJob;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CommandBus implements CommandBusInterface
{
    /** @var CommandHandlerInterface[] */
    private array $handlers = [];

    /** @var MiddlewareInterface[] */
    private array $middleware = [];

    private const HANDLER_CACHE_TTL = 3600;
    private const METRICS_CACHE_TTL = 300;

    private ?CommandAuthorizationManager $authManager = null;
    private ?AsyncStrategyInterface $asyncStrategy = null;

    public function __construct(
        private readonly Pipeline $pipeline,
        private readonly Queue $queue,
        private readonly string $defaultExecutionMode = 'sync',
        ?CommandAuthorizationManager $authManager = null,
        ?AsyncStrategyInterface $asyncStrategy = null
    ) {
        $this->authManager = $authManager;
        $this->asyncStrategy = $asyncStrategy;
    }

    public function dispatch(CommandInterface $command): mixed
    {
        $startTime = microtime(true);

        try {
            // Authorization check
            if ($this->authManager) {
                $this->authManager->authorizeCommand($command);
            }

            // Resolve execution mode
            $mode = $this->resolveExecutionMode($command);

            $result = match ($mode) {
                'sync' => $this->dispatchSync($command),
                'async' => $this->dispatchAsync($command),
                'queued' => $this->queue($command),
                default => throw new \InvalidArgumentException("Invalid execution mode: {$mode}")
            };

            $this->recordMetrics($command, microtime(true) - $startTime, true);

            return $result;
        } catch (\Exception $e) {
            $this->recordMetrics($command, microtime(true) - $startTime, false, $e);
            throw $e;
        }
    }

    public function dispatchAsync(CommandInterface $command): string
    {
        if (!$this->asyncStrategy) {
            throw new \RuntimeException('No async strategy configured for command bus');
        }

        if (!$this->asyncStrategy->supports($command)) {
            throw new \RuntimeException(
                "Async strategy '{$this->asyncStrategy->getName()}' does not support command: " .
                $command->getCommandName()
            );
        }

        // Authorization check for async commands
        if ($this->authManager) {
            $this->authManager->authorizeCommand($command);
        }

        return $this->asyncStrategy->dispatch($command);
    }

    /**
     * Get the status of an async command
     */
    public function getAsyncStatus(string $asyncId): string
    {
        if (!$this->asyncStrategy) {
            throw new \RuntimeException('No async strategy configured for command bus');
        }

        return $this->asyncStrategy->getStatus($asyncId)->value;
    }

    /**
     * Get the result of a completed async command
     */
    public function getAsyncResult(string $asyncId): mixed
    {
        if (!$this->asyncStrategy) {
            throw new \RuntimeException('No async strategy configured for command bus');
        }

        return $this->asyncStrategy->getResult($asyncId);
    }

    /**
     * Cancel a pending async command
     */
    public function cancelAsync(string $asyncId): bool
    {
        if (!$this->asyncStrategy) {
            throw new \RuntimeException('No async strategy configured for command bus');
        }

        return $this->asyncStrategy->cancel($asyncId);
    }

    public function queue(CommandInterface $command, ?string $queue = null): string
    {
        $queue = $queue ?? $this->getQueueForCommand($command);

        $job = new ProcessCommandJob($command);

        // Set job priority based on command priority
        if ($command->getPriority() > 0) {
            $job->onQueue($queue . '_high');
        } elseif ($command->getPriority() < 0) {
            $job->onQueue($queue . '_low');
        } else {
            $job->onQueue($queue);
        }

        // Set job timeout
        $job->timeout($command->getTimeout());

        // Configure retries
        if ($command->shouldRetry()) {
            $job->tries($command->getMaxRetries());
        }

        $jobId = $this->queue->push($job);

        Log::info('Command queued for processing', [
            'command_id' => $command->getCommandId(),
            'command_type' => $command->getCommandName(),
            'queue' => $queue,
            'job_id' => $jobId,
            'priority' => $command->getPriority(),
        ]);

        return $jobId;
    }

    public function registerHandler(CommandHandlerInterface $handler): void
    {
        $commandType = $handler->getHandledCommandType();

        if (!isset($this->handlers[$commandType])) {
            $this->handlers[$commandType] = [];
        }

        $this->handlers[$commandType][] = $handler;

        // Sort by priority (highest first)
        usort($this->handlers[$commandType], function ($a, $b) {
            return $b->getPriority() <=> $a->getPriority();
        });

        // Clear handler cache
        Cache::forget("command_handler:{$commandType}");
    }

    public function addMiddleware(MiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;

        // Sort middleware by priority (highest first)
        usort($this->middleware, function ($a, $b) {
            return $b->getPriority() <=> $a->getPriority();
        });
    }

    public function getHandler(CommandInterface $command): CommandHandlerInterface
    {
        $commandType = $command->getCommandName();
        $cacheKey = "command_handler:{$commandType}";

        return Cache::remember($cacheKey, self::HANDLER_CACHE_TTL, function () use ($command, $commandType) {
            if (!isset($this->handlers[$commandType])) {
                throw new CommandHandlerNotFoundException($commandType);
            }

            // Find the first handler that can handle this command
            foreach ($this->handlers[$commandType] as $handler) {
                if ($handler->canHandle($command)) {
                    return $handler;
                }
            }

            throw new CommandHandlerNotFoundException($commandType);
        });
    }

    public function canHandle(CommandInterface $command): bool
    {
        try {
            $this->getHandler($command);
            return true;
        } catch (CommandHandlerNotFoundException) {
            return false;
        }
    }

    /**
     * Process command through middleware pipeline
     */
    public function processCommand(CommandInterface $command): mixed
    {
        $applicableMiddleware = array_filter(
            $this->middleware,
            fn(MiddlewareInterface $middleware) => $middleware->shouldProcess($command)
        );

        return $this->pipeline
            ->send($command)
            ->through($applicableMiddleware)
            ->then(function (CommandInterface $command) {
                $handler = $this->getHandler($command);
                return $handler->handle($command);
            });
    }

    /**
     * Get command processing statistics
     */
    public function getStatistics(): array
    {
        $stats = Cache::get('command_bus_stats', [
            'total_commands' => 0,
            'successful_commands' => 0,
            'failed_commands' => 0,
            'avg_execution_time_ms' => 0,
            'commands_by_type' => [],
            'execution_modes' => [
                'sync' => 0,
                'async' => 0,
                'queued' => 0,
            ],
        ]);

        $stats['success_rate'] = $stats['total_commands'] > 0
            ? ($stats['successful_commands'] / $stats['total_commands']) * 100
            : 0;

        return $stats;
    }

    /**
     * Clear all command bus statistics
     */
    public function clearStatistics(): void
    {
        Cache::forget('command_bus_stats');
    }

    /**
     * Validate command before processing
     */
    public function validateCommand(CommandInterface $command): void
    {
        $rules = $command->getValidationRules();

        if (empty($rules)) {
            return;
        }

        $validator = validator($command->toArray(), $rules);

        if ($validator->fails()) {
            throw new CommandValidationException($command->getCommandName(), $validator->errors());
        }
    }

    private function dispatchSync(CommandInterface $command): mixed
    {
        Log::info('Processing command synchronously', [
            'command_id' => $command->getCommandId(),
            'command_type' => $command->getCommandName(),
        ]);

        return $this->processCommand($command);
    }

    private function resolveExecutionMode(CommandInterface $command): string
    {
        // Check for explicit mode in metadata
        $metadata = $command->getMetadata();
        if (isset($metadata['execution_mode'])) {
            return $metadata['execution_mode'];
        }

        // Determine mode based on command characteristics
        if ($command->getPriority() > 5) {
            return 'sync'; // High priority commands run synchronously
        }

        if ($command->getTimeout() > 60) {
            return 'queued'; // Long-running commands go to queue
        }

        return $this->defaultExecutionMode;
    }

    private function getQueueForCommand(CommandInterface $command): string
    {
        $metadata = $command->getMetadata();

        if (isset($metadata['queue'])) {
            return $metadata['queue'];
        }

        // Default queue based on command type
        $commandType = $command->getCommandName();
        $shortName = (new \ReflectionClass($commandType))->getShortName();

        return 'commands_' . strtolower(preg_replace('/Command$/', '', $shortName));
    }

    private function recordMetrics(
        CommandInterface $command,
        float $executionTime,
        bool $success,
        ?\Exception $exception = null
    ): void {
        $stats = Cache::get('command_bus_stats', [
            'total_commands' => 0,
            'successful_commands' => 0,
            'failed_commands' => 0,
            'avg_execution_time_ms' => 0,
            'commands_by_type' => [],
            'execution_modes' => ['sync' => 0, 'async' => 0, 'queued' => 0],
        ]);

        $commandType = $command->getCommandName();
        $executionTimeMs = $executionTime * 1000;

        // Update counters
        $stats['total_commands']++;
        if ($success) {
            $stats['successful_commands']++;
        } else {
            $stats['failed_commands']++;
        }

        // Update average execution time
        $totalTime = $stats['avg_execution_time_ms'] * ($stats['total_commands'] - 1);
        $stats['avg_execution_time_ms'] = ($totalTime + $executionTimeMs) / $stats['total_commands'];

        // Update command type stats
        if (!isset($stats['commands_by_type'][$commandType])) {
            $stats['commands_by_type'][$commandType] = [
                'count' => 0,
                'success_count' => 0,
                'avg_time_ms' => 0,
            ];
        }

        $typeStats = &$stats['commands_by_type'][$commandType];
        $typeStats['count']++;
        if ($success) {
            $typeStats['success_count']++;
        }

        $typeTotalTime = $typeStats['avg_time_ms'] * ($typeStats['count'] - 1);
        $typeStats['avg_time_ms'] = ($typeTotalTime + $executionTimeMs) / $typeStats['count'];

        Cache::put('command_bus_stats', $stats, now()->addSeconds(self::METRICS_CACHE_TTL));

        // Log detailed metrics
        Log::info('Command executed', [
            'command_id' => $command->getCommandId(),
            'command_type' => $commandType,
            'execution_time_ms' => round($executionTimeMs, 2),
            'success' => $success,
            'error' => $exception?->getMessage(),
        ]);
    }
}