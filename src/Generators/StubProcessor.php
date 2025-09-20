<?php

declare(strict_types=1);

namespace LaravelModularDDD\Generators;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class StubProcessor
{
    private Filesystem $filesystem;
    private string $stubsPath;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
        $this->stubsPath = $this->getStubsPath();
    }

    /**
     * Process stub template with variables
     *
     * @param string $stubName
     * @param array<string, mixed> $variables
     * @return string
     */
    public function process(string $stubName, array $variables): string
    {
        $stubPath = $this->getStubPath($stubName);

        if (!$this->filesystem->exists($stubPath)) {
            throw new InvalidArgumentException("Stub file not found: {$stubPath}");
        }

        $content = $this->filesystem->get($stubPath);

        return $this->replaceVariables($content, $variables);
    }

    /**
     * Get available stub names
     *
     * @return array<string>
     */
    public function getAvailableStubs(): array
    {
        if (!$this->filesystem->exists($this->stubsPath)) {
            return [];
        }

        $files = $this->filesystem->files($this->stubsPath);

        return array_map(function ($file) {
            return pathinfo($file->getFilename(), PATHINFO_FILENAME);
        }, $files);
    }

    /**
     * Check if stub exists
     *
     * @param string $stubName
     * @return bool
     */
    public function stubExists(string $stubName): bool
    {
        return $this->filesystem->exists($this->getStubPath($stubName));
    }

    /**
     * Replace variables in content
     *
     * @param string $content
     * @param array<string, mixed> $variables
     * @return string
     */
    private function replaceVariables(string $content, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $placeholder = '{{ ' . $key . ' }}';
            $content = str_replace($placeholder, $this->convertValueToString($value), $content);
        }

        // Process conditional blocks
        $content = $this->processConditionals($content, $variables);

        // Process loops
        $content = $this->processLoops($content, $variables);

        return $content;
    }

    /**
     * Convert a value to string for template replacement
     *
     * @param mixed $value
     * @return string
     */
    private function convertValueToString($value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            // If it's an array of arrays (like command properties), convert to a formatted string
            if (isset($value[0]) && is_array($value[0])) {
                return $this->formatPropertiesArray($value);
            }
            // Simple array - join with commas
            return implode(', ', array_map([$this, 'convertValueToString'], $value));
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
            return get_class($value);
        }

        if (is_null($value)) {
            return '';
        }

        // Fallback to JSON for complex types
        return json_encode($value) ?: '';
    }

    /**
     * Format an array of property definitions for use in stubs
     *
     * @param array<array<string, string>> $properties
     * @return string
     */
    private function formatPropertiesArray(array $properties): string
    {
        $formatted = [];
        foreach ($properties as $property) {
            if (isset($property['name'], $property['type'])) {
                $formatted[] = "public {$property['type']} \${$property['name']}";
            }
        }
        return implode(";\n    ", $formatted) . (empty($formatted) ? '' : ';');
    }

    /**
     * Process conditional blocks in stubs
     *
     * @param string $content
     * @param array<string, mixed> $variables
     * @return string
     */
    private function processConditionals(string $content, array $variables): string
    {
        // Pattern: {{#if condition}} content {{/if}}
        $pattern = '/\{\{#if\s+(\w+)\}\}(.*?)\{\{\/if\}\}/s';

        return preg_replace_callback($pattern, function ($matches) use ($variables) {
            $condition = $matches[1];
            $conditionalContent = $matches[2];

            if (isset($variables[$condition]) && $variables[$condition]) {
                return $conditionalContent;
            }

            return '';
        }, $content);
    }

    /**
     * Process loop blocks in stubs
     *
     * @param string $content
     * @param array<string, mixed> $variables
     * @return string
     */
    private function processLoops(string $content, array $variables): string
    {
        // Pattern: {{#each items}} content {{/each}}
        $pattern = '/\{\{#each\s+(\w+)\}\}(.*?)\{\{\/each\}\}/s';

        return preg_replace_callback($pattern, function ($matches) use ($variables) {
            $arrayKey = $matches[1];
            $loopContent = $matches[2];

            if (!isset($variables[$arrayKey]) || !is_array($variables[$arrayKey])) {
                return '';
            }

            $output = '';
            foreach ($variables[$arrayKey] as $item) {
                $itemContent = $loopContent;
                if (is_array($item)) {
                    foreach ($item as $key => $value) {
                        $itemContent = str_replace('{{ ' . $key . ' }}', $this->convertValueToString($value), $itemContent);
                    }
                } else {
                    $itemContent = str_replace('{{ item }}', $this->convertValueToString($item), $itemContent);
                }
                $output .= $itemContent;
            }

            return $output;
        }, $content);
    }

    /**
     * Get stubs directory path
     *
     * @return string
     */
    private function getStubsPath(): string
    {
        // Check for published stubs first
        $publishedPath = resource_path('stubs/ddd');
        if ($this->filesystem->exists($publishedPath)) {
            return $publishedPath;
        }

        // Fall back to package stubs
        return __DIR__ . '/../../resources/stubs';
    }

    /**
     * Get full stub file path
     *
     * @param string $stubName
     * @return string
     */
    private function getStubPath(string $stubName): string
    {
        return $this->stubsPath . '/' . $stubName . '.stub';
    }
}