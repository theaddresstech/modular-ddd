<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Exceptions;

class UnauthorizedQueryException extends \Exception
{
    private ?int $userId;

    public function __construct(string $message, ?int $userId = null, int $code = 403, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->userId = $userId;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }
}