# Command Workflows and Recipes

This guide provides practical workflows and recipes for common development scenarios using the Laravel Modular DDD package's generator commands.

## Table of Contents

- [Development Workflows](#development-workflows)
- [Project Setup Recipes](#project-setup-recipes)
- [Common Development Patterns](#common-development-patterns)
- [Migration and Refactoring](#migration-and-refactoring)
- [Team Collaboration](#team-collaboration)
- [Production Deployment](#production-deployment)

## Development Workflows

### 1. New Project Setup

Complete workflow for setting up a new Laravel DDD project:

```bash
#!/bin/bash
# new-project-setup.sh

echo "🚀 Setting up new Laravel DDD project..."

# Install Laravel if not already done
# composer create-project laravel/laravel my-ddd-project
# cd my-ddd-project

# Install the DDD package
composer require your-vendor/laravel-modular-ddd

# Publish configuration
php artisan vendor:publish --provider="LaravelModularDDD\ModularDddServiceProvider"

# Create first module
php artisan module:make UserManagement --aggregate=User

# Create core domain modules
php artisan module:make SharedKernel --no-api --no-web
php artisan module:make Authentication --aggregate=User
php artisan module:make Authorization --aggregate=Role

# Set up database
php artisan migrate

# Generate comprehensive tests
php artisan module:test --type=all

echo "✅ Project setup complete!"
```

### 2. Feature Development Workflow

Systematic approach to developing new features:

```bash
#!/bin/bash
# feature-development.sh

FEATURE_NAME=$1
MODULE_NAME=$2

if [ -z "$FEATURE_NAME" ] || [ -z "$MODULE_NAME" ]; then
    echo "Usage: ./feature-development.sh <feature-name> <module-name>"
    exit 1
fi

echo "🔨 Developing feature: $FEATURE_NAME in module: $MODULE_NAME"

# Step 1: Create or update aggregate
php artisan module:aggregate $MODULE_NAME $FEATURE_NAME \
    --with-events=${FEATURE_NAME}Created,${FEATURE_NAME}Updated \
    --dry-run

read -p "Continue with aggregate generation? (y/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    php artisan module:aggregate $MODULE_NAME $FEATURE_NAME \
        --with-events=${FEATURE_NAME}Created,${FEATURE_NAME}Updated
fi

# Step 2: Generate commands
php artisan module:command $MODULE_NAME Create${FEATURE_NAME}Command \
    --handler --aggregate=$FEATURE_NAME

php artisan module:command $MODULE_NAME Update${FEATURE_NAME}Command \
    --handler --aggregate=$FEATURE_NAME

# Step 3: Generate queries
php artisan module:query $MODULE_NAME Get${FEATURE_NAME}Query \
    --handler --cache

php artisan module:query $MODULE_NAME List${FEATURE_NAME}Query \
    --handler --paginated --filtered

# Step 4: Generate repository
php artisan module:repository $MODULE_NAME $FEATURE_NAME

# Step 5: Generate tests
php artisan module:test $MODULE_NAME --aggregate=$FEATURE_NAME

# Step 6: Create migration
php artisan module:make:migration $MODULE_NAME "create_${FEATURE_NAME,,}_table" \
    --create=${FEATURE_NAME,,}s

echo "✅ Feature $FEATURE_NAME development structure complete!"
echo "📝 Next steps:"
echo "   1. Implement business logic in aggregates"
echo "   2. Fill in command/query handlers"
echo "   3. Run migrations"
echo "   4. Run tests"
```

### 3. Microservice Extraction Workflow

Extract a module into a separate microservice:

```bash
#!/bin/bash
# extract-microservice.sh

MODULE_NAME=$1
SERVICE_NAME=$2

echo "🔄 Extracting $MODULE_NAME to microservice $SERVICE_NAME"

# Step 1: Analyze module dependencies
php artisan module:info $MODULE_NAME --dependencies

# Step 2: Generate API boundaries
php artisan module:command $MODULE_NAME ExportDataCommand --handler --async
php artisan module:query $MODULE_NAME GetModuleDataQuery --handler

# Step 3: Create integration events
php artisan module:event $MODULE_NAME ${MODULE_NAME}DataExported
php artisan module:event $MODULE_NAME ${MODULE_NAME}DataImported

# Step 4: Generate service contracts
php artisan module:contract $MODULE_NAME ${MODULE_NAME}ServiceInterface

# Step 5: Create new Laravel project for microservice
mkdir ../$SERVICE_NAME
cd ../$SERVICE_NAME
composer create-project laravel/laravel .

# Step 6: Copy module to new service
cp -r ../original-project/Modules/$MODULE_NAME ./app/Domain/

# Step 7: Generate API controllers in new service
cd ../original-project
php artisan module:controller $MODULE_NAME ApiController --api --external

echo "✅ Microservice extraction complete!"
```

## Project Setup Recipes

### 1. E-Commerce Platform Setup

Complete e-commerce platform with multiple modules:

```bash
#!/bin/bash
# ecommerce-setup.sh

echo "🛒 Setting up E-Commerce platform..."

# Core business modules
php artisan module:make ProductCatalog --aggregate=Product
php artisan module:make OrderManagement --aggregate=Order
php artisan module:make CustomerManagement --aggregate=Customer
php artisan module:make InventoryManagement --aggregate=Inventory
php artisan module:make PaymentProcessing --aggregate=Payment
php artisan module:make ShippingManagement --aggregate=Shipment

# Supporting modules
php artisan module:make NotificationService --aggregate=Notification
php artisan module:make AuditLog --aggregate=AuditEntry
php artisan module:make ReportingEngine --aggregate=Report

# Generate product catalog components
php artisan module:aggregate ProductCatalog Product \
    --with-events=ProductCreated,ProductUpdated,ProductDeleted,PriceChanged \
    --with-value-objects=ProductId,Money,SKU,ProductName

php artisan module:aggregate ProductCatalog Category \
    --with-events=CategoryCreated,CategoryUpdated \
    --with-value-objects=CategoryId,CategoryName

# Generate order management components
php artisan module:aggregate OrderManagement Order \
    --with-events=OrderCreated,OrderConfirmed,OrderShipped,OrderDelivered,OrderCancelled \
    --with-value-objects=OrderId,OrderNumber,ShippingAddress

php artisan module:aggregate OrderManagement OrderItem \
    --with-events=OrderItemAdded,OrderItemRemoved \
    --with-value-objects=OrderItemId,Quantity

# Generate commands and queries for each module
for module in ProductCatalog OrderManagement CustomerManagement; do
    # Commands
    php artisan module:command $module Create${module%Management}Command --handler
    php artisan module:command $module Update${module%Management}Command --handler
    php artisan module:command $module Delete${module%Management}Command --handler

    # Queries
    php artisan module:query $module Get${module%Management}Query --handler --cache
    php artisan module:query $module List${module%Management}Query --handler --paginated --filtered
    php artisan module:query $module Search${module%Management}Query --handler --filtered
done

# Generate repositories
php artisan module:repository ProductCatalog Product --event-sourced
php artisan module:repository OrderManagement Order --event-sourced
php artisan module:repository CustomerManagement Customer --event-sourced

# Generate comprehensive tests
php artisan module:test --type=all

echo "✅ E-Commerce platform setup complete!"
```

### 2. SaaS Application Setup

Multi-tenant SaaS application structure:

```bash
#!/bin/bash
# saas-setup.sh

echo "☁️ Setting up SaaS application..."

# Tenant management
php artisan module:make TenantManagement --aggregate=Tenant
php artisan module:aggregate TenantManagement Tenant \
    --with-events=TenantCreated,TenantActivated,TenantSuspended \
    --with-value-objects=TenantId,Domain,SubscriptionPlan

# Subscription management
php artisan module:make SubscriptionManagement --aggregate=Subscription
php artisan module:aggregate SubscriptionManagement Subscription \
    --with-events=SubscriptionCreated,SubscriptionUpgraded,SubscriptionCancelled \
    --with-value-objects=SubscriptionId,PlanId,BillingCycle

# User management per tenant
php artisan module:make UserManagement --aggregate=User
php artisan module:aggregate UserManagement User \
    --with-events=UserInvited,UserActivated,UserDeactivated \
    --with-value-objects=UserId,Email,TenantId

# Billing and invoicing
php artisan module:make BillingManagement --aggregate=Invoice
php artisan module:aggregate BillingManagement Invoice \
    --with-events=InvoiceGenerated,InvoicePaid,InvoiceOverdue \
    --with-value-objects=InvoiceId,Amount,Currency

# Usage tracking
php artisan module:make UsageTracking --aggregate=UsageMetric
php artisan module:aggregate UsageTracking UsageMetric \
    --with-events=UsageRecorded,UsageLimitExceeded \
    --with-value-objects=MetricId,MetricType,Value

# Generate multi-tenant repositories
for module in TenantManagement SubscriptionManagement UserManagement; do
    php artisan module:repository $module $(echo $module | sed 's/Management//') --event-sourced --multi-tenant
done

# Generate tenant-aware queries
php artisan module:query TenantManagement GetTenantUsageQuery --handler --filtered
php artisan module:query SubscriptionManagement GetTenantSubscriptionQuery --handler --cache
php artisan module:query UserManagement ListTenantUsersQuery --handler --paginated

echo "✅ SaaS application setup complete!"
```

### 3. Financial Services Setup

Financial application with compliance and audit requirements:

```bash
#!/bin/bash
# fintech-setup.sh

echo "💰 Setting up Financial Services application..."

# Account management
php artisan module:make AccountManagement --aggregate=Account
php artisan module:aggregate AccountManagement Account \
    --with-events=AccountOpened,AccountClosed,AccountFrozen \
    --with-value-objects=AccountId,AccountNumber,Balance

# Transaction processing
php artisan module:make TransactionProcessing --aggregate=Transaction
php artisan module:aggregate TransactionProcessing Transaction \
    --with-events=TransactionInitiated,TransactionCompleted,TransactionFailed \
    --with-value-objects=TransactionId,Amount,Currency,TransactionType

# Risk management
php artisan module:make RiskManagement --aggregate=RiskAssessment
php artisan module:aggregate RiskManagement RiskAssessment \
    --with-events=RiskAssessed,RiskLevelChanged,RiskAlertTriggered \
    --with-value-objects=RiskScore,RiskLevel,AssessmentId

# Compliance and audit
php artisan module:make ComplianceManagement --aggregate=ComplianceCheck
php artisan module:aggregate ComplianceManagement ComplianceCheck \
    --with-events=ComplianceCheckPerformed,ViolationDetected \
    --with-value-objects=CheckId,ComplianceRule,ViolationType

# Generate secure commands with audit trails
php artisan module:command AccountManagement CreateAccountCommand \
    --handler --authorize --audit

php artisan module:command TransactionProcessing ProcessTransactionCommand \
    --handler --async --authorize --audit

# Generate compliance-aware queries
php artisan module:query ComplianceManagement GetComplianceReportQuery \
    --handler --filtered --secure

# Generate audit repositories
for module in AccountManagement TransactionProcessing RiskManagement; do
    php artisan module:repository $module $(echo $module | sed 's/Management//Processing//') \
        --event-sourced --audit-enabled --encryption
done

echo "✅ Financial Services application setup complete!"
```

## Common Development Patterns

### 1. CRUD Operations Generator

Generate complete CRUD operations for an aggregate:

```bash
#!/bin/bash
# generate-crud.sh

MODULE=$1
AGGREGATE=$2

if [ -z "$MODULE" ] || [ -z "$AGGREGATE" ]; then
    echo "Usage: ./generate-crud.sh <module> <aggregate>"
    exit 1
fi

echo "🔧 Generating CRUD operations for $AGGREGATE in $MODULE"

# Commands
php artisan module:command $MODULE Create${AGGREGATE}Command --handler --aggregate=$AGGREGATE
php artisan module:command $MODULE Update${AGGREGATE}Command --handler --aggregate=$AGGREGATE
php artisan module:command $MODULE Delete${AGGREGATE}Command --handler --aggregate=$AGGREGATE

# Queries
php artisan module:query $MODULE Get${AGGREGATE}Query --handler --cache
php artisan module:query $MODULE List${AGGREGATE}Query --handler --paginated --filtered
php artisan module:query $MODULE Search${AGGREGATE}Query --handler --filtered

# Repository
php artisan module:repository $MODULE $AGGREGATE

# API Controller
php artisan module:controller $MODULE ${AGGREGATE}Controller --api --resource

# Tests
php artisan module:test $MODULE --aggregate=$AGGREGATE

echo "✅ CRUD operations generated for $AGGREGATE"
```

### 2. Event-Driven Architecture Setup

Set up event-driven communication between modules:

```bash
#!/bin/bash
# setup-event-driven.sh

echo "🔄 Setting up event-driven architecture..."

# Generate domain events for each module
MODULES=("UserManagement" "OrderManagement" "ProductCatalog")

for module in "${MODULES[@]}"; do
    # Create module events
    php artisan module:event $module ${module}DomainEvent --abstract

    # Create event listeners
    php artisan module:listener $module Handle${module}Events

    # Create event projectors
    php artisan module:projector $module ${module}Projector

    # Create read models
    php artisan module:read-model $module ${module}ReadModel
done

# Generate integration events
php artisan module:event SharedKernel IntegrationEvent --abstract
php artisan module:event SharedKernel UserRegistered --extends=IntegrationEvent
php artisan module:event SharedKernel OrderPlaced --extends=IntegrationEvent

# Generate event buses
php artisan module:bus SharedKernel DomainEventBus
php artisan module:bus SharedKernel IntegrationEventBus

echo "✅ Event-driven architecture setup complete!"
```

### 3. API Development Pattern

Generate complete API with documentation:

```bash
#!/bin/bash
# generate-api.sh

MODULE=$1
VERSION=${2:-v1}

echo "🌐 Generating API for $MODULE (version $VERSION)"

# Generate API controllers
php artisan module:controller $MODULE Api\\${VERSION}\\ProductController --api
php artisan module:controller $MODULE Api\\${VERSION}\\OrderController --api

# Generate API resources
php artisan module:resource $MODULE ProductResource --collection
php artisan module:resource $MODULE OrderResource --collection

# Generate API requests
php artisan module:request $MODULE CreateProductRequest --validation
php artisan module:request $MODULE UpdateProductRequest --validation

# Generate API tests
php artisan module:test $MODULE --type=api --version=$VERSION

# Generate OpenAPI documentation
php artisan module:api-docs $MODULE --version=$VERSION

echo "✅ API generation complete for $MODULE"
```

## Migration and Refactoring

### 1. Legacy Code Migration

Migrate existing Laravel application to DDD structure:

```bash
#!/bin/bash
# migrate-to-ddd.sh

echo "🔄 Migrating legacy application to DDD..."

# Analyze existing models and controllers
php artisan ddd:analyze --existing-code

# Extract modules from existing code
php artisan ddd:extract User --from-model=App\\Models\\User
php artisan ddd:extract Order --from-model=App\\Models\\Order
php artisan ddd:extract Product --from-model=App\\Models\\Product

# Generate aggregates from existing models
php artisan module:aggregate UserManagement User --from-model=App\\Models\\User
php artisan module:aggregate OrderManagement Order --from-model=App\\Models\\Order

# Convert existing controllers to command/query handlers
php artisan ddd:convert-controller App\\Http\\Controllers\\UserController \
    --to-module=UserManagement

# Generate migration scripts
php artisan ddd:generate-migration-script --from-legacy

echo "✅ Legacy migration structure generated!"
echo "📝 Manual steps required:"
echo "   1. Review generated aggregates"
echo "   2. Extract business logic from controllers"
echo "   3. Update database relationships"
echo "   4. Test migration thoroughly"
```

### 2. Module Refactoring

Refactor existing modules for better domain boundaries:

```bash
#!/bin/bash
# refactor-module.sh

OLD_MODULE=$1
NEW_MODULES=("${@:2}")

echo "🔧 Refactoring $OLD_MODULE into: ${NEW_MODULES[*]}"

# Analyze existing module
php artisan module:info $OLD_MODULE --detailed --export-structure

# Create new modules
for new_module in "${NEW_MODULES[@]}"; do
    php artisan module:make $new_module
done

# Generate migration plan
php artisan ddd:plan-refactoring $OLD_MODULE --target-modules="${NEW_MODULES[*]}"

# Create data migration commands
php artisan module:command SharedKernel Migrate${OLD_MODULE}DataCommand --handler

echo "✅ Refactoring plan generated!"
echo "📝 Next steps:"
echo "   1. Review migration plan"
echo "   2. Move aggregates to appropriate modules"
echo "   3. Update module dependencies"
echo "   4. Run data migration"
echo "   5. Update tests"
```

## Team Collaboration

### 1. Feature Branch Workflow

Standardized workflow for feature development:

```bash
#!/bin/bash
# feature-branch.sh

FEATURE_NAME=$1
MODULE_NAME=$2

# Create feature branch
git checkout -b "feature/${FEATURE_NAME,,}"

# Generate feature structure
php artisan module:feature $MODULE_NAME $FEATURE_NAME \
    --commands --queries --tests

# Create pull request template
cat > .github/pull_request_template.md << EOF
## Feature: $FEATURE_NAME

### Changes Made
- [ ] Domain modeling completed
- [ ] Commands implemented
- [ ] Queries implemented
- [ ] Tests written
- [ ] Documentation updated

### Testing
- [ ] Unit tests pass
- [ ] Integration tests pass
- [ ] Performance tests pass

### Checklist
- [ ] Code follows DDD principles
- [ ] No direct database access in domain layer
- [ ] Events are properly emitted
- [ ] Validation is in place
EOF

echo "✅ Feature branch $FEATURE_NAME created and structured!"
```

### 2. Code Review Checklist Generator

Generate code review checklist for DDD compliance:

```bash
#!/bin/bash
# generate-review-checklist.sh

MODULE=$1

echo "📋 Generating code review checklist for $MODULE"

cat > "review-checklist-${MODULE,,}.md" << EOF
# Code Review Checklist: $MODULE

## Domain Layer
- [ ] Aggregates contain business logic, not just data
- [ ] Domain events are emitted for significant state changes
- [ ] Value objects are immutable and validate themselves
- [ ] No dependencies on infrastructure concerns

## Application Layer
- [ ] Command handlers coordinate but don't contain business logic
- [ ] Query handlers are focused on data retrieval
- [ ] Proper error handling and validation
- [ ] Transaction boundaries are explicit

## Infrastructure Layer
- [ ] Repository implementations don't leak database concerns
- [ ] Event listeners are properly registered
- [ ] External service integrations are properly abstracted

## Tests
- [ ] Unit tests cover domain logic
- [ ] Integration tests cover complete workflows
- [ ] Test doubles are used appropriately
- [ ] Performance tests for critical paths

## General
- [ ] Code follows PSR-12 standards
- [ ] PHPStan level 8 compliance
- [ ] No coupling between modules
- [ ] Documentation is updated
EOF

echo "✅ Review checklist generated!"
```

## Production Deployment

### 1. Deployment Preparation

Prepare modules for production deployment:

```bash
#!/bin/bash
# prepare-deployment.sh

echo "🚀 Preparing for production deployment..."

# Run all tests
php artisan test --coverage --min=80

# Check code quality
./vendor/bin/phpstan analyze --level=8
./vendor/bin/php-cs-fixer fix --dry-run

# Generate production optimizations
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Generate API documentation
php artisan module:api-docs --all --format=openapi

# Check module health
php artisan module:health --all

# Generate deployment manifest
php artisan ddd:generate-manifest --environment=production

# Run performance benchmarks
php artisan ddd:benchmark --all --output=production-benchmark.json

echo "✅ Production preparation complete!"
```

### 2. Zero-Downtime Deployment

Deploy with zero downtime using module versioning:

```bash
#!/bin/bash
# zero-downtime-deploy.sh

VERSION=$1
MODULES=("${@:2}")

echo "🔄 Deploying version $VERSION with zero downtime..."

# Create version-specific routes
for module in "${MODULES[@]}"; do
    php artisan module:version $module $VERSION --create-routes
done

# Deploy new version alongside current
php artisan module:deploy $VERSION --parallel --health-check

# Gradually shift traffic
php artisan module:traffic-shift --from=current --to=$VERSION --percentage=10
sleep 60
php artisan module:traffic-shift --from=current --to=$VERSION --percentage=50
sleep 60
php artisan module:traffic-shift --from=current --to=$VERSION --percentage=100

# Cleanup old version
php artisan module:cleanup --version=previous

echo "✅ Zero-downtime deployment complete!"
```

These workflows and recipes provide a comprehensive foundation for developing, maintaining, and deploying Laravel applications using the Modular DDD package. Each workflow is designed to enforce best practices while maximizing developer productivity.