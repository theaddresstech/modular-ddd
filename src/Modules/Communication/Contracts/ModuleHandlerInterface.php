<?php

declare(strict_types=1);

namespace LaravelModularDDD\Modules\Communication\Contracts;

/**
 * Interface for module handlers.
 *
 * Defines the contract for classes that can handle module messages.
 */
interface ModuleHandlerInterface
{
    /**
     * Handle a module message.
     *
     * @param ModuleMessageInterface $message The message to handle
     * @return array The response data
     */
    public function handle(ModuleMessageInterface $message): array;

    /**
     * Handle a module event.
     *
     * @param string $eventType The type of event
     * @param array $eventData The event data
     * @return array The response data
     */
    public function handleEvent(string $eventType, array $eventData): array;

    /**
     * Check if a specific event type was received.
     *
     * @param string $eventType The event type to check
     * @return bool True if the event was received
     */
    public function hasReceivedEvent(string $eventType): bool;

    /**
     * Get the health status of the module.
     *
     * @return array Health status information
     */
    public function getHealthStatus(): array;
}