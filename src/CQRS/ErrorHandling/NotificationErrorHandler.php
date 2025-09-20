<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\ErrorHandling;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;

class NotificationErrorHandler implements ErrorHandlerInterface
{
    private array $criticalExceptions;

    public function __construct(
        private readonly array $notifiableUsers = [],
        private readonly int $priority = 200,
        array $criticalExceptions = []
    ) {
        $this->criticalExceptions = $criticalExceptions ?: [
            \PDOException::class,
            \Illuminate\Database\QueryException::class,
            \Error::class,
        ];
    }

    public function handle(CommandInterface $command, \Throwable $exception, array $context = []): void
    {
        if (!$this->isCriticalException($exception)) {
            return;
        }

        try {
            $notification = new \Illuminate\Notifications\Messages\MailMessage();
            $notification
                ->error()
                ->subject('Critical Command Execution Failure')
                ->line("Command: " . get_class($command))
                ->line("Error: " . $exception->getMessage())
                ->line("File: " . $exception->getFile() . ':' . $exception->getLine())
                ->line("Time: " . now()->toDateTimeString());

            foreach ($this->notifiableUsers as $user) {
                if (is_string($user)) {
                    // Assume it's an email address
                    \Illuminate\Support\Facades\Mail::to($user)->send(
                        new \Illuminate\Mail\Mailable()
                    );
                } else {
                    // Assume it's a User model
                    $user->notify($notification);
                }
            }

        } catch (\Throwable $e) {
            Log::error('Failed to send error notification', [
                'original_error' => $exception->getMessage(),
                'notification_error' => $e->getMessage(),
            ]);
        }
    }

    public function canHandle(\Throwable $exception): bool
    {
        return $this->isCriticalException($exception);
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getName(): string
    {
        return 'notification';
    }

    private function isCriticalException(\Throwable $exception): bool
    {
        foreach ($this->criticalExceptions as $criticalType) {
            if ($exception instanceof $criticalType) {
                return true;
            }
        }

        return false;
    }
}