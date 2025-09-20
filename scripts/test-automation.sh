#!/bin/bash

# Test Automation Script for Laravel Modular DDD Package
# This script provides comprehensive testing automation for local development and CI/CD

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
PHP_VERSION=${PHP_VERSION:-8.2}
LARAVEL_VERSION=${LARAVEL_VERSION:-10.x}
DATABASE=${DATABASE:-sqlite}
COVERAGE=${COVERAGE:-true}
PARALLEL=${PARALLEL:-false}
VERBOSE=${VERBOSE:-false}

# Functions
print_header() {
    echo -e "${BLUE}================================${NC}"
    echo -e "${BLUE}  Laravel Modular DDD Testing  ${NC}"
    echo -e "${BLUE}================================${NC}"
    echo ""
}

print_section() {
    echo -e "${YELLOW}>> $1${NC}"
}

print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

check_requirements() {
    print_section "Checking Requirements"

    # Check PHP version
    if ! command -v php &> /dev/null; then
        print_error "PHP is not installed"
        exit 1
    fi

    PHP_CURRENT=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
    print_info "PHP Version: $PHP_CURRENT"

    # Check Composer
    if ! command -v composer &> /dev/null; then
        print_error "Composer is not installed"
        exit 1
    fi

    print_info "Composer Version: $(composer --version --no-ansi)"

    # Check required PHP extensions
    REQUIRED_EXTENSIONS=("mbstring" "tokenizer" "xml" "json" "curl")
    for ext in "${REQUIRED_EXTENSIONS[@]}"; do
        if ! php -m | grep -q "^$ext$"; then
            print_error "PHP extension '$ext' is not installed"
            exit 1
        fi
    done

    # Check PDO separately (it might be compiled in or show as pdo_*)
    if ! php -m | grep -q "pdo" && ! php -r "class_exists('PDO') || exit(1);"; then
        print_error "PDO is not available (required for database operations)"
        exit 1
    fi

    print_success "All requirements met"
}

setup_environment() {
    print_section "Setting Up Environment"

    # Get script directory and project root
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

    # Copy appropriate .env file
    case $DATABASE in
        "mysql")
            if [ -f "$PROJECT_ROOT/tests/Support/.env.mysql" ]; then
                cp "$PROJECT_ROOT/tests/Support/.env.mysql" "$PROJECT_ROOT/.env.testing"
            else
                print_error "MySQL environment file not found"
                exit 1
            fi
            ;;
        "pgsql")
            if [ -f "$PROJECT_ROOT/tests/Support/.env.pgsql" ]; then
                cp "$PROJECT_ROOT/tests/Support/.env.pgsql" "$PROJECT_ROOT/.env.testing"
            else
                # Fallback to CI environment for PostgreSQL
                cp "$PROJECT_ROOT/tests/Support/.env.ci" "$PROJECT_ROOT/.env.testing"
            fi
            ;;
        "sqlite"|*)
            if [ -f "$PROJECT_ROOT/tests/Support/.env.sqlite" ]; then
                cp "$PROJECT_ROOT/tests/Support/.env.sqlite" "$PROJECT_ROOT/.env.testing"
            else
                print_error "SQLite environment file not found"
                exit 1
            fi
            ;;
    esac

    # Install dependencies if needed
    cd "$PROJECT_ROOT"
    if [ ! -d "vendor" ]; then
        print_info "Installing Composer dependencies..."
        composer install --no-interaction --prefer-dist --optimize-autoloader
    fi

    # Setup Laravel testing environment
    php vendor/bin/testbench workbench:install --no-interaction

    print_success "Environment setup complete"
}

run_code_quality_checks() {
    print_section "Running Code Quality Checks"

    # PHP CS Fixer
    if [ -f "vendor/bin/php-cs-fixer" ]; then
        print_info "Running PHP CS Fixer..."
        vendor/bin/php-cs-fixer fix --dry-run --diff --verbose
        print_success "PHP CS Fixer passed"
    fi

    # PHPStan
    if [ -f "vendor/bin/phpstan" ]; then
        print_info "Running PHPStan..."
        vendor/bin/phpstan analyse --memory-limit=2G
        print_success "PHPStan passed"
    fi

    # Psalm
    if [ -f "vendor/bin/psalm" ]; then
        print_info "Running Psalm..."
        vendor/bin/psalm --show-info=false
        print_success "Psalm passed"
    fi

    # PHPMD
    if [ -f "vendor/bin/phpmd" ]; then
        print_info "Running PHPMD..."
        vendor/bin/phpmd src text phpmd.xml
        print_success "PHPMD passed"
    fi
}

