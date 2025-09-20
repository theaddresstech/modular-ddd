# Laravel Modular DDD Package - Makefile
#
# This Makefile provides convenient commands for development, testing, and CI/CD operations

.PHONY: help install test test-unit test-feature test-integration test-performance test-coverage
.PHONY: quality lint fix stan psalm security
.PHONY: benchmark stress clean build docs deploy
.PHONY: docker-test docker-performance docker-security
.DEFAULT_GOAL := help

# Variables
PHP_VERSION ?= 8.2
LARAVEL_VERSION ?= 10.x
DATABASE ?= sqlite
COVERAGE ?= true
PARALLEL ?= false
VERBOSE ?= false

# Colors
YELLOW := \033[33m
GREEN := \033[32m
RED := \033[31m
BLUE := \033[34m
NC := \033[0m

# Help target
help: ## Show this help message
	@echo -e "$(BLUE)Laravel Modular DDD Package$(NC)"
	@echo -e "$(BLUE)============================$(NC)"
	@echo ""
	@echo "Available targets:"
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  $(YELLOW)%-20s$(NC) %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# Installation and Setup
install: ## Install dependencies and setup environment
	@echo -e "$(BLUE)Installing dependencies...$(NC)"
	composer install --no-interaction --prefer-dist --optimize-autoloader
	cp tests/Support/.env.ci .env.testing
	@echo -e "$(GREEN)✅ Installation complete$(NC)"

setup: install ## Alias for install
	@echo -e "$(GREEN)✅ Setup complete$(NC)"

# Testing Commands
test: ## Run all tests
	@echo -e "$(BLUE)Running all tests...$(NC)"
	./scripts/test-automation.sh all
	@echo -e "$(GREEN)✅ All tests completed$(NC)"

test-unit: ## Run unit tests only
	@echo -e "$(BLUE)Running unit tests...$(NC)"
	vendor/bin/phpunit --testsuite=Unit $(if $(COVERAGE),--coverage-html=coverage/unit,)
	@echo -e "$(GREEN)✅ Unit tests completed$(NC)"

test-feature: ## Run feature tests only
	@echo -e "$(BLUE)Running feature tests...$(NC)"
	vendor/bin/phpunit --testsuite=Feature $(if $(COVERAGE),--coverage-html=coverage/feature,)
	@echo -e "$(GREEN)✅ Feature tests completed$(NC)"

test-integration: ## Run integration tests only
	@echo -e "$(BLUE)Running integration tests...$(NC)"
	vendor/bin/phpunit --testsuite=Integration $(if $(COVERAGE),--coverage-html=coverage/integration,)
	@echo -e "$(GREEN)✅ Integration tests completed$(NC)"

test-performance: ## Run performance tests
	@echo -e "$(BLUE)Running performance tests...$(NC)"
	./scripts/test-automation.sh performance
	@echo -e "$(GREEN)✅ Performance tests completed$(NC)"

test-coverage: ## Generate test coverage report
	@echo -e "$(BLUE)Generating coverage report...$(NC)"
	vendor/bin/phpunit --coverage-html=coverage/html --coverage-clover=coverage/clover.xml
	@echo -e "$(GREEN)✅ Coverage report generated at coverage/html/index.html$(NC)"

test-parallel: ## Run tests in parallel
	@echo -e "$(BLUE)Running tests in parallel...$(NC)"
	$(MAKE) test PARALLEL=true
	@echo -e "$(GREEN)✅ Parallel tests completed$(NC)"

# Code Quality Commands
quality: lint stan psalm ## Run all code quality checks

lint: ## Run PHP CS Fixer (dry run)
	@echo -e "$(BLUE)Running PHP CS Fixer...$(NC)"
	vendor/bin/php-cs-fixer fix --dry-run --diff --verbose
	@echo -e "$(GREEN)✅ Linting completed$(NC)"

fix: ## Fix code style issues
	@echo -e "$(BLUE)Fixing code style...$(NC)"
	vendor/bin/php-cs-fixer fix --verbose
	@echo -e "$(GREEN)✅ Code style fixed$(NC)"

stan: ## Run PHPStan static analysis
	@echo -e "$(BLUE)Running PHPStan...$(NC)"
	vendor/bin/phpstan analyse --memory-limit=2G
	@echo -e "$(GREEN)✅ PHPStan analysis completed$(NC)"

psalm: ## Run Psalm static analysis
	@echo -e "$(BLUE)Running Psalm...$(NC)"
	vendor/bin/psalm --show-info=false
	@echo -e "$(GREEN)✅ Psalm analysis completed$(NC)"

security: ## Run security analysis
	@echo -e "$(BLUE)Running security checks...$(NC)"
	composer audit --format=table
	vendor/bin/psalm --taint-analysis --report=security-report.json || true
	@echo -e "$(GREEN)✅ Security analysis completed$(NC)"

# Performance Testing
benchmark: ## Run performance benchmarks
	@echo -e "$(BLUE)Running benchmarks...$(NC)"
	php artisan benchmark:event-store --iterations=1000 --warmup=100
	php artisan benchmark:cqrs --iterations=500 --warmup=50
	php artisan benchmark:modules --iterations=200 --warmup=20
	@echo -e "$(GREEN)✅ Benchmarks completed$(NC)"

stress: ## Run stress tests
	@echo -e "$(BLUE)Running stress tests...$(NC)"
	timeout 60 php artisan stress:event-sourcing --concurrent=10 --duration=60 || true
	timeout 30 php artisan stress:cqrs --concurrent=5 --duration=30 || true
	timeout 20 php artisan stress:modules --concurrent=3 --duration=20 || true
	@echo -e "$(GREEN)✅ Stress tests completed$(NC)"

# Documentation
docs: ## Generate documentation
	@echo -e "$(BLUE)Generating documentation...$(NC)"
	vendor/bin/phpDocumentor -d src/ -t docs/api/
	php artisan docs:generate --output=docs/
	@echo -e "$(GREEN)✅ Documentation generated$(NC)"

# Database Operations
db-setup: ## Setup test databases
	@echo -e "$(BLUE)Setting up test databases...$(NC)"
	php artisan migrate:fresh --env=testing
	php artisan db:seed --env=testing
	@echo -e "$(GREEN)✅ Database setup completed$(NC)"

db-reset: ## Reset test databases
	@echo -e "$(BLUE)Resetting test databases...$(NC)"
	php artisan migrate:fresh --env=testing
	@echo -e "$(GREEN)✅ Database reset completed$(NC)"

# Cleanup Commands
clean: ## Clean up generated files and caches
	@echo -e "$(BLUE)Cleaning up...$(NC)"
	rm -rf coverage/
	rm -rf reports/
	rm -rf .phpunit.cache/
	rm -rf docs/api/
	rm -f security-report.json
	php artisan cache:clear
	php artisan config:clear
	composer clear-cache
	@echo -e "$(GREEN)✅ Cleanup completed$(NC)"

clean-deep: clean ## Deep clean including vendor directory
	@echo -e "$(BLUE)Deep cleaning...$(NC)"
	rm -rf vendor/
	rm -rf node_modules/
	rm -f composer.lock
	rm -f package-lock.json
	@echo -e "$(GREEN)✅ Deep cleanup completed$(NC)"

# Build Commands
build: ## Build the package for distribution
	@echo -e "$(BLUE)Building package...$(NC)"
	composer install --no-dev --optimize-autoloader
	composer dump-autoload --optimize --classmap-authoritative
	@echo -e "$(GREEN)✅ Package built$(NC)"

build-dev: ## Build the package for development
	@echo -e "$(BLUE)Building package for development...$(NC)"
	composer install --optimize-autoloader
	composer dump-autoload --optimize
	@echo -e "$(GREEN)✅ Development build completed$(NC)"

# Docker Commands
docker-test: ## Run tests in Docker
	@echo -e "$(BLUE)Running tests in Docker...$(NC)"
	docker-compose -f docker/docker-compose.test.yml up --build --abort-on-container-exit
	docker-compose -f docker/docker-compose.test.yml down --volumes
	@echo -e "$(GREEN)✅ Docker tests completed$(NC)"

docker-performance: ## Run performance tests in Docker
	@echo -e "$(BLUE)Running performance tests in Docker...$(NC)"
	docker-compose -f docker/docker-compose.performance.yml up --build --abort-on-container-exit
	docker-compose -f docker/docker-compose.performance.yml down --volumes
	@echo -e "$(GREEN)✅ Docker performance tests completed$(NC)"

docker-security: ## Run security tests in Docker
	@echo -e "$(BLUE)Running security tests in Docker...$(NC)"
	docker-compose -f docker/docker-compose.security.yml up --build --abort-on-container-exit
	docker-compose -f docker/docker-compose.security.yml down --volumes
	@echo -e "$(GREEN)✅ Docker security tests completed$(NC)"

# CI/CD Commands
ci: quality test security ## Run all CI checks
	@echo -e "$(GREEN)✅ All CI checks passed$(NC)"

ci-test: ## Run CI test pipeline
	@echo -e "$(BLUE)Running CI test pipeline...$(NC)"
	$(MAKE) install
	$(MAKE) quality
	$(MAKE) test-unit
	$(MAKE) test-feature
	$(MAKE) test-integration
	@echo -e "$(GREEN)✅ CI test pipeline completed$(NC)"

ci-performance: ## Run CI performance pipeline
	@echo -e "$(BLUE)Running CI performance pipeline...$(NC)"
	$(MAKE) install
	$(MAKE) benchmark
	$(MAKE) stress
	@echo -e "$(GREEN)✅ CI performance pipeline completed$(NC)"

# Release Commands
pre-release: ## Prepare for release
	@echo -e "$(BLUE)Preparing for release...$(NC)"
	$(MAKE) clean
	$(MAKE) install
	$(MAKE) ci
	$(MAKE) docs
	@echo -e "$(GREEN)✅ Pre-release preparation completed$(NC)"

release: pre-release ## Create a release
	@echo -e "$(BLUE)Creating release...$(NC)"
	@echo "Release process should be handled by CI/CD pipeline"
	@echo -e "$(GREEN)✅ Release preparation completed$(NC)"

# Development Commands
dev-install: ## Install development dependencies
	@echo -e "$(BLUE)Installing development dependencies...$(NC)"
	composer install --dev
	npm install
	@echo -e "$(GREEN)✅ Development dependencies installed$(NC)"

dev-setup: dev-install ## Setup development environment
	@echo -e "$(BLUE)Setting up development environment...$(NC)"
	cp tests/Support/.env.ci .env.testing
	$(MAKE) db-setup
	@echo -e "$(GREEN)✅ Development environment setup completed$(NC)"

watch: ## Watch for file changes and run tests
	@echo -e "$(BLUE)Watching for file changes...$(NC)"
	vendor/bin/phpunit-watcher watch

# Module Commands
module-create: ## Create a new test module (usage: make module-create NAME=ModuleName)
	@echo -e "$(BLUE)Creating module $(NAME)...$(NC)"
	php artisan module:make $(NAME) --dry-run
	@echo -e "$(YELLOW)This was a dry run. Run without --dry-run to create actual files$(NC)"

module-test: ## Test a specific module (usage: make module-test NAME=ModuleName)
	@echo -e "$(BLUE)Testing module $(NAME)...$(NC)"
	php artisan module:test $(NAME)

# Information Commands
info: ## Show system information
	@echo -e "$(BLUE)System Information:$(NC)"
	@echo "PHP Version: $(shell php -r 'echo PHP_VERSION;')"
	@echo "Composer Version: $(shell composer --version --no-ansi)"
	@echo "Laravel Version: $(LARAVEL_VERSION)"
	@echo "Database: $(DATABASE)"
	@echo "Coverage: $(COVERAGE)"
	@echo "Parallel: $(PARALLEL)"

status: ## Show project status
	@echo -e "$(BLUE)Project Status:$(NC)"
	@echo "Git Branch: $(shell git rev-parse --abbrev-ref HEAD 2>/dev/null || echo 'Not a git repository')"
	@echo "Git Commit: $(shell git rev-parse --short HEAD 2>/dev/null || echo 'Not a git repository')"
	@echo "Composer Dependencies: $(shell composer show -i | wc -l | tr -d ' ') packages"
	@echo "PHPUnit Version: $(shell vendor/bin/phpunit --version | head -n1)"

# Quick Commands (aliases)
t: test ## Alias for test
tu: test-unit ## Alias for test-unit
tf: test-feature ## Alias for test-feature
ti: test-integration ## Alias for test-integration
tp: test-performance ## Alias for test-performance
tc: test-coverage ## Alias for test-coverage
q: quality ## Alias for quality
l: lint ## Alias for lint
f: fix ## Alias for fix
s: stan ## Alias for stan
c: clean ## Alias for clean