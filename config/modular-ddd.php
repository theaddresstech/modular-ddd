<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Module Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the modular DDD package
    |
    */

    'modules_path' => base_path('modules'),
    'module_namespace' => 'Modules',

    /*
    |--------------------------------------------------------------------------
    | Event Sourcing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for event sourcing functionality including tiered storage
    |
    */
    'event_sourcing' => [
        'enabled' => env('EVENT_SOURCING_ENABLED', true),

        'storage_tiers' => [
            'hot' => [
                'driver' => 'redis',
                'connection' => env('EVENT_STORE_REDIS_CONNECTION', 'default'),
                'ttl' => env('EVENT_STORE_HOT_TTL', 86400), // 24 hours
                'enabled' => env('EVENT_STORE_HOT_ENABLED', true),
            ],
            'warm' => [
                'driver' => env('EVENT_STORE_WARM_DRIVER', 'mysql'),
                'connection' => env('EVENT_STORE_WARM_CONNECTION', 'mysql'),
                'table' => 'event_store',
                'snapshots_table' => 'snapshots',
            ],
        ],

        'async_warm_storage' => env('EVENT_STORE_ASYNC_WARM', true),

        'serialization' => [
            'format' => 'json', // json, msgpack (if available)
            'compression' => false, // Enable compression for large events
        ],

        'snapshots' => [
            'enabled' => env('EVENT_SOURCING_SNAPSHOTS_ENABLED', true),

            // Default: Simple and predictable (PRD requirement: every 10 events)
            'strategy' => env('SNAPSHOT_STRATEGY', 'simple'), // simple, adaptive, time_based
            'threshold' => env('SNAPSHOT_THRESHOLD', 10), // PRD requirement: every 10 events

            // Optional: For advanced users who need adaptive behavior
            'adaptive_config' => [
                'event_count_threshold' => env('SNAPSHOT_EVENT_THRESHOLD', 50),
                'time_threshold_seconds' => env('SNAPSHOT_TIME_THRESHOLD', 3600), // 1 hour
                'complexity_multiplier' => 1.0,
                'access_frequency_weight' => 0.3,
                'size_weight' => 0.2,
                'performance_weight' => 0.5,
                'min_threshold' => 10,
                'max_threshold' => 1000,
            ],

            // Time-based strategy configuration
            'time_interval' => env('SNAPSHOT_TIME_INTERVAL', 3600), // 1 hour

            'retention' => [
                'keep_count' => 3, // Keep last 3 snapshots
                'max_age_days' => 30, // Remove snapshots older than 30 days
            ],
        ],

        'performance' => [
            'batch_size' => 100, // Events per batch for bulk operations
            'connection_pool_size' => 10,
            'query_timeout' => 30, // seconds
        ],

        'ordering' => [
            'strict_ordering' => env('EVENT_STRICT_ORDERING', true),
            'max_reorder_window' => env('EVENT_MAX_REORDER_WINDOW', 100),
            'sequence_cache_ttl' => env('EVENT_SEQUENCE_CACHE_TTL', 3600), // 1 hour
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | CQRS Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Command Query Responsibility Segregation
    |
    */
    'cqrs' => [
        'command_bus' => [
            'default_mode' => env('CQRS_DEFAULT_MODE', 'sync'), // sync, async, eventual
            'timeout' => env('COMMAND_TIMEOUT', 30),
            'retry_attempts' => 3,
            'middleware' => [
                'validation' => true,
                'authorization' => true,
                'logging' => env('CQRS_LOGGING', true),
                'transactions' => true,
            ],
        ],

        'query_bus' => [
            'cache_enabled' => env('QUERY_CACHE_ENABLED', true),
            'cache_ttl' => env('QUERY_CACHE_TTL', 900), // 15 minutes
            'cache_driver' => env('QUERY_CACHE_DRIVER', 'redis'),
            'timeout' => env('QUERY_TIMEOUT', 10),

            // Multi-tier cache configuration
            'cache_stores' => [
                'l2' => env('QUERY_L2_CACHE_STORE', 'redis'),
                'l3' => env('QUERY_L3_CACHE_STORE', 'database'),
            ],

            // Memory management for L1 cache
            'memory_limits' => [
                'l1_max_entries' => env('QUERY_L1_MAX_ENTRIES', 1000),
                'max_memory_mb' => env('QUERY_MAX_MEMORY_MB', 128),
                'eviction_threshold' => env('QUERY_EVICTION_THRESHOLD', 0.8),
            ],
        ],

        'read_models' => [
            'separate_connection' => env('READ_MODEL_SEPARATE_CONNECTION', false),
            'connection' => env('READ_MODEL_CONNECTION', null),
            'auto_projection' => env('AUTO_PROJECTION_ENABLED', true),
            'projection_batch_size' => 100,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Async Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for asynchronous command processing
    |
    */
    'async' => [
        'strategy' => env('ASYNC_STRATEGY', 'laravel_queue'), // sync, laravel_queue
        'queue' => env('ASYNC_QUEUE_NAME', 'commands'),
        'timeout' => env('ASYNC_TIMEOUT', 300), // 5 minutes
        'max_retries' => env('ASYNC_MAX_RETRIES', 3),
        'retry_delay' => env('ASYNC_RETRY_DELAY', 5), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction Management Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for database transaction management
    |
    */
    'transactions' => [
        'deadlock_retry_attempts' => env('TRANSACTION_DEADLOCK_RETRIES', 3),
        'deadlock_retry_delay' => env('TRANSACTION_DEADLOCK_DELAY', 100), // milliseconds
        'default_isolation_level' => env('TRANSACTION_ISOLATION_LEVEL', 'READ_COMMITTED'),
        'default_timeout' => env('TRANSACTION_TIMEOUT', 30), // seconds
        'distributed' => [
            'enabled' => env('DISTRIBUTED_TRANSACTIONS_ENABLED', false),
            'timeout' => env('DISTRIBUTED_TRANSACTION_TIMEOUT', 300), // 5 minutes
            'coordinator' => env('DISTRIBUTED_TRANSACTION_COORDINATOR', 'database'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Projection Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for event projection strategies
    |
    */
    'projections' => [
        'enabled' => env('PROJECTIONS_ENABLED', true),
        'strategies' => [
            'realtime' => [
                'event_patterns' => ['*'], // Process all events in real-time
            ],
            'async' => [
                'event_patterns' => ['*'],
                'queue' => env('PROJECTION_QUEUE', 'projections'),
                'delay' => env('PROJECTION_DELAY', 0),
            ],
            'batched' => [
                'event_patterns' => ['*'],
                'batch_size' => env('PROJECTION_BATCH_SIZE', 100),
                'batch_timeout' => env('PROJECTION_BATCH_TIMEOUT', 60), // seconds
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Module Management
    |--------------------------------------------------------------------------
    |
    | Configuration for module discovery, loading, and management
    |
    */
    'module_management' => [
        'auto_discovery' => env('MODULE_AUTO_DISCOVERY', true),
        'preload_modules' => env('MODULE_PRELOAD', true),
        'cache_manifests' => env('MODULE_CACHE_MANIFESTS', true),
        'cache_ttl' => 3600, // 1 hour

        'health_checks' => [
            'enabled' => env('MODULE_HEALTH_CHECKS', true),
            'check_dependencies' => true,
            'check_services' => true,
            'check_migrations' => true,
        ],

        'dependency_resolution' => [
            'strict_versioning' => env('MODULE_STRICT_VERSIONING', false),
            'allow_circular' => false,
            'max_depth' => 10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Configuration
    |--------------------------------------------------------------------------
    |
    | Performance-related settings
    |
    */
    'performance' => [
        'cache' => [
            'driver' => env('MODULE_CACHE_DRIVER', 'redis'),
            'prefix' => 'ddd_modules',
            'ttl' => 3600,
            'tags_enabled' => true,
        ],

        'monitoring' => [
            'enabled' => env('DDD_MONITORING_ENABLED', true),
            'slow_query_threshold' => 1000, // milliseconds
            'slow_command_threshold' => 2000, // milliseconds
            'memory_threshold' => 128, // MB
            'metrics_collector' => env('METRICS_COLLECTOR', 'memory'), // memory, redis, database
            'performance_thresholds' => [
                'command_processing_ms' => env('PERFORMANCE_COMMAND_THRESHOLD', 200),
                'query_processing_ms' => env('PERFORMANCE_QUERY_THRESHOLD', 100),
                'event_processing_ms' => env('PERFORMANCE_EVENT_THRESHOLD', 50),
                'memory_usage_mb' => env('PERFORMANCE_MEMORY_THRESHOLD', 256),
                'cpu_usage_percent' => env('PERFORMANCE_CPU_THRESHOLD', 80),
            ],
        ],

        'optimization' => [
            'eager_load_events' => false,
            'batch_event_loading' => true,
            'connection_pooling' => env('DDD_CONNECTION_POOLING', false),
        ],

        // Performance profiles for different scales
        'profiles' => [
            'startup' => [
                'description' => 'Minimal resource usage for small applications',
                'event_sourcing' => [
                    'snapshots' => ['strategy' => 'simple', 'threshold' => 10],
                    'hot_storage_ttl' => 3600, // 1 hour
                ],
                'cqrs' => [
                    'query_cache_ttl' => 300, // 5 minutes
                    'memory_limits' => ['l1_max_entries' => 100, 'max_memory_mb' => 32],
                ],
                'async' => ['strategy' => 'sync'],
                'projections' => ['strategies' => ['realtime']],
            ],

            'growth' => [
                'description' => 'Balanced performance for growing applications',
                'event_sourcing' => [
                    'snapshots' => ['strategy' => 'simple', 'threshold' => 10],
                    'hot_storage_ttl' => 86400, // 24 hours
                ],
                'cqrs' => [
                    'query_cache_ttl' => 900, // 15 minutes
                    'memory_limits' => ['l1_max_entries' => 500, 'max_memory_mb' => 64],
                ],
                'async' => ['strategy' => 'laravel_queue'],
                'projections' => ['strategies' => ['realtime', 'async']],
            ],

            'scale' => [
                'description' => 'High performance for large-scale applications',
                'event_sourcing' => [
                    'snapshots' => ['strategy' => 'adaptive'],
                    'hot_storage_ttl' => 86400, // 24 hours
                    'storage_tiers' => ['hot' => ['enabled' => true]],
                ],
                'cqrs' => [
                    'query_cache_ttl' => 1800, // 30 minutes
                    'memory_limits' => ['l1_max_entries' => 2000, 'max_memory_mb' => 256],
                ],
                'async' => ['strategy' => 'laravel_queue'],
                'projections' => ['strategies' => ['async', 'batched']],
            ],

            'enterprise' => [
                'description' => 'Maximum performance for enterprise applications',
                'event_sourcing' => [
                    'snapshots' => ['strategy' => 'adaptive'],
                    'hot_storage_ttl' => 172800, // 48 hours
                    'storage_tiers' => ['hot' => ['enabled' => true]],
                ],
                'cqrs' => [
                    'query_cache_ttl' => 3600, // 1 hour
                    'memory_limits' => ['l1_max_entries' => 5000, 'max_memory_mb' => 512],
                ],
                'async' => ['strategy' => 'laravel_queue', 'queue' => 'high-priority'],
                'projections' => ['strategies' => ['async', 'batched']],
                'monitoring' => ['metrics_collector' => 'redis'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Code Generation Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for generating modules and components
    |
    */
    'generators' => [
        'stubs_path' => resource_path('stubs/ddd'),
        'default_stub_set' => 'default', // default, minimal, complete

        'namespaces' => [
            'domain' => 'Domain',
            'application' => 'Application',
            'infrastructure' => 'Infrastructure',
            'presentation' => 'Presentation',
        ],

        'auto_generate' => [
            'tests' => env('AUTO_GENERATE_TESTS', true),
            'factories' => env('AUTO_GENERATE_FACTORIES', true),
            'migrations' => env('AUTO_GENERATE_MIGRATIONS', true),
            'seeders' => env('AUTO_GENERATE_SEEDERS', false),
        ],

        'code_style' => [
            'strict_types' => true,
            'final_classes' => true,
            'readonly_properties' => true,
            'typed_properties' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Testing Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for testing infrastructure
    |
    */
    'testing' => [
        'in_memory_stores' => env('DDD_TESTING_IN_MEMORY', true),
        'auto_rollback' => true,
        'event_assertions' => true,
        'coverage_threshold' => 80,

        'factories' => [
            'auto_discover' => true,
            'namespace' => 'Database\\Factories',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Security-related settings
    |
    */
    'security' => [
        'event_encryption' => [
            'enabled' => env('EVENT_ENCRYPTION_ENABLED', false),
            'key' => env('EVENT_ENCRYPTION_KEY'),
            'cipher' => 'AES-256-GCM',
        ],

        'audit_logging' => [
            'enabled' => env('AUDIT_LOGGING_ENABLED', true),
            'log_channel' => 'audit',
            'include_payloads' => false,
        ],

        'access_control' => [
            'enabled' => env('DDD_ACCESS_CONTROL', true),
            'default_policy' => 'deny',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Module Communication Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for inter-module communication
    |
    */
    'module_communication' => [
        'enabled' => env('MODULE_COMMUNICATION_ENABLED', true),
        'default_timeout' => env('MODULE_MESSAGE_TIMEOUT', 30), // seconds
        'default_retries' => env('MODULE_MESSAGE_RETRIES', 3),

        'async_processing' => [
            'enabled' => env('MODULE_ASYNC_ENABLED', true),
            'queue' => env('MODULE_COMMUNICATION_QUEUE', 'modules'),
            'message_timeout' => env('MODULE_MESSAGE_JOB_TIMEOUT', 300), // 5 minutes
            'event_timeout' => env('MODULE_EVENT_JOB_TIMEOUT', 120), // 2 minutes
        ],

        'routing' => [
            'strict_mode' => env('MODULE_ROUTING_STRICT', false),
            'allow_wildcards' => true,
            'log_undelivered' => true,
        ],

        'events' => [
            'enable_laravel_integration' => true,
            'enable_wildcard_subscribers' => true,
            'log_failures' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Development Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for development environment
    |
    */
    'development' => [
        'debug_mode' => env('DDD_DEBUG', env('APP_DEBUG', false)),
        'profiling' => env('DDD_PROFILING', false),
        'query_logging' => env('DDD_QUERY_LOGGING', false),

        'ide_support' => [
            'generate_helpers' => env('DDD_GENERATE_IDE_HELPERS', true),
            'phpstorm_meta' => true,
        ],

        'code_analysis' => [
            'phpstan_level' => 8,
            'auto_fix' => false,
            'custom_rules' => true,
        ],
    ],
];