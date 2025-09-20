<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Unit\Generators;

use LaravelModularDDD\Tests\TestCase;
use LaravelModularDDD\Generators\ModuleGenerator;
use LaravelModularDDD\Generators\AggregateGenerator;
use LaravelModularDDD\Generators\CommandGenerator;
use LaravelModularDDD\Generators\QueryGenerator;
use LaravelModularDDD\Generators\RepositoryGenerator;
use LaravelModularDDD\Generators\ServiceGenerator;
use LaravelModularDDD\Generators\Contracts\GeneratorInterface;
use LaravelModularDDD\Generators\StubProcessor;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

/**
 * Test suite for the Generator System.
 *
 * This validates that the code generation system works correctly,
 * creating properly structured DDD modules with all components.
 */
class GeneratorSystemTest extends TestCase
{
    private Filesystem $filesystem;
    private StubProcessor $stubProcessor;
    private string $testModulesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = app(Filesystem::class);
        $this->stubProcessor = new StubProcessor($this->filesystem);
        $this->testModulesPath = base_path('test_modules');

        // Configure test modules path
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
    public function it_can_generate_complete_module_structure(): void
    {
        // Arrange
        $generator = new ModuleGenerator($this->filesystem, $this->stubProcessor);
        $moduleName = 'TestOrder';

        // Act
        $createdFiles = $generator->generate($moduleName, '', [
            'aggregate' => 'Order',
            'with-api' => true,
            'with-web' => false,
        ]);

        // Assert
        $this->assertNotEmpty($createdFiles);
        $this->assertModuleStructureExists($moduleName);

        // Verify manifest was created correctly
        $manifestPath = $this->testModulesPath . "/TestOrder/manifest.json";
        $this->assertFileExists($manifestPath);

        $manifest = json_decode($this->filesystem->get($manifestPath), true);
        $this->assertEquals('TestOrder', $manifest['name']);
        $this->assertEquals('Order', $manifest['provides']['aggregates'][0]);
        $this->assertTrue($manifest['routes']['api']);
        $this->assertFalse($manifest['routes']['web']);
    }

    /** @test */
    public function it_validates_module_name_correctly(): void
    {
        // Arrange
        $generator = new ModuleGenerator($this->filesystem, $this->stubProcessor);

        // Act & Assert - Valid names should pass
        $this->assertTrue($generator->validate('ValidModule', ''));
        $this->assertTrue($generator->validate('Another123', ''));

        // Invalid names should fail
        $this->assertFalse($generator->validate('invalid-module', ''));
        $this->assertFalse($generator->validate('123invalid', ''));
        $this->assertFalse($generator->validate('', ''));
        $this->assertFalse($generator->validate('invalid_module', ''));
    }

    /** @test */
    public function it_throws_exception_for_existing_module_without_force(): void
    {
        // Arrange
        $generator = new ModuleGenerator($this->filesystem, $this->stubProcessor);
        $moduleName = 'ExistingModule';

        // Create existing module
        $modulePath = $this->testModulesPath . '/' . $moduleName;
        $this->filesystem->ensureDirectoryExists($modulePath);

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Module ExistingModule already exists');

        $generator->validate($moduleName, '');
    }

    /** @test */
    public function it_overwrites_existing_module_with_force_option(): void
    {
        // Arrange
        $generator = new ModuleGenerator($this->filesystem, $this->stubProcessor);
        $moduleName = 'ExistingModule';

        // Create existing module
        $modulePath = $this->testModulesPath . '/' . $moduleName;
        $this->filesystem->ensureDirectoryExists($modulePath);
        $this->filesystem->put($modulePath . '/existing.txt', 'existing content');

        // Act
        $this->assertTrue($generator->validate($moduleName, '', ['force' => true]));
        $createdFiles = $generator->generate($moduleName, '', ['force' => true]);

        // Assert
        $this->assertNotEmpty($createdFiles);
        $this->assertModuleStructureExists($moduleName);
    }

