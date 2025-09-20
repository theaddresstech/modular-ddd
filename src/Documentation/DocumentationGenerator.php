<?php

declare(strict_types=1);

namespace LaravelModularDDD\Documentation;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use LaravelModularDDD\Support\ModuleRegistry;

/**
 * DocumentationGenerator
 *
 * Generates comprehensive documentation for DDD modules.
 * Creates README files, API docs, architectural diagrams, and system overviews.
 */
final class DocumentationGenerator
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly ModuleRegistry $moduleRegistry
    ) {}

    /**
     * Generate documentation for a specific module.
     */
    public function generateModuleDocumentation(array $module, array $options): array
    {
        $result = [
            'files_created' => [],
            'files_updated' => [],
            'warnings' => [],
        ];

        $moduleName = $module['name'];
        $outputDir = $this->getModuleOutputDirectory($moduleName, $options);

        // Ensure output directory exists
        if (!is_dir($outputDir)) {
            $this->filesystem->makeDirectory($outputDir, 0755, true);
        }

        // Generate README
        $readmeFile = $this->generateModuleReadme($module, $outputDir, $options);
        if ($readmeFile) {
            $result['files_created'][] = $readmeFile;
        }

        // Generate architecture documentation
        $archFile = $this->generateArchitectureDocumentation($module, $outputDir, $options);
        if ($archFile) {
            $result['files_created'][] = $archFile;
        }

        // Generate API documentation if requested
        if ($options['include_api']) {
            $apiFiles = $this->generateApiDocumentation($module, $outputDir, $options);
            $result['files_created'] = array_merge($result['files_created'], $apiFiles);
        }

        // Generate component documentation
        $componentFiles = $this->generateComponentDocumentation($module, $outputDir, $options);
        $result['files_created'] = array_merge($result['files_created'], $componentFiles);

        // Generate test documentation if requested
        if ($options['include_tests']) {
            $testFiles = $this->generateTestDocumentation($module, $outputDir, $options);
            $result['files_created'] = array_merge($result['files_created'], $testFiles);
        }

        // Generate diagrams if requested
        if ($options['include_diagrams']) {
            $diagramFiles = $this->generateDiagrams($module, $outputDir, $options);
            $result['files_created'] = array_merge($result['files_created'], $diagramFiles);
            $result['diagrams'] = true;
        }

        return $result;
    }

    /**
     * Generate system overview documentation.
     */
    public function generateSystemOverview(Collection $modules, array $options): array
    {
        $result = [
            'files_created' => [],
            'warnings' => [],
        ];

        $outputDir = $this->getSystemOutputDirectory($options);

        // Ensure output directory exists
        if (!is_dir($outputDir)) {
            $this->filesystem->makeDirectory($outputDir, 0755, true);
        }

        // Generate system overview
        $overviewFile = $this->generateSystemOverviewFile($modules, $outputDir, $options);
        if ($overviewFile) {
            $result['files_created'][] = $overviewFile;
            $result['overview_file'] = $overviewFile;
        }

        // Generate module index
        $indexFile = $this->generateModuleIndex($modules, $outputDir, $options);
        if ($indexFile) {
            $result['files_created'][] = $indexFile;
        }

        // Generate dependency diagram
        if ($options['include_diagrams']) {
            $dependencyDiagram = $this->generateDependencyDiagram($modules, $outputDir, $options);
            if ($dependencyDiagram) {
                $result['files_created'][] = $dependencyDiagram;
            }
        }

        return $result;
    }

    /**
     * Get module output directory.
     */
    private function getModuleOutputDirectory(string $moduleName, array $options): string
    {
        $baseDir = $options['output_directory'] ?? 'docs';
        return "{$baseDir}/modules/{$moduleName}";
    }

    /**
     * Get system output directory.
     */
    private function getSystemOutputDirectory(array $options): string
    {
        return $options['output_directory'] ?? 'docs';
    }

    /**
     * Generate module README file.
     */
    private function generateModuleReadme(array $module, string $outputDir, array $options): ?string
    {
        $readmeFile = "{$outputDir}/README.md";

        if ($this->filesystem->exists($readmeFile) && !$options['force']) {
            return null;
        }

        $content = $this->generateReadmeContent($module, $options);
        $this->filesystem->put($readmeFile, $content);

        return $readmeFile;
    }

    /**
     * Generate README content.
     */
    private function generateReadmeContent(array $module, array $options): string
    {
        $moduleName = $module['name'];
        $components = $module['components'] ?? [];

        $content = "# {$moduleName} Module\n\n";

        // Module description
        $content .= "## Overview\n\n";
        $content .= "The {$moduleName} module implements domain-driven design principles with a four-layer architecture.\n\n";

        // Architecture section
        $content .= "## Architecture\n\n";
        $content .= "This module follows the DDD four-layer architecture:\n\n";
        $content .= "- **Domain Layer**: Business logic, aggregates, and domain events\n";
        $content .= "- **Application Layer**: Use cases, commands, and queries\n";
        $content .= "- **Infrastructure Layer**: Data persistence and external services\n";
        $content .= "- **Presentation Layer**: HTTP controllers and API endpoints\n\n";

        // Components section
        $content .= "## Components\n\n";
        $content .= $this->generateComponentsSection($components);

        // Dependencies section
        if (!empty($module['dependencies']['module_dependencies'])) {
            $content .= "## Dependencies\n\n";
            $content .= "This module depends on:\n\n";
            foreach ($module['dependencies']['module_dependencies'] as $dependency) {
                $content .= "- {$dependency}\n";
            }
            $content .= "\n";
        }

        // API section placeholder
        if ($options['include_api']) {
            $content .= "## API Endpoints\n\n";
            $content .= "See [API Documentation](./api.md) for detailed endpoint information.\n\n";
        }

        // Tests section placeholder
        if ($options['include_tests']) {
            $content .= "## Testing\n\n";
            $content .= "See [Test Documentation](./tests.md) for testing information.\n\n";
        }

        // Usage examples
        $content .= "## Usage Examples\n\n";
        $content .= $this->generateUsageExamples($module);

        return $content;
    }

    /**
     * Generate components section content.
     */
    private function generateComponentsSection(array $components): string
    {
        $content = "";

        $componentTypes = [
            'aggregates' => 'Aggregates',
            'commands' => 'Commands',
            'queries' => 'Queries',
            'events' => 'Domain Events',
            'value_objects' => 'Value Objects',
            'controllers' => 'Controllers',
        ];

        foreach ($componentTypes as $type => $title) {
            $items = $components[$type] ?? [];
            if (!empty($items)) {
                $content .= "### {$title}\n\n";
                foreach ($items as $item) {
                    $name = is_array($item) ? $item['name'] : $item;
                    $content .= "- `{$name}`\n";
                }
                $content .= "\n";
            }
        }

        return $content;
    }

    /**
     * Generate usage examples.
     */
    private function generateUsageExamples(array $module): string
    {
        $moduleName = $module['name'];
        $content = "";

        $content .= "### Command Example\n\n";
        $content .= "```php\n";
        $content .= "use Modules\\{$moduleName}\\Application\\Commands\\Create{$moduleName}Command;\n\n";
        $content .= "\$command = new Create{$moduleName}Command(\n";
        $content .= "    \$id,\n";
        $content .= "    \$name,\n";
        $content .= "    \$description\n";
        $content .= ");\n\n";
        $content .= "\$result = \$commandBus->dispatch(\$command);\n";
        $content .= "```\n\n";

        $content .= "### Query Example\n\n";
        $content .= "```php\n";
        $content .= "use Modules\\{$moduleName}\\Application\\Queries\\Get{$moduleName}Query;\n\n";
        $content .= "\$query = new Get{$moduleName}Query(\$id);\n";
        $content .= "\$result = \$queryBus->dispatch(\$query);\n";
        $content .= "```\n\n";

        return $content;
    }

    /**
     * Generate architecture documentation.
     */
    private function generateArchitectureDocumentation(array $module, string $outputDir, array $options): ?string
    {
        $archFile = "{$outputDir}/architecture.md";

        if ($this->filesystem->exists($archFile) && !$options['force']) {
            return null;
        }

        $content = $this->generateArchitectureContent($module);
        $this->filesystem->put($archFile, $content);

        return $archFile;
    }

    /**
     * Generate architecture content.
     */
    private function generateArchitectureContent(array $module): string
    {
        $moduleName = $module['name'];

        $content = "# {$moduleName} Module Architecture\n\n";

        $content .= "## Domain-Driven Design Implementation\n\n";
        $content .= "This module implements DDD tactical patterns:\n\n";

        $content .= "### Aggregates\n\n";
        $content .= "Aggregates enforce business rules and maintain consistency boundaries.\n\n";

        $content .= "### Value Objects\n\n";
        $content .= "Immutable objects that describe characteristics of domain entities.\n\n";

        $content .= "### Domain Events\n\n";
        $content .= "Events that represent significant business occurrences.\n\n";

        $content .= "## CQRS Implementation\n\n";
        $content .= "Commands and queries are separated for optimal read/write operations.\n\n";

        $content .= "### Commands\n\n";
        $content .= "Commands represent intentions to change system state.\n\n";

        $content .= "### Queries\n\n";
        $content .= "Queries retrieve data without side effects.\n\n";

        $content .= "## Event Sourcing\n\n";
        $content .= "Events are stored and used to reconstruct aggregate state.\n\n";

        return $content;
    }

    /**
     * Generate API documentation.
     */
    private function generateApiDocumentation(array $module, string $outputDir, array $options): array
    {
        $files = [];

        $apiFile = "{$outputDir}/api.md";

        if (!$this->filesystem->exists($apiFile) || $options['force']) {
            $content = $this->generateApiContent($module);
            $this->filesystem->put($apiFile, $content);
            $files[] = $apiFile;
        }

        return $files;
    }

    /**
     * Generate API content.
     */
    private function generateApiContent(array $module): string
    {
        $moduleName = $module['name'];
        $moduleNameLower = strtolower($moduleName);

        $content = "# {$moduleName} API Documentation\n\n";

        $content .= "## Base URL\n\n";
        $content .= "`/api/{$moduleNameLower}`\n\n";

        $content .= "## Endpoints\n\n";

        // Standard CRUD endpoints
        $endpoints = [
            ['GET', '/', 'List all items', '200'],
            ['POST', '/', 'Create new item', '201'],
            ['GET', '/{id}', 'Get specific item', '200'],
            ['PUT', '/{id}', 'Update specific item', '200'],
            ['DELETE', '/{id}', 'Delete specific item', '204'],
        ];

        foreach ($endpoints as [$method, $path, $description, $status]) {
            $content .= "### {$method} /api/{$moduleNameLower}{$path}\n\n";
            $content .= "{$description}\n\n";
            $content .= "**Response:** {$status}\n\n";
        }

        return $content;
    }

    /**
     * Generate component documentation.
     */
    private function generateComponentDocumentation(array $module, string $outputDir, array $options): array
    {
        $files = [];

        $componentsFile = "{$outputDir}/components.md";

        if (!$this->filesystem->exists($componentsFile) || $options['force']) {
            $content = $this->generateComponentsContent($module);
            $this->filesystem->put($componentsFile, $content);
            $files[] = $componentsFile;
        }

        return $files;
    }

    /**
     * Generate components content.
     */
    private function generateComponentsContent(array $module): string
    {
        $moduleName = $module['name'];
        $components = $module['components'] ?? [];

        $content = "# {$moduleName} Components\n\n";

        $content .= "## Domain Layer\n\n";
        $content .= $this->generateLayerDocumentation('Domain', [
            'Aggregates' => $components['aggregates'] ?? [],
            'Value Objects' => $components['value_objects'] ?? [],
            'Domain Events' => $components['events'] ?? [],
            'Exceptions' => $components['exceptions'] ?? [],
        ]);

        $content .= "## Application Layer\n\n";
        $content .= $this->generateLayerDocumentation('Application', [
            'Commands' => $components['commands'] ?? [],
            'Queries' => $components['queries'] ?? [],
            'Command Handlers' => $components['handlers']['command_handlers'] ?? [],
            'Query Handlers' => $components['handlers']['query_handlers'] ?? [],
        ]);

        $content .= "## Infrastructure Layer\n\n";
        $content .= $this->generateLayerDocumentation('Infrastructure', [
            'Repository Interfaces' => $components['repositories']['interfaces'] ?? [],
            'Repository Implementations' => $components['repositories']['implementations'] ?? [],
        ]);

        $content .= "## Presentation Layer\n\n";
        $content .= $this->generateLayerDocumentation('Presentation', [
            'Controllers' => $components['controllers'] ?? [],
        ]);

        return $content;
    }

    /**
     * Generate layer documentation.
     */
    private function generateLayerDocumentation(string $layerName, array $componentTypes): string
    {
        $content = "";

        foreach ($componentTypes as $typeName => $components) {
            if (!empty($components)) {
                $content .= "### {$typeName}\n\n";
                foreach ($components as $component) {
                    $name = is_array($component) ? $component['name'] : $component;
                    $content .= "- **{$name}**\n";
                }
                $content .= "\n";
            }
        }

        return $content;
    }

    /**
     * Generate test documentation.
     */
    private function generateTestDocumentation(array $module, string $outputDir, array $options): array
    {
        $files = [];

        $testsFile = "{$outputDir}/tests.md";

        if (!$this->filesystem->exists($testsFile) || $options['force']) {
            $content = $this->generateTestsContent($module);
            $this->filesystem->put($testsFile, $content);
            $files[] = $testsFile;
        }

        return $files;
    }

    /**
     * Generate tests content.
     */
    private function generateTestsContent(array $module): string
    {
        $moduleName = $module['name'];
        $tests = $module['tests'] ?? [];

        $content = "# {$moduleName} Testing\n\n";

        $content .= "## Test Structure\n\n";

        $testTypes = [
            'unit' => 'Unit Tests',
            'feature' => 'Feature Tests',
            'integration' => 'Integration Tests',
        ];

        foreach ($testTypes as $type => $title) {
            $testFiles = $tests[$type] ?? [];
            if (!empty($testFiles)) {
                $content .= "### {$title}\n\n";
                foreach ($testFiles as $test) {
                    $name = is_array($test) ? $test['name'] : basename($test, '.php');
                    $content .= "- `{$name}`\n";
                }
                $content .= "\n";
            }
        }

        $content .= "## Running Tests\n\n";
        $content .= "```bash\n";
        $content .= "# Run all module tests\n";
        $content .= "php artisan test Modules/{$moduleName}/Tests\n\n";
        $content .= "# Run specific test type\n";
        $content .= "php artisan test Modules/{$moduleName}/Tests/Unit\n";
        $content .= "php artisan test Modules/{$moduleName}/Tests/Feature\n";
        $content .= "```\n\n";

        return $content;
    }

    /**
     * Generate diagrams (placeholder implementation).
     */
    private function generateDiagrams(array $module, string $outputDir, array $options): array
    {
        $files = [];

        // Generate PlantUML diagram
        $diagramFile = "{$outputDir}/architecture.puml";

        if (!$this->filesystem->exists($diagramFile) || $options['force']) {
            $content = $this->generatePlantUMLDiagram($module);
            $this->filesystem->put($diagramFile, $content);
            $files[] = $diagramFile;
        }

        return $files;
    }

    /**
     * Generate PlantUML diagram.
     */
    private function generatePlantUMLDiagram(array $module): string
    {
        $moduleName = $module['name'];

        $content = "@startuml {$moduleName} Architecture\n\n";

        $content .= "package \"Domain Layer\" {\n";
        $content .= "  class Aggregate\n";
        $content .= "  class ValueObject\n";
        $content .= "  class DomainEvent\n";
        $content .= "}\n\n";

        $content .= "package \"Application Layer\" {\n";
        $content .= "  class Command\n";
        $content .= "  class CommandHandler\n";
        $content .= "  class Query\n";
        $content .= "  class QueryHandler\n";
        $content .= "}\n\n";

        $content .= "package \"Infrastructure Layer\" {\n";
        $content .= "  class Repository\n";
        $content .= "  class EventStore\n";
        $content .= "}\n\n";

        $content .= "package \"Presentation Layer\" {\n";
        $content .= "  class Controller\n";
        $content .= "  class ApiResource\n";
        $content .= "}\n\n";

        $content .= "CommandHandler --> Aggregate\n";
        $content .= "QueryHandler --> Repository\n";
        $content .= "Repository --> EventStore\n";
        $content .= "Controller --> CommandHandler\n";
        $content .= "Controller --> QueryHandler\n\n";

        $content .= "@enduml\n";

        return $content;
    }

    /**
     * Generate system overview file.
     */
    private function generateSystemOverviewFile(Collection $modules, string $outputDir, array $options): ?string
    {
        $overviewFile = "{$outputDir}/README.md";

        if ($this->filesystem->exists($overviewFile) && !$options['force']) {
            return null;
        }

        $content = $this->generateSystemOverviewContent($modules);
        $this->filesystem->put($overviewFile, $content);

        return $overviewFile;
    }

    /**
     * Generate system overview content.
     */
    private function generateSystemOverviewContent(Collection $modules): string
    {
        $content = "# System Overview\n\n";

        $content .= "## Architecture\n\n";
        $content .= "This system is built using Domain-Driven Design (DDD) principles with a modular architecture.\n\n";

        $content .= "## Modules\n\n";
        $content .= "The system consists of the following modules:\n\n";

        foreach ($modules as $module) {
            $status = $module['enabled'] ? '✅' : '❌';
            $content .= "- **{$module['name']}** {$status}\n";
        }

        $content .= "\n## Statistics\n\n";
        $stats = $this->moduleRegistry->getStatistics();
        $content .= "- Total modules: {$stats['total_modules']}\n";
        $content .= "- Enabled modules: {$stats['enabled_modules']}\n";
        $content .= "- Disabled modules: {$stats['disabled_modules']}\n\n";

        return $content;
    }

    /**
     * Generate module index.
     */
    private function generateModuleIndex(Collection $modules, string $outputDir, array $options): ?string
    {
        $indexFile = "{$outputDir}/modules.md";

        if ($this->filesystem->exists($indexFile) && !$options['force']) {
            return null;
        }

        $content = "# Module Index\n\n";

        foreach ($modules as $module) {
            $content .= "## {$module['name']}\n\n";
            $content .= "- [Documentation](./modules/{$module['name']}/README.md)\n";
            $content .= "- [Architecture](./modules/{$module['name']}/architecture.md)\n";
            $content .= "- [Components](./modules/{$module['name']}/components.md)\n\n";
        }

        $this->filesystem->put($indexFile, $content);

        return $indexFile;
    }

    /**
     * Generate dependency diagram (placeholder).
     */
    private function generateDependencyDiagram(Collection $modules, string $outputDir, array $options): ?string
    {
        $diagramFile = "{$outputDir}/dependencies.puml";

        if ($this->filesystem->exists($diagramFile) && !$options['force']) {
            return null;
        }

        $content = "@startuml Module Dependencies\n\n";

        foreach ($modules as $module) {
            $dependencies = $module['dependencies']['module_dependencies'] ?? [];
            foreach ($dependencies as $dependency) {
                $content .= "{$dependency} --> {$module['name']}\n";
            }
        }

        $content .= "\n@enduml\n";

        $this->filesystem->put($diagramFile, $content);

        return $diagramFile;
    }
}