run_unit_tests() {
    print_section "Running Unit Tests"

    local cmd="vendor/bin/phpunit --testsuite=Unit"

    if [ "$COVERAGE" = "true" ]; then
        cmd="$cmd --coverage-html=coverage/unit --coverage-clover=coverage/unit.xml"
    fi

    if [ "$VERBOSE" = "true" ]; then
        cmd="$cmd --verbose"
    fi

    if [ "$PARALLEL" = "true" ] && [ -f "vendor/bin/paratest" ]; then
        cmd="vendor/bin/paratest --testsuite=Unit"
        if [ "$COVERAGE" = "true" ]; then
            cmd="$cmd --coverage-html=coverage/unit --coverage-clover=coverage/unit.xml"
        fi
    fi

    print_info "Command: $cmd"
    eval $cmd

    print_success "Unit tests passed"
}

run_feature_tests() {
    print_section "Running Feature Tests"

    local cmd="vendor/bin/phpunit --testsuite=Feature"

    if [ "$COVERAGE" = "true" ]; then
        cmd="$cmd --coverage-html=coverage/feature --coverage-clover=coverage/feature.xml"
    fi

    if [ "$VERBOSE" = "true" ]; then
        cmd="$cmd --verbose"
    fi

    if [ "$PARALLEL" = "true" ] && [ -f "vendor/bin/paratest" ]; then
        cmd="vendor/bin/paratest --testsuite=Feature"
        if [ "$COVERAGE" = "true" ]; then
            cmd="$cmd --coverage-html=coverage/feature --coverage-clover=coverage/feature.xml"
        fi
    fi

    print_info "Command: $cmd"
    eval $cmd

    print_success "Feature tests passed"
}

run_integration_tests() {
    print_section "Running Integration Tests"

    local cmd="vendor/bin/phpunit --testsuite=Integration"

    if [ "$COVERAGE" = "true" ]; then
        cmd="$cmd --coverage-html=coverage/integration --coverage-clover=coverage/integration.xml"
    fi

    if [ "$VERBOSE" = "true" ]; then
        cmd="$cmd --verbose"
    fi

    print_info "Command: $cmd"
    eval $cmd

    print_success "Integration tests passed"
}

run_performance_tests() {
    print_section "Running Performance Tests"

    # Event Store Performance
    print_info "Testing Event Store performance..."
    php artisan benchmark:event-store --iterations=100 --warmup=10

    # CQRS Performance
    print_info "Testing CQRS performance..."
    php artisan benchmark:cqrs --iterations=50 --warmup=5

    # Module Communication Performance
    print_info "Testing Module communication performance..."
    php artisan benchmark:modules --iterations=20 --warmup=2

    print_success "Performance tests completed"
}

run_stress_tests() {
    print_section "Running Stress Tests"

    # Event Sourcing Stress Test
    print_info "Stress testing Event Sourcing..."
    timeout 30 php artisan stress:event-sourcing --concurrent=5 --duration=30 || true

    # CQRS Stress Test
    print_info "Stress testing CQRS..."
    timeout 20 php artisan stress:cqrs --concurrent=3 --duration=20 || true

    # Module Communication Stress Test
    print_info "Stress testing Module communication..."
    timeout 15 php artisan stress:modules --concurrent=2 --duration=15 || true

    print_success "Stress tests completed"
}

generate_coverage_report() {
    if [ "$COVERAGE" = "true" ]; then
        print_section "Generating Coverage Report"

        # Merge coverage reports if multiple exist
        if [ -f "vendor/bin/phpcov" ]; then
            print_info "Merging coverage reports..."
            vendor/bin/phpcov merge --clover=coverage/merged.xml coverage/

            # Generate HTML report
            vendor/bin/phpcov merge --html=coverage/html coverage/

            print_info "Coverage report generated at: coverage/html/index.html"
        fi

        print_success "Coverage report generated"
    fi
}

