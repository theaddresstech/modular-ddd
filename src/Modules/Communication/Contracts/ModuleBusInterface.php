<?php

declare(strict_types=1);

namespace LaravelModularDDD\Modules\Communication\Contracts;

use LaravelModularDDD\Modules\Communication\ModuleMessage;
use LaravelModularDDD\Modules\Communication\ModuleEvent;

/**
 * ModuleBusInterface
 *
 * Defines the contract for inter-module communication.
 * Provides methods for sending messages, publishing events, and subscribing to events.
 */
interface ModuleBusInterface
{
    /**
     * Send a message to a specific module and expect a response.
     */
    public function send(ModuleMessage $message): mixed;

    /**
     * Send a message asynchronously without expecting a response.
     */
    public function sendAsync(ModuleMessage $message): string;

    /**
     * Publish an event that multiple modules can subscribe to.
     */
    public function publish(ModuleEvent $event): void;

    /**
     * Publish an event asynchronously.
     */
    public function publishAsync(ModuleEvent $event): string;

    /**
     * Subscribe to events from a specific module or event type.
     */
    public function subscribe(string $eventType, callable $handler): void;

    /**
     * Unsubscribe from events.
     */
    public function unsubscribe(string $eventType, callable $handler): void;

    /**
     * Register a message handler for a specific module.
     */
    public function registerHandler(string $modulePattern, callable $handler): void;

    /**
     * Remove a message handler.
     */
    public function removeHandler(string $modulePattern): void;

    /**
     * Check if a message can be delivered to a module.
     */
    public function canDeliver(ModuleMessage $message): bool;

    /**
     * Get statistics about message processing.
     */
    public function getStatistics(): array;

    /**
     * Clear all pending messages and events.
     */
    public function flush(): void;
}