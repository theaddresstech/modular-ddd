<?php

declare(strict_types=1);

namespace LaravelModularDDD\Generators\Contracts;

interface GeneratorInterface
{
    /**
     * Generate component with given options
     *
     * @param string $moduleName
     * @param string $componentName
     * @param array<string, mixed> $options
     * @return array<string> Generated file paths
     */
    public function generate(string $moduleName, string $componentName, array $options = []): array;

    /**
     * Validate generation parameters
     *
     * @param string $moduleName
     * @param string $componentName
     * @param array<string, mixed> $options
     * @return bool
     */
    public function validate(string $moduleName, string $componentName, array $options = []): bool;

    /**
     * Get generator name
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get supported options
     *
     * @return array<string, string>
     */
    public function getSupportedOptions(): array;
}