    /** @test */
    public function it_generates_aggregate_with_proper_structure(): void
    {
        // Arrange
        $generator = new AggregateGenerator($this->filesystem, $this->stubProcessor);
        $moduleName = 'TestProduct';
        $aggregateName = 'Product';

        $this->createTestModuleStructure($moduleName);

        // Act
        $createdFiles = $generator->generate($moduleName, $aggregateName);

        // Assert
        $this->assertNotEmpty($createdFiles);

        $aggregatePath = $this->testModulesPath . "/TestProduct/Domain/Models/Product.php";
        $this->assertFileExists($aggregatePath);

        $aggregateContent = $this->filesystem->get($aggregatePath);
        $this->assertStringContainsString('class Product', $aggregateContent);
        $this->assertStringContainsString('namespace Modules\\TestProduct\\Domain\\Models', $aggregateContent);
    }

    /** @test */
    public function it_generates_commands_with_handlers(): void
    {
        // Arrange
        $generator = new CommandGenerator($this->filesystem, $this->stubProcessor);
        $moduleName = 'TestOrder';
        $commandName = 'CreateOrder';

        $this->createTestModuleStructure($moduleName);

        // Act
        $createdFiles = $generator->generate($moduleName, $commandName, [
            'aggregate' => 'Order',
            'action' => 'create',
        ]);

        // Assert
        $this->assertNotEmpty($createdFiles);

        // Verify command file
        $commandPath = $this->testModulesPath . "/TestOrder/Application/Commands/CreateOrder.php";
        $this->assertFileExists($commandPath);

        $commandContent = $this->filesystem->get($commandPath);
        $this->assertStringContainsString('class CreateOrder', $commandContent);
        $this->assertStringContainsString('implements CommandInterface', $commandContent);

        // Verify handler file
        $handlerPath = $this->testModulesPath . "/TestOrder/Application/Commands/CreateOrderHandler.php";
        $this->assertFileExists($handlerPath);

        $handlerContent = $this->filesystem->get($handlerPath);
        $this->assertStringContainsString('class CreateOrderHandler', $handlerContent);
        $this->assertStringContainsString('implements CommandHandlerInterface', $handlerContent);
    }

    /** @test */
    public function it_generates_queries_with_handlers(): void
    {
        // Arrange
        $generator = new QueryGenerator($this->filesystem, $this->stubProcessor);
        $moduleName = 'TestUser';
        $queryName = 'GetUser';

        $this->createTestModuleStructure($moduleName);

        // Act
        $createdFiles = $generator->generate($moduleName, $queryName, [
            'aggregate' => 'User',
            'action' => 'get',
        ]);

        // Assert
        $this->assertNotEmpty($createdFiles);

        // Verify query file
        $queryPath = $this->testModulesPath . "/TestUser/Application/Queries/GetUser.php";
        $this->assertFileExists($queryPath);

        $queryContent = $this->filesystem->get($queryPath);
        $this->assertStringContainsString('class GetUser', $queryContent);
        $this->assertStringContainsString('implements QueryInterface', $queryContent);

        // Verify handler file
        $handlerPath = $this->testModulesPath . "/TestUser/Application/Queries/GetUserHandler.php";
        $this->assertFileExists($handlerPath);

        $handlerContent = $this->filesystem->get($handlerPath);
        $this->assertStringContainsString('class GetUserHandler', $handlerContent);
        $this->assertStringContainsString('implements QueryHandlerInterface', $handlerContent);
    }

    /** @test */
    public function it_generates_repository_interface_and_implementation(): void
    {
        // Arrange
        $generator = new RepositoryGenerator($this->filesystem, $this->stubProcessor);
        $moduleName = 'TestCustomer';
        $aggregateName = 'Customer';

        $this->createTestModuleStructure($moduleName);

        // Act
        $createdFiles = $generator->generate($moduleName, $aggregateName);

        // Assert
        $this->assertNotEmpty($createdFiles);

        // Verify interface
        $interfacePath = $this->testModulesPath . "/TestCustomer/Domain/Repositories/CustomerRepositoryInterface.php";
        $this->assertFileExists($interfacePath);

        $interfaceContent = $this->filesystem->get($interfacePath);
        $this->assertStringContainsString('interface CustomerRepositoryInterface', $interfaceContent);

        // Verify implementation
        $implementationPath = $this->testModulesPath . "/TestCustomer/Infrastructure/Persistence/Eloquent/Repositories/CustomerRepository.php";
        $this->assertFileExists($implementationPath);

        $implementationContent = $this->filesystem->get($implementationPath);
        $this->assertStringContainsString('class CustomerRepository', $implementationContent);
        $this->assertStringContainsString('implements CustomerRepositoryInterface', $implementationContent);
    }

