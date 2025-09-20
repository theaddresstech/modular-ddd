<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Database Connections for Testing
    |--------------------------------------------------------------------------
    |
    | Database connections specifically configured for testing environments.
    | These configurations prioritize speed and isolation over persistence.
    |
    */

    'default' => env('DB_CONNECTION', 'testing'),

    'connections' => [
        // Fast in-memory database for unit tests
        'testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

        // SQLite file for tests that need persistence
        'testing_file' => [
            'driver' => 'sqlite',
            'database' => database_path('testing.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

        // MySQL for integration tests
        'testing_mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_TEST_HOST', '127.0.0.1'),
            'port' => env('DB_TEST_PORT', '3306'),
            'database' => env('DB_TEST_DATABASE', 'ddd_testing'),
            'username' => env('DB_TEST_USERNAME', 'root'),
            'password' => env('DB_TEST_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        // PostgreSQL for integration tests
        'testing_postgres' => [
            'driver' => 'pgsql',
            'host' => env('DB_TEST_PG_HOST', '127.0.0.1'),
            'port' => env('DB_TEST_PG_PORT', '5432'),
            'database' => env('DB_TEST_PG_DATABASE', 'ddd_testing'),
            'username' => env('DB_TEST_PG_USERNAME', 'postgres'),
            'password' => env('DB_TEST_PG_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'schema' => 'public',
            'sslmode' => 'prefer',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Configuration for Testing
    |--------------------------------------------------------------------------
    */

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),

        'testing' => [
            'host' => env('REDIS_TEST_HOST', '127.0.0.1'),
            'password' => env('REDIS_TEST_PASSWORD', null),
            'port' => env('REDIS_TEST_PORT', 6379),
            'database' => env('REDIS_TEST_DB', 1),
        ],

        'testing_cache' => [
            'host' => env('REDIS_TEST_HOST', '127.0.0.1'),
            'password' => env('REDIS_TEST_PASSWORD', null),
            'port' => env('REDIS_TEST_PORT', 6379),
            'database' => env('REDIS_TEST_CACHE_DB', 2),
        ],

        'testing_queue' => [
            'host' => env('REDIS_TEST_HOST', '127.0.0.1'),
            'password' => env('REDIS_TEST_PASSWORD', null),
            'port' => env('REDIS_TEST_PORT', 6379),
            'database' => env('REDIS_TEST_QUEUE_DB', 3),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Testing Configuration
    |--------------------------------------------------------------------------
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Testing-Specific Event Store Configuration
    |--------------------------------------------------------------------------
    */

    'event_store' => [
        'hot_tier' => [
            'enabled' => true,
            'connection' => 'testing',
            'ttl' => 300, // 5 minutes for testing
        ],
        'warm_tier' => [
            'connection' => 'testing',
            'table' => 'event_store',
        ],
        'performance' => [
            'batch_size' => 50, // Smaller batches for testing
            'connection_pool_size' => 2,
            'query_timeout' => 10,
        ],
    ],
];