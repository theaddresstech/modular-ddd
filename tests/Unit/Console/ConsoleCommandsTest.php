<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Unit\Console;

use LaravelModularDDD\Tests\TestCase;
use LaravelModularDDD\Console\Commands\ModuleMakeCommand;
use LaravelModularDDD\Console\Commands\ModuleListCommand;
use LaravelModularDDD\Console\Commands\ModuleEnableCommand;
use LaravelModularDDD\Console\Commands\ModuleDisableCommand;
use LaravelModularDDD\Console\Commands\ModuleInfoCommand;
use LaravelModularDDD\Console\Commands\ModuleHealthCommand;
use LaravelModularDDD\Console\Commands\ModuleMigrateCommand;
use LaravelModularDDD\Console\Commands\ModuleMigrateStatusCommand;
use LaravelModularDDD\Console\Commands\ModuleTestCommand;
use LaravelModularDDD\Console\Commands\AggregateGenerateCommand;
use LaravelModularDDD\Console\Commands\CommandGenerateCommand;
use LaravelModularDDD\Console\Commands\QueryGenerateCommand;
use LaravelModularDDD\Generators\ModuleGenerator;
use LaravelModularDDD\Generators\AggregateGenerator;
use LaravelModularDDD\Generators\CommandGenerator;
use LaravelModularDDD\Generators\QueryGenerator;
use LaravelModularDDD\Support\ModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Console Commands Test Suite
 *
 * This validates that all console commands work correctly,
 * handle inputs properly, and provide appropriate outputs.
 */
class ConsoleCommandsTest extends TestCase
{
    private string $testModulesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testModulesPath = base_path('test_console_modules');
        config(['modular-ddd.modules_path' => $this->testModulesPath]);