    /** @test */
    public function it_generates_service_with_dependencies(): void
    {
        // Arrange
        $generator = new ServiceGenerator($this->filesystem, $this->stubProcessor);
        $moduleName = 'TestPayment';
        $serviceName = 'PaymentService';

        $this->createTestModuleStructure($moduleName);

        // Act
        $createdFiles = $generator->generate($moduleName, $serviceName, [
            'aggregate' => 'Payment',
            'dependencies' => ['PaymentRepository', 'EventDispatcher'],
        ]);

        // Assert
        $this->assertNotEmpty($createdFiles);

        $servicePath = $this->testModulesPath . "/TestPayment/Domain/Services/PaymentService.php";
        $this->assertFileExists($servicePath);

        $serviceContent = $this->filesystem->get($servicePath);
        $this->assertStringContainsString('class PaymentService', $serviceContent);
        $this->assertStringContainsString('PaymentRepository', $serviceContent);
        $this->assertStringContainsString('EventDispatcher', $serviceContent);
    }

    /** @test */
    public function it_creates_proper_directory_structure(): void
    {
        // Arrange
        $generator = new ModuleGenerator($this->filesystem, $this->stubProcessor);
        $moduleName = 'TestStructure';

        // Act
        $generator->generate($moduleName, '');

        // Assert
        $modulePath = $this->testModulesPath . '/' . $moduleName;

        $expectedDirectories = [
            'Domain/Models',
            'Domain/Entities',
            'Domain/ValueObjects',
            'Domain/Events',
            'Domain/Services',
            'Domain/Repositories',
            'Application/Commands',
            'Application/Queries',
            'Application/DTOs',
            'Infrastructure/Persistence/Eloquent/Models',
            'Infrastructure/Persistence/Eloquent/Repositories',
            'Infrastructure/ReadModels',
            'Infrastructure/Projections',
            'Presentation/Http/Controllers',
            'Presentation/Http/Requests',
            'Database/Migrations',
            'Database/Seeders',
            'Database/Factories',
            'Tests/Unit/Domain',
            'Tests/Feature/Application',
            'Tests/Integration/Infrastructure',
            'Config',
            'Providers',
        ];

        foreach ($expectedDirectories as $directory) {
            $this->assertDirectoryExists($modulePath . '/' . $directory);
        }
    }

    /** @test */
    public function it_generates_tests_when_not_skipped(): void
    {
        // Arrange
        $generator = new ModuleGenerator($this->filesystem, $this->stubProcessor);
        $moduleName = 'TestWithTests';

        // Act
        $createdFiles = $generator->generate($moduleName, '', [
            'aggregate' => 'TestAggregate',
            'skip-tests' => false,
        ]);

        // Assert
        $modulePath = $this->testModulesPath . '/' . $moduleName;

        // Verify unit test was created
        $unitTestPath = $modulePath . '/Tests/Unit/Domain/TestAggregateTest.php';
        $this->assertFileExists($unitTestPath);

        // Verify feature tests were created
        $this->assertFileExists($modulePath . '/Tests/Feature/Application/CreateTestAggregateCommandTest.php');
        $this->assertFileExists($modulePath . '/Tests/Feature/Application/UpdateTestAggregateCommandTest.php');
        $this->assertFileExists($modulePath . '/Tests/Feature/Application/DeleteTestAggregateCommandTest.php');

        // Verify integration test was created
        $this->assertFileExists($modulePath . '/Tests/Integration/Infrastructure/TestAggregateRepositoryTest.php');
    }

