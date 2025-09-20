#!/usr/bin/env php
<?php

/**
 * Verification script for Laravel Modular DDD Package
 * This script simulates package installation and checks all dependencies
 * Run: php verify_installation.php
 */

require_once __DIR__ . '/vendor/autoload.php';

echo "=====================================================\n";
echo "Laravel Modular DDD - Installation Verification\n";
echo "=====================================================\n\n";

$results = [
    'success' => [],
    'warning' => [],
    'error' => []
];

// Check PHP version
echo "Checking PHP Requirements:\n";
echo "-------------------------\n";
$phpVersion = phpversion();
if (version_compare($phpVersion, '8.1', '>=')) {
    $results['success'][] = "PHP version {$phpVersion} ✓";
    echo "✓ PHP version: {$phpVersion}\n";
} else {
    $results['error'][] = "PHP version {$phpVersion} (requires >= 8.1)";
    echo "✗ PHP version: {$phpVersion} (requires >= 8.1)\n";
}

// Check required extensions
$requiredExtensions = ['json', 'mbstring', 'pdo'];
foreach ($requiredExtensions as $ext) {
    if (extension_loaded($ext)) {
        $results['success'][] = "Extension {$ext} loaded";
        echo "✓ Extension: {$ext}\n";
    } else {
        $results['error'][] = "Extension {$ext} not loaded";
        echo "✗ Extension: {$ext} not loaded\n";
    }
}

// Check composer dependencies
echo "\nChecking Composer Dependencies:\n";
echo "-------------------------------\n";
$composerLock = json_decode(file_get_contents(__DIR__ . '/composer.lock'), true);
$installedPackages = $composerLock['packages'] ?? [];
$requiredPackages = [
    'laravel/framework' => '^11.0',
    'nesbot/carbon' => '^2.0|^3.0'
];

foreach ($requiredPackages as $package => $version) {
    $found = false;
    foreach ($installedPackages as $installed) {
        if ($installed['name'] === $package) {
            $found = true;
            echo "✓ {$package}: {$installed['version']}\n";
            $results['success'][] = "{$package} installed";
            break;
        }
    }
    if (!$found) {
        echo "✗ {$package}: Not found (requires {$version})\n";
        $results['error'][] = "{$package} not installed";
    }
}

// Check file structure
echo "\nChecking File Structure:\n";
echo "------------------------\n";
$requiredDirectories = [
    'src/Core',
    'src/CQRS',
    'src/EventSourcing',
    'src/Generators',
    'src/Modules',
    'src/Console',
    'src/Testing',
    'src/Support',
    'src/Documentation',
    'config',
    'database/migrations',
    'stubs'
];

foreach ($requiredDirectories as $dir) {
    if (is_dir(__DIR__ . '/' . $dir)) {
        echo "✓ Directory: {$dir}\n";
        $results['success'][] = "Directory {$dir} exists";
    } else {
        echo "✗ Directory: {$dir} missing\n";
        $results['error'][] = "Directory {$dir} missing";
    }
}

// Check critical files
echo "\nChecking Critical Files:\n";
echo "------------------------\n";
$criticalFiles = [
    'src/ModularDddServiceProvider.php' => 'Main Service Provider',
    'config/modular-ddd.php' => 'Configuration File',
    'composer.json' => 'Composer Configuration'
];

foreach ($criticalFiles as $file => $description) {
    if (file_exists(__DIR__ . '/' . $file)) {
        echo "✓ {$description}: {$file}\n";
        $results['success'][] = "{$description} exists";
    } else {
        echo "✗ {$description}: {$file} missing\n";
        $results['error'][] = "{$description} missing";
    }
}

// Check namespace autoloading
echo "\nChecking Namespace Autoloading:\n";
echo "-------------------------------\n";
$testClasses = [
    'LaravelModularDDD\\ModularDddServiceProvider',
    'LaravelModularDDD\\Core\\Domain\\AggregateRoot',
    'LaravelModularDDD\\CQRS\\CommandBus',
    'LaravelModularDDD\\EventSourcing\\EventStore\\MySQLEventStore',
    'LaravelModularDDD\\Generators\\ModuleGenerator',
    'LaravelModularDDD\\Support\\ModuleRegistry'
];

foreach ($testClasses as $class) {
    if (class_exists($class)) {
        echo "✓ Class autoloads: {$class}\n";
        $results['success'][] = "Class {$class} autoloads";
    } else {
        echo "✗ Class not found: {$class}\n";
        $results['error'][] = "Class {$class} not found";
    }
}

// Check console commands
echo "\nChecking Console Commands:\n";
echo "--------------------------\n";
$commands = [
    'LaravelModularDDD\\Console\\Commands\\ModuleMakeCommand',
    'LaravelModularDDD\\Console\\Commands\\AggregateGenerateCommand',
    'LaravelModularDDD\\Console\\Commands\\CommandGenerateCommand',
    'LaravelModularDDD\\Console\\Commands\\QueryGenerateCommand'
];

foreach ($commands as $command) {
    if (class_exists($command)) {
        $reflection = new ReflectionClass($command);
        if ($reflection->isSubclassOf('Illuminate\\Console\\Command')) {
            echo "✓ Command registered: " . basename(str_replace('\\', '/', $command)) . "\n";
            $results['success'][] = "Command " . basename(str_replace('\\', '/', $command)) . " valid";
        } else {
            echo "⚠ Command found but not extending Laravel Command: {$command}\n";
            $results['warning'][] = "Command {$command} not properly extending";
        }
    } else {
        echo "✗ Command not found: {$command}\n";
        $results['error'][] = "Command {$command} not found";
    }
}

// Summary
echo "\n=====================================================\n";
echo "Verification Summary:\n";
echo "-----------------------------------------------------\n";
echo "✓ Passed: " . count($results['success']) . " checks\n";

if (count($results['warning']) > 0) {
    echo "⚠ Warnings: " . count($results['warning']) . " issues\n";
    foreach ($results['warning'] as $warning) {
        echo "  - {$warning}\n";
    }
}

if (count($results['error']) > 0) {
    echo "✗ Failed: " . count($results['error']) . " checks\n";
    foreach ($results['error'] as $error) {
        echo "  - {$error}\n";
    }
} else {
    echo "\n🎉 Package is ready for installation in Laravel!\n";
}

echo "\nInstallation Instructions:\n";
echo "-------------------------\n";
echo "1. In your Laravel project, add to composer.json:\n";
echo "   \"repositories\": [\n";
echo "       {\n";
echo "           \"type\": \"path\",\n";
echo "           \"url\": \"" . __DIR__ . "\"\n";
echo "       }\n";
echo "   ]\n\n";
echo "2. Run: composer require laravel-modular-ddd/laravel-ddd-modules\n";
echo "3. Publish config: php artisan vendor:publish --tag=modular-ddd-config\n";
echo "4. Run migrations: php artisan migrate\n";
echo "=====================================================\n";

exit(count($results['error']) > 0 ? 1 : 0);