run_security_checks() {
    print_section "Running Security Checks"

    # Composer security audit
    print_info "Running Composer security audit..."
    composer audit --format=table

    # Psalm taint analysis
    if [ -f "vendor/bin/psalm" ]; then
        print_info "Running Psalm taint analysis..."
        vendor/bin/psalm --taint-analysis --report=security-report.json || true
    fi

    print_success "Security checks completed"
}

cleanup() {
    print_section "Cleaning Up"

    # Get project root if not set
    if [ -z "$PROJECT_ROOT" ]; then
        SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
        PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
    fi

    cd "$PROJECT_ROOT"

    # Remove temporary files
    rm -f .env.testing

    # Clean up test databases
    if [ -f "database/database.sqlite" ]; then
        rm -f database/database.sqlite
    fi

    print_success "Cleanup completed"
}

show_summary() {
    print_section "Test Summary"

    echo "Test Environment:"
    echo "  PHP Version: $PHP_CURRENT"
    echo "  Laravel Version: $LARAVEL_VERSION"
    echo "  Database: $DATABASE"
    echo "  Coverage: $COVERAGE"
    echo "  Parallel: $PARALLEL"
    echo ""

    if [ "$COVERAGE" = "true" ] && [ -f "coverage/merged.xml" ]; then
        print_info "Coverage Report: coverage/html/index.html"
    fi

    if [ -f "security-report.json" ]; then
        print_info "Security Report: security-report.json"
    fi

    print_success "All tests completed successfully!"
}

show_help() {
    echo "Laravel Modular DDD Test Automation Script"
    echo ""
    echo "Usage: $0 [OPTIONS] [COMMAND]"
    echo ""
    echo "Commands:"
    echo "  all             Run all tests (default)"
    echo "  quality         Run code quality checks only"
    echo "  unit            Run unit tests only"
    echo "  feature         Run feature tests only"
    echo "  integration     Run integration tests only"
    echo "  performance     Run performance tests only"
    echo "  stress          Run stress tests only"
    echo "  security        Run security checks only"
    echo "  help            Show this help message"
    echo ""
    echo "Options:"
    echo "  --database=DB   Database to use (sqlite, mysql, pgsql) [default: sqlite]"
    echo "  --no-coverage   Disable coverage reporting"
    echo "  --parallel      Use parallel testing"
    echo "  --verbose       Enable verbose output"
    echo ""
    echo "Environment Variables:"
    echo "  PHP_VERSION     PHP version to use [default: 8.2]"
    echo "  LARAVEL_VERSION Laravel version to use [default: 10.x]"
    echo ""
    echo "Examples:"
    echo "  $0                           # Run all tests"
    echo "  $0 unit --verbose            # Run unit tests with verbose output"
    echo "  $0 --database=mysql all      # Run all tests with MySQL"
    echo "  $0 performance               # Run performance tests only"
}

# Parse command line arguments
COMMAND="all"
while [[ $# -gt 0 ]]; do
    case $1 in
        --database=*)
            DATABASE="${1#*=}"
            shift
            ;;
        --no-coverage)
            COVERAGE="false"
            shift
            ;;
        --parallel)
            PARALLEL="true"
            shift
            ;;
        --verbose)
            VERBOSE="true"
            shift
            ;;
        help|--help|-h)
            show_help
            exit 0
            ;;
        all|quality|unit|feature|integration|performance|stress|security)
            COMMAND="$1"
            shift
            ;;
        *)
            print_error "Unknown option: $1"
            show_help
            exit 1
            ;;
    esac
done

# Main execution
main() {
    print_header
    check_requirements
    setup_environment

    case $COMMAND in
        "all")
            run_code_quality_checks
            run_unit_tests
            run_feature_tests
            run_integration_tests
            run_performance_tests
            run_stress_tests
            run_security_checks
            generate_coverage_report
            ;;
        "quality")
            run_code_quality_checks
            ;;
        "unit")
            run_unit_tests
            ;;
        "feature")
            run_feature_tests
            ;;
        "integration")
            run_integration_tests
            ;;
        "performance")
            run_performance_tests
            ;;
        "stress")
            run_stress_tests
            ;;
        "security")
            run_security_checks
            ;;
    esac

    cleanup
    show_summary
}

# Execute main function
main