    /** @test */
    public function it_skips_tests_when_requested(): void
    {
        // Arrange
        $generator = new ModuleGenerator($this->filesystem, $this->stubProcessor);
        $moduleName = 'TestWithoutTests';

        // Act
        $createdFiles = $generator->generate($moduleName, '', [
            'aggregate' => 'TestAggregate',
            'skip-tests' => true,
        ]);

        // Assert
        $modulePath = $this->testModulesPath . '/' . $moduleName;

        // Verify no test files were created
        $this->assertDirectoryDoesNotExist($modulePath . '/Tests');
    }

    /** @test */
    public function it_generates_api_routes_when_requested(): void
    {
        // Arrange
        $generator = new ModuleGenerator($this->filesystem, $this->stubProcessor);
        $moduleName = 'TestAPI';

        // Act
        $generator->generate($moduleName, '', [
            'aggregate' => 'APIResource',
            'with-api' => true,
            'with-web' => false,
        ]);

        // Assert
        $apiRoutesPath = $this->testModulesPath . '/TestAPI/Routes/api.php';
        $this->assertFileExists($apiRoutesPath);

        $routesContent = $this->filesystem->get($apiRoutesPath);
        $this->assertStringContainsString('Route::apiResource', $routesContent);

        // Web routes should not exist
        $webRoutesPath = $this->testModulesPath . '/TestAPI/Routes/web.php';
        $this->assertFileDoesNotExist($webRoutesPath);
    }

    /** @test */
    public function it_generates_web_routes_when_requested(): void
    {
        // Arrange
        $generator = new ModuleGenerator($this->filesystem, $this->stubProcessor);
        $moduleName = 'TestWeb';

        // Act
        $generator->generate($moduleName, '', [
            'aggregate' => 'WebResource',
            'with-api' => false,
            'with-web' => true,
        ]);

        // Assert
        $webRoutesPath = $this->testModulesPath . '/TestWeb/Routes/web.php';
        $this->assertFileExists($webRoutesPath);

        $routesContent = $this->filesystem->get($webRoutesPath);
        $this->assertStringContainsString('Route::resource', $routesContent);

        // API routes should not exist
        $apiRoutesPath = $this->testModulesPath . '/TestWeb/Routes/api.php';
        $this->assertFileDoesNotExist($apiRoutesPath);
    }

    /** @test */
    public function it_generates_controller_with_api_endpoints(): void
    {
        // Arrange
        $generator = new ModuleGenerator($this->filesystem, $this->stubProcessor);
        $moduleName = 'TestController';

        // Act
        $generator->generate($moduleName, '', [
            'aggregate' => 'ControllerTest',
            'with-api' => true,
        ]);

        // Assert
        $controllerPath = $this->testModulesPath . '/TestController/Presentation/Http/Controllers/ControllerTestController.php';
        $this->assertFileExists($controllerPath);

        $controllerContent = $this->filesystem->get($controllerPath);
        $this->assertStringContainsString('class ControllerTestController', $controllerContent);
        $this->assertStringContainsString('public function index', $controllerContent);
        $this->assertStringContainsString('public function store', $controllerContent);
        $this->assertStringContainsString('public function show', $controllerContent);
        $this->assertStringContainsString('public function update', $controllerContent);
        $this->assertStringContainsString('public function destroy', $controllerContent);

        // Verify request classes were generated
        $this->assertFileExists($this->testModulesPath . '/TestController/Presentation/Http/Requests/StoreControllerTestRequest.php');
        $this->assertFileExists($this->testModulesPath . '/TestController/Presentation/Http/Requests/UpdateControllerTestRequest.php');

        // Verify resource class was generated
        $this->assertFileExists($this->testModulesPath . '/TestController/Presentation/Http/Resources/ControllerTestResource.php');
    }

