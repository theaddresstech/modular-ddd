<?php

declare(strict_types=1);

namespace Tests;

use Orchestra\Testbench\TestCase;
use LaravelModularDDD\ModularDddServiceProvider;
use LaravelModularDDD\Console\Commands\ModuleMigrateCommand;
use LaravelModularDDD\Console\Commands\ModuleMigrateRollbackCommand;
use LaravelModularDDD\Console\Commands\ModuleMigrateStatusCommand;
use Illuminate\Database\Migrations\Migrator;

class ServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [ModularDddServiceProvider::class];
    }

    /** @test */
    public function it_registers_the_service_provider()
    {
        $this->assertTrue(
            $this->app->providerIsLoaded(ModularDddServiceProvider::class)
        );
    }

    /** @test */
    public function it_registers_migration_repository()
    {
        $this->assertTrue($this->app->bound('migration.repository'));
    }

    /** @test */
    public function it_registers_migrator()
    {
        $this->assertTrue($this->app->bound('migrator'));
        $this->assertInstanceOf(Migrator::class, $this->app->make('migrator'));
    }

    /** @test */
    public function it_registers_module_migrate_command()
    {
        $command = $this->app->make(ModuleMigrateCommand::class);
        $this->assertInstanceOf(ModuleMigrateCommand::class, $command);
    }

    /** @test */
    public function it_registers_module_migrate_rollback_command()
    {
        $command = $this->app->make(ModuleMigrateRollbackCommand::class);
        $this->assertInstanceOf(ModuleMigrateRollbackCommand::class, $command);
    }

    /** @test */
    public function it_registers_module_migrate_status_command()
    {
        $command = $this->app->make(ModuleMigrateStatusCommand::class);
        $this->assertInstanceOf(ModuleMigrateStatusCommand::class, $command);
    }
}