        // Ensure clean test environment
        $this->cleanupTestModules();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestModules();
        parent::tearDown();
    }

    /** @test */
    public function module_make_command_generates_complete_module(): void
    {
        // Act
        $result = Artisan::call('module:make', [
            'name' => 'TestModule',
            '--aggregate' => 'TestAggregate',
            '--no-tests' => false,
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        // Verify module directory structure exists
        $modulePath = $this->testModulesPath . '/TestModule';
        $this->assertDirectoryExists($modulePath);
        $this->assertFileExists($modulePath . '/manifest.json');
        $this->assertFileExists($modulePath . '/Providers/TestModuleServiceProvider.php');

        // Verify output contains success message
        $output = Artisan::output();
        $this->assertStringContainsString('Module generation completed successfully', $output);
        $this->assertStringContainsString('TestModule', $output);
    }

    /** @test */
    public function module_make_command_respects_dry_run_option(): void
    {
        // Act
        $result = Artisan::call('module:make', [
            'name' => 'DryRunModule',
            '--dry-run' => true,
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        // Verify no files were actually created
        $modulePath = $this->testModulesPath . '/DryRunModule';
        $this->assertDirectoryDoesNotExist($modulePath);

        // Verify dry run output
        $output = Artisan::output();
        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString('No files will be created', $output);
    }

    /** @test */
    public function module_make_command_validates_module_name(): void
    {
        // Act
        $result = Artisan::call('module:make', [
            'name' => 'invalid-module-name',
        ]);

        // Assert
        $this->assertEquals(Command::FAILURE, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('Module generation failed', $output);
        $this->assertStringContainsString('uppercase letter', $output);
    }

    /** @test */
    public function module_make_command_handles_existing_module_without_force(): void
    {
        // Arrange - Create existing module
        $modulePath = $this->testModulesPath . '/ExistingModule';
        File::ensureDirectoryExists($modulePath);

        // Act - Mock user input to decline overwrite
        $this->expectsConfirmation('Module ExistingModule already exists. Do you want to continue', 'no');

        $result = Artisan::call('module:make', [
            'name' => 'ExistingModule',
        ]);

        // Assert
        $this->assertEquals(Command::FAILURE, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('Module generation cancelled', $output);
    }

    /** @test */
    public function module_make_command_overwrites_with_force_option(): void
    {
        // Arrange - Create existing module
        $modulePath = $this->testModulesPath . '/ForceModule';
        File::ensureDirectoryExists($modulePath);
        File::put($modulePath . '/existing.txt', 'existing content');

        // Act
        $result = Artisan::call('module:make', [
            'name' => 'ForceModule',
            '--force' => true,
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        // Verify module was recreated
        $this->assertFileExists($modulePath . '/manifest.json');
        $this->assertFileExists($modulePath . '/Providers/ForceModuleServiceProvider.php');
    }

    /** @test */
    public function module_list_command_displays_modules_table(): void
    {
        // Arrange - Create test modules
        $this->createTestModuleForConsole('ModuleA');
        $this->createTestModuleForConsole('ModuleB');

        // Act
        $result = Artisan::call('module:list');

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('ModuleA', $output);
        $this->assertStringContainsString('ModuleB', $output);
        $this->assertStringContainsString('Total modules:', $output);
    }

    /** @test */
    public function module_list_command_shows_detailed_view(): void
    {
        // Arrange
        $this->createTestModuleForConsole('DetailedModule');

        // Act
        $result = Artisan::call('module:list', [
            '--detailed' => true,
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('DetailedModule', $output);
        $this->assertStringContainsString('Path:', $output);
        $this->assertStringContainsString('Components:', $output);
    }

    /** @test */
    public function module_list_command_outputs_json_format(): void
    {
        // Arrange
        $this->createTestModuleForConsole('JsonModule');

        // Act
        $result = Artisan::call('module:list', [
            '--json' => true,
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        $output = Artisan::output();
        $this->assertJson($output);

        $data = json_decode($output, true);
        $this->assertArrayHasKey('modules', $data);
        $this->assertArrayHasKey('statistics', $data);
        $this->assertArrayHasKey('generated_at', $data);
    }

    /** @test */
    public function module_list_command_filters_enabled_modules(): void
    {
        // Arrange
        $this->createTestModuleForConsole('EnabledModule', true);
        $this->createTestModuleForConsole('DisabledModule', false);

        // Act
        $result = Artisan::call('module:list', [
            '--enabled' => true,
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('EnabledModule', $output);
        $this->assertStringNotContainsString('DisabledModule', $output);
    }

    /** @test */
    public function module_list_command_shows_statistics(): void
    {
        // Arrange
        $this->createTestModuleForConsole('StatsModule1');
        $this->createTestModuleForConsole('StatsModule2');

        // Act
        $result = Artisan::call('module:list', [
            '--stats' => true,
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('Module Statistics', $output);
        $this->assertStringContainsString('Modules:', $output);
        $this->assertStringContainsString('Components:', $output);
        $this->assertStringContainsString('Health Status:', $output);
    }

    /** @test */
    public function module_enable_command_enables_module(): void
    {
        // Arrange
        $this->createTestModuleForConsole('DisabledModule', false);

        // Act
        $result = Artisan::call('module:enable', [
            'module' => 'DisabledModule',
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('enabled successfully', $output);
    }

    /** @test */
    public function module_disable_command_disables_module(): void
    {
        // Arrange
        $this->createTestModuleForConsole('EnabledModule', true);

        // Act
        $result = Artisan::call('module:disable', [
            'module' => 'EnabledModule',
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('disabled successfully', $output);
    }

    /** @test */
    public function module_info_command_displays_module_details(): void
    {
        // Arrange
        $this->createTestModuleForConsole('InfoModule');

        // Act
        $result = Artisan::call('module:info', [
            'module' => 'InfoModule',
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('InfoModule', $output);
        $this->assertStringContainsString('Module Information', $output);
    }

    /** @test */
    public function module_health_command_shows_health_status(): void
    {
        // Arrange
        $this->createTestModuleForConsole('HealthModule');

        // Act
        $result = Artisan::call('module:health');

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('Module Health Report', $output);
        $this->assertStringContainsString('HealthModule', $output);
    }

    /** @test */
    public function module_health_command_shows_specific_module(): void
    {
        // Arrange
        $this->createTestModuleForConsole('SpecificModule');

        // Act
        $result = Artisan::call('module:health', [
            'module' => 'SpecificModule',
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('SpecificModule', $output);
        $this->assertStringContainsString('Health Status', $output);
    }

    /** @test */
    public function aggregate_generate_command_creates_aggregate(): void
    {
        // Arrange
        $this->createTestModuleForConsole('AggregateModule');

        // Act
        $result = Artisan::call('aggregate:generate', [
            'module' => 'AggregateModule',
            'name' => 'TestAggregate',
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('Aggregate generated successfully', $output);

        // Verify files were created
        $aggregatePath = $this->testModulesPath . '/AggregateModule/Domain/Models/TestAggregate.php';
        $this->assertFileExists($aggregatePath);
    }

    /** @test */
    public function command_generate_command_creates_command_and_handler(): void
    {
        // Arrange
        $this->createTestModuleForConsole('CommandModule');

        // Act
        $result = Artisan::call('command:generate', [
            'module' => 'CommandModule',
            'name' => 'CreateTestCommand',
            '--aggregate' => 'TestAggregate',
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('Command generated successfully', $output);

        // Verify files were created
        $commandPath = $this->testModulesPath . '/CommandModule/Application/Commands/CreateTestCommand.php';
        $handlerPath = $this->testModulesPath . '/CommandModule/Application/Commands/CreateTestCommandHandler.php';

        $this->assertFileExists($commandPath);
        $this->assertFileExists($handlerPath);
    }

    /** @test */
    public function query_generate_command_creates_query_and_handler(): void
    {
        // Arrange
        $this->createTestModuleForConsole('QueryModule');

        // Act
        $result = Artisan::call('query:generate', [
            'module' => 'QueryModule',
            'name' => 'GetTestQuery',
            '--aggregate' => 'TestAggregate',
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('Query generated successfully', $output);

        // Verify files were created
        $queryPath = $this->testModulesPath . '/QueryModule/Application/Queries/GetTestQuery.php';
        $handlerPath = $this->testModulesPath . '/QueryModule/Application/Queries/GetTestQueryHandler.php';

        $this->assertFileExists($queryPath);
        $this->assertFileExists($handlerPath);
    }

    /** @test */
    public function module_migrate_command_runs_module_migrations(): void
    {
        // Arrange
        $this->createTestModuleForConsole('MigrateModule');
        $this->createTestMigration('MigrateModule');

        // Act
        $result = Artisan::call('module:migrate', [
            'module' => 'MigrateModule',
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('Migration completed', $output);
    }

    /** @test */
    public function module_migrate_status_command_shows_migration_status(): void
    {
        // Arrange
        $this->createTestModuleForConsole('StatusModule');
        $this->createTestMigration('StatusModule');

        // Act
        $result = Artisan::call('module:migrate:status', [
            'module' => 'StatusModule',
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('Migration Status', $output);
    }

    /** @test */
    public function module_test_command_runs_module_tests(): void
    {
        // Arrange
        $this->createTestModuleForConsole('TestModule');
        $this->createTestFile('TestModule');

        // Act
        $result = Artisan::call('module:test', [
            'module' => 'TestModule',
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('Tests completed', $output);
    }

    /** @test */
    public function module_test_command_runs_specific_test_type(): void
    {
        // Arrange
        $this->createTestModuleForConsole('UnitTestModule');
        $this->createTestFile('UnitTestModule', 'unit');

        // Act
        $result = Artisan::call('module:test', [
            'module' => 'UnitTestModule',
            '--type' => 'unit',
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('Unit tests completed', $output);
    }

    /** @test */
    public function commands_handle_nonexistent_module_gracefully(): void
    {
        // Act
        $result = Artisan::call('module:info', [
            'module' => 'NonexistentModule',
        ]);

        // Assert
        $this->assertEquals(Command::FAILURE, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('Module not found', $output);
    }

    /** @test */
    public function commands_validate_module_names(): void
    {
        // Act - Test invalid module name
        $result = Artisan::call('aggregate:generate', [
            'module' => 'invalid-module',
            'name' => 'TestAggregate',
        ]);

        // Assert
        $this->assertEquals(Command::FAILURE, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('Invalid module name', $output);
    }

    /** @test */
    public function commands_support_verbose_output(): void
    {
        // Arrange
        $this->createTestModuleForConsole('VerboseModule');

        // Act
        $result = Artisan::call('module:info', [
            'module' => 'VerboseModule',
            '--verbose' => true,
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        $output = Artisan::output();
        $this->assertStringContainsString('VerboseModule', $output);
        // Verbose output should contain additional details
    }

    /** @test */
    public function commands_handle_interactive_confirmation(): void
    {
        // Arrange
        $this->createTestModuleForConsole('InteractiveModule');

        // Mock user confirmation
        $this->expectsConfirmation('Are you sure you want to disable this module?', 'yes');

        // Act
        $result = Artisan::call('module:disable', [
            'module' => 'InteractiveModule',
            '--interactive' => true,
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);
    }

    /** @test */
    public function commands_support_quiet_mode(): void
    {
        // Arrange
        $this->createTestModuleForConsole('QuietModule');

        // Act
        $result = Artisan::call('module:info', [
            'module' => 'QuietModule',
            '--quiet' => true,
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $result);

        // Quiet mode should produce minimal output
        $output = Artisan::output();
        $this->assertStringNotContainsString('Module Information', $output);
    }

    /** @test */
    public function commands_perform_efficiently_with_many_modules(): void
    {
        // Arrange - Create many modules
        for ($i = 1; $i <= 20; $i++) {
            $this->createTestModuleForConsole("BulkModule{$i}");
        }

        // Act & Assert
        $this->assertExecutionTimeWithinLimits(function () {
            $result = Artisan::call('module:list');
            $this->assertEquals(Command::SUCCESS, $result);

            $output = Artisan::output();
            $this->assertStringContainsString('BulkModule1', $output);
            $this->assertStringContainsString('BulkModule20', $output);
        }, 3000); // Should complete within 3 seconds
    }

    /** @test */
    public function commands_handle_concurrent_execution(): void
    {
        // Arrange
        $this->createTestModuleForConsole('ConcurrentModule');

        // Act - Simulate concurrent command execution
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = Artisan::call('module:info', [
                'module' => 'ConcurrentModule',
            ]);
        }

        // Assert
        foreach ($results as $result) {
            $this->assertEquals(Command::SUCCESS, $result);
        }
    }

    private function createTestModuleForConsole(string $name, bool $enabled = true): void
    {
        $modulePath = $this->testModulesPath . '/' . $name;
        File::ensureDirectoryExists($modulePath);

        // Create basic module structure
        File::ensureDirectoryExists($modulePath . '/Domain/Models');
        File::ensureDirectoryExists($modulePath . '/Application/Commands');
        File::ensureDirectoryExists($modulePath . '/Application/Queries');
        File::ensureDirectoryExists($modulePath . '/Providers');

        // Create manifest.json
        $manifest = [
            'name' => $name,
            'version' => '1.0.0',
            'enabled' => $enabled,
            'service_provider' => "Modules\\{$name}\\{$name}ServiceProvider",
        ];

        File::put($modulePath . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        // Create basic service provider
        $serviceProvider = "<?php\n\nnamespace Modules\\{$name};\n\nuse Illuminate\\Support\\ServiceProvider;\n\nclass {$name}ServiceProvider extends ServiceProvider\n{\n    public function register(): void\n    {\n        //\n    }\n\n    public function boot(): void\n    {\n        //\n    }\n}";

        File::put($modulePath . "/Providers/{$name}ServiceProvider.php", $serviceProvider);
    }

    private function createTestMigration(string $moduleName): void
    {
        $migrationPath = $this->testModulesPath . '/' . $moduleName . '/Database/Migrations';
        File::ensureDirectoryExists($migrationPath);

        $migration = "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nclass CreateTestTable extends Migration\n{\n    public function up(): void\n    {\n        Schema::create('test_table', function (Blueprint \$table) {\n            \$table->id();\n            \$table->timestamps();\n        });\n    }\n\n    public function down(): void\n    {\n        Schema::dropIfExists('test_table');\n    }\n}";

        File::put($migrationPath . '/2023_01_01_000000_create_test_table.php', $migration);
    }

    private function createTestFile(string $moduleName, string $type = 'unit'): void
    {
        $testPath = $this->testModulesPath . '/' . $moduleName . '/Tests/' . ucfirst($type);
        File::ensureDirectoryExists($testPath);

        $test = "<?php\n\nnamespace Modules\\{$moduleName}\\Tests\\" . ucfirst($type) . ";\n\nuse PHPUnit\\Framework\\TestCase;\n\nclass ExampleTest extends TestCase\n{\n    public function test_example(): void\n    {\n        \$this->assertTrue(true);\n    }\n}";

        File::put($testPath . '/ExampleTest.php', $test);
    }

    private function cleanupTestModules(): void
    {
        if (File::exists($this->testModulesPath)) {
            File::deleteDirectory($this->testModulesPath);
        }
    }
}