    /** @test */
    public function it_generates_factories_and_seeders(): void
    {
        // Arrange
        $generator = new ModuleGenerator($this->filesystem, $this->stubProcessor);
        $moduleName = 'TestFactories';

        // Act
        $generator->generate($moduleName, '', [
            'aggregate' => 'FactoryTest',
        ]);

        // Assert
        $factoryPath = $this->testModulesPath . '/TestFactories/Database/Factories/FactoryTestFactory.php';
        $this->assertFileExists($factoryPath);

        $factoryContent = $this->filesystem->get($factoryPath);
        $this->assertStringContainsString('class FactoryTestFactory', $factoryContent);
        $this->assertStringContainsString('extends Factory', $factoryContent);

        $seederPath = $this->testModulesPath . '/TestFactories/Database/Seeders/TestFactoriesDatabaseSeeder.php';
        $this->assertFileExists($seederPath);

        $seederContent = $this->filesystem->get($seederPath);
        $this->assertStringContainsString('class TestFactoriesDatabaseSeeder', $seederContent);
        $this->assertStringContainsString('extends Seeder', $seederContent);
    }

    /** @test */
    public function it_generates_service_provider_with_proper_bindings(): void
    {
        // Arrange
        $generator = new ModuleGenerator($this->filesystem, $this->stubProcessor);
        $moduleName = 'TestProvider';

        // Act
        $generator->generate($moduleName, '', [
            'aggregate' => 'ProviderTest',
            'with-api' => true,
        ]);

        // Assert
        $providerPath = $this->testModulesPath . '/TestProvider/Providers/TestProviderServiceProvider.php';
        $this->assertFileExists($providerPath);

        $providerContent = $this->filesystem->get($providerPath);
        $this->assertStringContainsString('class TestProviderServiceProvider', $providerContent);
        $this->assertStringContainsString('extends ServiceProvider', $providerContent);
        $this->assertStringContainsString('public function register', $providerContent);
        $this->assertStringContainsString('public function boot', $providerContent);
    }

    /** @test */
    public function it_handles_generator_performance_efficiently(): void
    {
        // Arrange
        $generator = new ModuleGenerator($this->filesystem, $this->stubProcessor);
        $moduleName = 'PerformanceTest';

        // Act & Assert
        $this->assertExecutionTimeWithinLimits(function () use ($generator, $moduleName) {
            $createdFiles = $generator->generate($moduleName, '', [
                'aggregate' => 'LargeAggregate',
                'with-api' => true,
                'with-web' => true,
            ]);

            $this->assertGreaterThan(30, count($createdFiles)); // Should create many files
        }, 5000); // Should complete within 5 seconds

        $this->assertMemoryUsageWithinLimits(64); // Should not exceed 64MB
    }

    /** @test */
    public function it_validates_generator_supported_options(): void
    {
        // Arrange
        $generators = [
            new ModuleGenerator($this->filesystem, $this->stubProcessor),
            new AggregateGenerator($this->filesystem, $this->stubProcessor),
            new CommandGenerator($this->filesystem, $this->stubProcessor),
            new QueryGenerator($this->filesystem, $this->stubProcessor),
            new RepositoryGenerator($this->filesystem, $this->stubProcessor),
            new ServiceGenerator($this->filesystem, $this->stubProcessor),
        ];

        // Act & Assert
        foreach ($generators as $generator) {
            $this->assertInstanceOf(GeneratorInterface::class, $generator);
            $this->assertIsString($generator->getName());
            $this->assertIsArray($generator->getSupportedOptions());

            $supportedOptions = $generator->getSupportedOptions();
            foreach ($supportedOptions as $option => $description) {
                $this->assertIsString($option);
                $this->assertIsString($description);
            }
        }
    }

    /** @test */
    public function it_handles_multiple_module_generation_concurrently(): void
    {
        // Arrange
        $generator = new ModuleGenerator($this->filesystem, $this->stubProcessor);
        $moduleNames = ['ConcurrentA', 'ConcurrentB', 'ConcurrentC'];

        // Act
        $allCreatedFiles = [];
        foreach ($moduleNames as $moduleName) {
            $createdFiles = $generator->generate($moduleName, '', [
                'aggregate' => $moduleName . 'Aggregate',
            ]);
            $allCreatedFiles = array_merge($allCreatedFiles, $createdFiles);
        }

        // Assert
        $this->assertGreaterThan(60, count($allCreatedFiles)); // Should create many files

        foreach ($moduleNames as $moduleName) {
            $this->assertModuleStructureExists($moduleName);
        }
    }

