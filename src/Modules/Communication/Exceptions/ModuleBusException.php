<?php

declare(strict_types=1);

namespace LaravelModularDDD\Modules\Communication\Exceptions;

use LaravelModularDDD\Modules\Communication\ModuleMessage;
use LaravelModularDDD\Modules\Communication\ModuleEvent;

class ModuleBusException extends \Exception
{
    public static function messageSendFailed(ModuleMessage $message, \Exception $previous): self
    {
        return new self(
            sprintf(
                'Failed to send message %s from %s to %s: %s',
                $message->getId(),
                $message->getSourceModule(),
                $message->getTargetModule(),
                $previous->getMessage()
            ),
            0,
            $previous
        );
    }

    public static function eventPublishFailed(ModuleEvent $event, \Exception $previous): self
    {
        return new self(
            sprintf(
                'Failed to publish event %s from %s: %s',
                $event->getId(),
                $event->getSourceModule(),
                $previous->getMessage()
            ),
            0,
            $previous
        );
    }

    public static function handlerRegistrationFailed(string $pattern, \Exception $previous): self
    {
        return new self(
            sprintf(
                'Failed to register handler for pattern %s: %s',
                $pattern,
                $previous->getMessage()
            ),
            0,
            $previous
        );
    }

    public static function subscriberRegistrationFailed(string $eventType, \Exception $previous): self
    {
        return new self(
            sprintf(
                'Failed to register subscriber for event type %s: %s',
                $eventType,
                $previous->getMessage()
            ),
            0,
            $previous
        );
    }
}