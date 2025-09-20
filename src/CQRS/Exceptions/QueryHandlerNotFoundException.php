<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Exceptions;

use Exception;

class QueryHandlerNotFoundException extends Exception
{
    public function __construct(string $queryType)
    {
        parent::__construct("No handler found for query: {$queryType}");
    }
}