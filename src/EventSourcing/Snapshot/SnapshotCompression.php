<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Snapshot;

class SnapshotCompression
{
    private string $compressionMethod;
    private int $compressionLevel;

    public function __construct(string $compressionMethod = 'gzip', int $compressionLevel = 6)
    {
        $this->validateCompressionMethod($compressionMethod);
        $this->compressionMethod = $compressionMethod;
        $this->compressionLevel = $compressionLevel;
    }

    /**
     * Compress snapshot data
     */
    public function compress(string $data): string
    {
        if (empty($data)) {
            return $data;
        }

        return match ($this->compressionMethod) {
            'gzip' => $this->compressGzip($data),
            'deflate' => $this->compressDeflate($data),
            'bzip2' => $this->compressBzip2($data),
            'none' => $data,
            default => throw new \InvalidArgumentException("Unknown compression method: {$this->compressionMethod}"),
        };
    }

    /**
     * Decompress snapshot data
     */
    public function decompress(string $compressedData, ?string $compressionMethod = null): string
    {
        if (empty($compressedData)) {
            return $compressedData;
        }

        $method = $compressionMethod ?? $this->compressionMethod;

        return match ($method) {
            'gzip' => $this->decompressGzip($compressedData),
            'deflate' => $this->decompressDeflate($compressedData),
            'bzip2' => $this->decompressBzip2($compressedData),
            'none' => $compressedData,
            default => throw new \InvalidArgumentException("Unknown compression method: {$method}"),
        };
    }

    /**
     * Get compression ratio for given data
     */
    public function getCompressionRatio(string $originalData): float
    {
        if (empty($originalData)) {
            return 1.0;
        }

        $compressed = $this->compress($originalData);
        return strlen($compressed) / strlen($originalData);
    }

    /**
     * Get compression statistics
     */
    public function getCompressionStats(string $originalData): array
    {
        $originalSize = strlen($originalData);
        $compressed = $this->compress($originalData);
        $compressedSize = strlen($compressed);

        return [
            'original_size_bytes' => $originalSize,
            'compressed_size_bytes' => $compressedSize,
            'compression_ratio' => $compressedSize / $originalSize,
            'space_saved_bytes' => $originalSize - $compressedSize,
            'space_saved_percentage' => (($originalSize - $compressedSize) / $originalSize) * 100,
            'compression_method' => $this->compressionMethod,
            'compression_level' => $this->compressionLevel,
        ];
    }

    /**
     * Test if compression is beneficial for given data
     */
    public function shouldCompress(string $data, float $minCompressionRatio = 0.8): bool
    {
        if (strlen($data) < 1024) { // Don't compress small data (< 1KB)
            return false;
        }

        $ratio = $this->getCompressionRatio($data);
        return $ratio <= $minCompressionRatio;
    }

    /**
     * Get available compression methods
     */
    public static function getAvailableMethods(): array
    {
        $methods = ['none'];

        if (function_exists('gzcompress')) {
            $methods[] = 'gzip';
        }

        if (function_exists('gzdeflate')) {
            $methods[] = 'deflate';
        }

        if (function_exists('bzcompress')) {
            $methods[] = 'bzip2';
        }

        return $methods;
    }

    private function compressGzip(string $data): string
    {
        $compressed = gzcompress($data, $this->compressionLevel);

        if ($compressed === false) {
            throw new \RuntimeException('Failed to compress data with gzip');
        }

        return $compressed;
    }

    private function decompressGzip(string $compressedData): string
    {
        $decompressed = gzuncompress($compressedData);

        if ($decompressed === false) {
            throw new \RuntimeException('Failed to decompress data with gzip');
        }

        return $decompressed;
    }

    private function compressDeflate(string $data): string
    {
        $compressed = gzdeflate($data, $this->compressionLevel);

        if ($compressed === false) {
            throw new \RuntimeException('Failed to compress data with deflate');
        }

        return $compressed;
    }

    private function decompressDeflate(string $compressedData): string
    {
        $decompressed = gzinflate($compressedData);

        if ($decompressed === false) {
            throw new \RuntimeException('Failed to decompress data with deflate');
        }

        return $decompressed;
    }

    private function compressBzip2(string $data): string
    {
        $compressed = bzcompress($data, $this->compressionLevel);

        if ($compressed === false) {
            throw new \RuntimeException('Failed to compress data with bzip2');
        }

        return $compressed;
    }

    private function decompressBzip2(string $compressedData): string
    {
        $decompressed = bzdecompress($compressedData);

        if ($decompressed === false) {
            throw new \RuntimeException('Failed to decompress data with bzip2');
        }

        return $decompressed;
    }

    private function validateCompressionMethod(string $method): void
    {
        $availableMethods = self::getAvailableMethods();

        if (!in_array($method, $availableMethods)) {
            throw new \InvalidArgumentException(
                "Compression method '{$method}' is not available. Available methods: " . implode(', ', $availableMethods)
            );
        }

        // Check if required extension is loaded
        switch ($method) {
            case 'gzip':
            case 'deflate':
                if (!extension_loaded('zlib')) {
                    throw new \RuntimeException('zlib extension is required for gzip/deflate compression');
                }
                break;

            case 'bzip2':
                if (!extension_loaded('bz2')) {
                    throw new \RuntimeException('bz2 extension is required for bzip2 compression');
                }
                break;
        }
    }
}