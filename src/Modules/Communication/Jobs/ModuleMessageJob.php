<?php

declare(strict_types=1);

namespace LaravelModularDDD\Modules\Communication\Jobs;

use LaravelModularDDD\Modules\Communication\Contracts\ModuleBusInterface;
use LaravelModularDDD\Modules\Communication\ModuleMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

class ModuleMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;
    public int $timeout = 300;

    public function __construct(
        private readonly string $jobId,
        private readonly array $messageData
    ) {}

    public function handle(ModuleBusInterface $moduleBus, LoggerInterface $logger): void
    {
        try {
            $message = ModuleMessage::fromArray($this->messageData);

            $logger->info('Processing async module message', [
                'job_id' => $this->jobId,
                'message_id' => $message->getId(),
                'source' => $message->getSourceModule(),
                'target' => $message->getTargetModule(),
            ]);

            $result = $moduleBus->send($message);

            $logger->info('Async module message processed successfully', [
                'job_id' => $this->jobId,
                'message_id' => $message->getId(),
                'result' => is_scalar($result) ? $result : 'object_result',
            ]);
        } catch (\Exception $e) {
            $logger->error('Failed to process async module message', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        app(LoggerInterface::class)->error('Module message job permanently failed', [
            'job_id' => $this->jobId,
            'message_data' => $this->messageData,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(30);
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function getMessageData(): array
    {
        return $this->messageData;
    }
}