<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Archival;

use Exception;

class ArchivalException extends Exception
{
    public static function aggregateNotFound(string $aggregateId): self
    {
        return new self("Aggregate {$aggregateId} not found in any storage tier");
    }

    public static function archiveCorrupted(string $path): self
    {
        return new self("Archive file at {$path} is corrupted or unreadable");
    }

    public static function restoreFailed(string $aggregateId, string $reason): self
    {
        return new self("Failed to restore aggregate {$aggregateId}: {$reason}");
    }

    public static function archivalFailed(string $reason): self
    {
        return new self("Archival operation failed: {$reason}");
    }
}