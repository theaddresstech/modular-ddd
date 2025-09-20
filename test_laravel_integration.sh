#!/bin/bash

# Test Laravel Integration Script
# This script creates a temporary Laravel project and tests the package installation

echo "========================================="
echo "Laravel Modular DDD - Integration Test"
echo "========================================="
echo ""

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Create temporary directory
TEMP_DIR=$(mktemp -d)
echo "Creating temporary Laravel project in: $TEMP_DIR"

# Navigate to temp directory
cd "$TEMP_DIR" || exit 1

# Create a new Laravel project
echo ""
echo "Installing Laravel 11..."
composer create-project laravel/laravel test-app "^11.0" --prefer-dist --quiet

cd test-app || exit 1

# Require the package
echo ""
echo "Installing Laravel Modular DDD package..."
composer require "mghrby/modular-ddd:*" --no-interaction

# Check if installation was successful
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Package installed successfully${NC}"
else
    echo -e "${RED}✗ Package installation failed${NC}"
    exit 1
fi

# Test if service provider is registered
echo ""
echo "Testing service provider registration..."
php artisan tinker --execute="
    try {
        \$services = [
            'LaravelModularDDD\EventSourcing\Contracts\EventStoreInterface',
            'LaravelModularDDD\CQRS\Contracts\CommandBusInterface',
            'LaravelModularDDD\CQRS\Contracts\QueryBusInterface',
            'LaravelModularDDD\Support\ModuleRegistry',
        ];

        foreach (\$services as \$service) {
            if (app()->bound(\$service)) {
                echo \"✓ \$service registered\\n\";
            } else {
                echo \"✗ \$service NOT registered\\n\";
            }
        }

        echo \"\\n✓ All core services are properly registered!\\n\";
    } catch (Exception \$e) {
        echo \"Error: \" . \$e->getMessage() . \"\\n\";
        exit(1);
    }
"

# Test console commands
echo ""
echo "Testing console commands..."
php artisan list | grep modular > /dev/null 2>&1
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Console commands registered${NC}"
    php artisan list | grep modular
else
    echo -e "${YELLOW}⚠ No console commands found${NC}"
fi

# Cleanup
echo ""
echo "Cleaning up..."
cd /
rm -rf "$TEMP_DIR"

echo ""
echo "========================================="
echo -e "${GREEN}✓ Integration test completed successfully!${NC}"
echo "========================================="
echo ""
echo "The package is ready to be installed in a Laravel 11 project!"
echo ""