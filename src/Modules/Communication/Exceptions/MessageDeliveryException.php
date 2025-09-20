<?php

declare(strict_types=1);

namespace LaravelModularDDD\Modules\Communication\Exceptions;

use LaravelModularDDD\Modules\Communication\ModuleMessage;

class MessageDeliveryException extends \Exception
{
    public static function noHandlerFound(ModuleMessage $message): self
    {
        return new self(
            sprintf(
                'No handler found for message %s targeting module %s with type %s',
                $message->getId(),
                $message->getTargetModule(),
                $message->getMessageType()
            )
        );
    }

    public static function handlerFailed(ModuleMessage $message, \Exception $previous): self
    {
        return new self(
            sprintf(
                'Handler failed for message %s: %s',
                $message->getId(),
                $previous->getMessage()
            ),
            0,
            $previous
        );
    }

    public static function timeoutExceeded(ModuleMessage $message, int $timeout): self
    {
        return new self(
            sprintf(
                'Message %s timed out after %d seconds',
                $message->getId(),
                $timeout
            )
        );
    }

    public static function retryLimitExceeded(ModuleMessage $message, int $retries): self
    {
        return new self(
            sprintf(
                'Message %s exceeded retry limit of %d attempts',
                $message->getId(),
                $retries
            )
        );
    }

    public static function invalidMessageFormat(string $reason): self
    {
        return new self(
            sprintf('Invalid message format: %s', $reason)
        );
    }
}