    /** @test */
    public function it_generates_read_models_and_projections(): void
    {
        // Arrange
        $generator = new ModuleGenerator($this->filesystem, $this->stubProcessor);
        $moduleName = 'TestReadModel';

        // Act
        $generator->generate($moduleName, '', [
            'aggregate' => 'ReadModelTest',
        ]);

        // Assert
        $readModelPath = $this->testModulesPath . '/TestReadModel/Infrastructure/ReadModels/ReadModelTestReadModel.php';
        $this->assertFileExists($readModelPath);

        $readModelContent = $this->filesystem->get($readModelPath);
        $this->assertStringContainsString('class ReadModelTestReadModel', $readModelContent);

        $projectorPath = $this->testModulesPath . '/TestReadModel/Infrastructure/Projections/ReadModelTestProjector.php';
        $this->assertFileExists($projectorPath);

        $projectorContent = $this->filesystem->get($projectorPath);
        $this->assertStringContainsString('class ReadModelTestProjector', $projectorContent);
    }

    /** @test */
    public function it_generates_value_objects(): void
    {
        // Arrange
        $generator = new ModuleGenerator($this->filesystem, $this->stubProcessor);
        $moduleName = 'TestValueObject';

        // Act
        $generator->generate($moduleName, '', [
            'aggregate' => 'ValueObjectTest',
        ]);

        // Assert
        $valueObjectPath = $this->testModulesPath . '/TestValueObject/Domain/ValueObjects/ValueObjectTestId.php';
        $this->assertFileExists($valueObjectPath);

        $valueObjectContent = $this->filesystem->get($valueObjectPath);
        $this->assertStringContainsString('class ValueObjectTestId', $valueObjectContent);
        $this->assertStringContainsString('implements AggregateIdInterface', $valueObjectContent);
    }

    /** @test */
    public function it_generates_domain_events(): void
    {
        // Arrange
        $generator = new ModuleGenerator($this->filesystem, $this->stubProcessor);
        $moduleName = 'TestEvents';

        // Act
        $generator->generate($moduleName, '', [
            'aggregate' => 'EventTest',
        ]);

        // Assert
        $events = ['EventTestCreated', 'EventTestUpdated', 'EventTestDeleted'];

        foreach ($events as $eventName) {
            $eventPath = $this->testModulesPath . "/TestEvents/Domain/Events/{$eventName}.php";
            $this->assertFileExists($eventPath);

            $eventContent = $this->filesystem->get($eventPath);
            $this->assertStringContainsString("class {$eventName}", $eventContent);
            $this->assertStringContainsString('implements DomainEventInterface', $eventContent);
        }
    }

    private function assertModuleStructureExists(string $moduleName): void
    {
        $modulePath = $this->testModulesPath . '/' . $moduleName;
        $this->assertDirectoryExists($modulePath);

        // Check essential files exist
        $this->assertFileExists($modulePath . '/manifest.json');
        $this->assertFileExists($modulePath . '/Config/config.php');
        $this->assertFileExists($modulePath . "/Providers/{$moduleName}ServiceProvider.php");
    }

    private function createTestModuleStructure(string $moduleName): void
    {
        $modulePath = $this->testModulesPath . '/' . $moduleName;

        $directories = [
            'Domain/Models',
            'Domain/ValueObjects',
            'Domain/Events',
            'Domain/Services',
            'Domain/Repositories',
            'Application/Commands',
            'Application/Queries',
            'Infrastructure/Persistence/Eloquent/Repositories',
        ];

        foreach ($directories as $directory) {
            $this->filesystem->ensureDirectoryExists($modulePath . '/' . $directory);
        }
    }

    private function cleanupTestModules(): void
    {
        if ($this->filesystem->exists($this->testModulesPath)) {
            $this->filesystem->deleteDirectory($this->testModulesPath);
        }
    }
}