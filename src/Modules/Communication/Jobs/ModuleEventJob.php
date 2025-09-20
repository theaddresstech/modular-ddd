<?php

declare(strict_types=1);

namespace LaravelModularDDD\Modules\Communication\Jobs;

use LaravelModularDDD\Modules\Communication\Contracts\ModuleBusInterface;
use LaravelModularDDD\Modules\Communication\ModuleEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

class ModuleEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;
    public int $timeout = 120;

    public function __construct(
        private readonly string $jobId,
        private readonly array $eventData
    ) {}

    public function handle(ModuleBusInterface $moduleBus, LoggerInterface $logger): void
    {
        try {
            $event = ModuleEvent::fromArray($this->eventData);

            $logger->info('Processing async module event', [
                'job_id' => $this->jobId,
                'event_id' => $event->getId(),
                'source' => $event->getSourceModule(),
                'type' => $event->getEventType(),
            ]);

            $moduleBus->publish($event);

            $logger->info('Async module event processed successfully', [
                'job_id' => $this->jobId,
                'event_id' => $event->getId(),
            ]);
        } catch (\Exception $e) {
            $logger->error('Failed to process async module event', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        app(LoggerInterface::class)->error('Module event job permanently failed', [
            'job_id' => $this->jobId,
            'event_data' => $this->eventData,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(15);
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function getEventData(): array
    {
        return $this->eventData;
    }
}