<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Middleware;

use LaravelModularDDD\CQRS\Contracts\MiddlewareInterface;
use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\CQRS\Exceptions\CommandAuthorizationException;
use Closure;
use Illuminate\Support\Facades\Gate;

class AuthorizationMiddleware implements MiddlewareInterface
{
    public function handle(mixed $message, Closure $next): mixed
    {
        if ($message instanceof CommandInterface) {
            $this->authorizeCommand($message);
        }

        return $next($message);
    }

    public function getPriority(): int
    {
        return 90; // High priority - authorize after validation
    }

    public function shouldProcess(mixed $message): bool
    {
        return $message instanceof CommandInterface && auth()->check();
    }

    private function authorizeCommand(CommandInterface $command): void
    {
        $commandType = $command->getCommandName();
        $gateName = $this->getGateName($commandType);

        // Check if gate exists
        if (!Gate::has($gateName)) {
            // No specific gate defined, allow by default
            return;
        }

        $user = auth()->user();

        if (!Gate::forUser($user)->allows($gateName, $command)) {
            throw new CommandAuthorizationException(
                $commandType,
                $user->id ?? 'anonymous'
            );
        }
    }

    private function getGateName(string $commandType): string
    {
        // Convert command class name to gate name
        // e.g., CreateOrderCommand -> create-order
        $shortName = (new \ReflectionClass($commandType))->getShortName();
        $gateName = preg_replace('/Command$/', '', $shortName);

        return strtolower(preg_replace('/([A-Z])/', '-$1', lcfirst($gateName)));
    }
}