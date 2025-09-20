# Production Deployment Guide

This guide covers deploying the Laravel Modular DDD package in production environments, including monitoring, scaling, and operational best practices.

## Table of Contents

- [Production Requirements](#production-requirements)
- [Environment Configuration](#environment-configuration)
- [Database Setup](#database-setup)
- [Caching and Redis](#caching-and-redis)
- [Queue Configuration](#queue-configuration)
- [Health Monitoring](#health-monitoring)
- [Performance Monitoring](#performance-monitoring)
- [Scaling Strategies](#scaling-strategies)
- [Security Considerations](#security-considerations)
- [Backup and Recovery](#backup-and-recovery)
- [Troubleshooting](#troubleshooting)

## Production Requirements

### Minimum System Requirements

**Application Servers:**
- PHP 8.2+ with OPcache enabled
- Memory: 2GB+ per instance
- CPU: 2+ cores
- Storage: SSD recommended

**Database:**
- MySQL 8.0+ or PostgreSQL 13+
- Memory: 4GB+ for moderate loads
- IOPS: 3000+ for event store performance
- Backup solution with point-in-time recovery

**Redis:**
- Redis 6.0+ with persistence enabled
- Memory: Based on hot tier requirements
- High availability setup (Redis Sentinel/Cluster)

**Queue Workers:**
- Separate instances for queue processing
- Supervisor for process management
- Auto-scaling based on queue depth

### Infrastructure Architecture

```
┌───────────────────────────────────────────────────┐
│                    Load Balancer                       │
├───────────────────────────────────────────────────┤
│ App Server 1  │  App Server 2  │  App Server N        │
│ (Web + API)   │  (Web + API)   │  (Web + API)         │
├───────────────────────────────────────────────────┤
│ Queue Worker 1 │ Queue Worker 2 │ Queue Worker N      │
│ (Background)   │ (Background)   │ (Background)        │
├───────────────────────────────────────────────────┤
│     Redis Cluster      │     MySQL Cluster           │
│   (Hot Tier + Cache)    │   (Warm Tier + Read Models)    │
└───────────────────────────────────────────────────┘
```

## Environment Configuration

### Production Environment Variables

```env
# Application
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:your-production-key-here
LOG_LEVEL=warning
LOG_CHANNEL=stack

# Database - Write Master
DB_CONNECTION=mysql
DB_HOST=mysql-master.internal
DB_PORT=3306
DB_DATABASE=production_app
DB_USERNAME=app_user
DB_PASSWORD=secure-password

# Database - Read Replica
DB_READ_HOST=mysql-read.internal
DB_READ_DATABASE=production_app
DB_READ_USERNAME=read_user
DB_READ_PASSWORD=secure-read-password

# Redis Configuration
REDIS_HOST=redis-cluster.internal
REDIS_PASSWORD=secure-redis-password
REDIS_PORT=6379
REDIS_DB=0

# Queue Configuration
QUEUE_CONNECTION=redis
REDIS_QUEUE=production_queue

# Event Sourcing - Production Settings
EVENT_SOURCING_ENABLED=true
EVENT_STORE_HOT_ENABLED=true
EVENT_STORE_HOT_TTL=172800
EVENT_STORE_ASYNC_WARM=true
SNAPSHOT_STRATEGY=adaptive
SNAPSHOT_THRESHOLD=50
EVENT_STRICT_ORDERING=true

# CQRS - Production Settings
CQRS_DEFAULT_MODE=async
COMMISSION_TIMEOUT=30
QUERY_CACHE_ENABLED=true
QUERY_CACHE_TTL=1800
QUERY_L1_MAX_ENTRIES=5000
QUERY_MAX_MEMORY_MB=512

# Module Communication
MODULE_COMMUNICATION_ENABLED=true
MODULE_ASYNC_ENABLED=true
MODULE_MESSAGE_TIMEOUT=30
MODULE_MESSAGE_RETRIES=3

# Performance Monitoring
DDD_MONITORING_ENABLED=true
PERFORMANCE_COMMAND_THRESHOLD=200
PERFORMANCE_QUERY_THRESHOLD=100
PERFORMANCE_EVENT_THRESHOLD=50
PERFORMANCE_MEMORY_THRESHOLD=256

# Security
AUDIT_LOGGING_ENABLED=true
DDD_ACCESS_CONTROL=true
EVENT_ENCRYPTION_ENABLED=false

# External Services
MAIL_DRIVER=ses
AWS_ACCESS_KEY_ID=your-aws-key
AWS_SECRET_ACCESS_KEY=your-aws-secret
AWS_DEFAULT_REGION=us-east-1

# Monitoring and Alerting
SENTRY_LARAVEL_DSN=your-sentry-dsn
NEW_RELIC_LICENSE_KEY=your-newrelic-key
```

### Production Configuration Profile

```php
// config/modular-ddd.php - Production overrides
return [
    // Use enterprise performance profile
    'active_profile' => env('DDD_PROFILE', 'enterprise'),
    
    'event_sourcing' => [
        'storage_tiers' => [
            'hot' => [
                'ttl' => env('EVENT_STORE_HOT_TTL', 172800), // 48 hours
                'enabled' => env('EVENT_STORE_HOT_ENABLED', true),
            ],
        ],
        'snapshots' => [
            'strategy' => env('SNAPSHOT_STRATEGY', 'adaptive'),
            'retention' => [
                'keep_count' => 5,
                'max_age_days' => 90,
            ],
        ],
        'performance' => [
            'batch_size' => 500,
            'connection_pool_size' => 20,
            'query_timeout' => 30,
        ],
    ],
    
    'cqrs' => [
        'query_bus' => [
            'cache_ttl' => env('QUERY_CACHE_TTL', 3600),
            'memory_limits' => [
                'l1_max_entries' => env('QUERY_L1_MAX_ENTRIES', 5000),
                'max_memory_mb' => env('QUERY_MAX_MEMORY_MB', 512),
            ],
        ],
    ],
    
    'performance' => [
        'monitoring' => [
            'enabled' => env('DDD_MONITORING_ENABLED', true),
            'metrics_collector' => env('METRICS_COLLECTOR', 'redis'),
        ],
    ],
];
```

## Database Setup

### MySQL Production Configuration

```sql
-- my.cnf configuration for production
[mysqld]
# InnoDB settings for event sourcing
innodb_buffer_pool_size = 4G
innodb_log_file_size = 1G
innodb_flush_log_at_trx_commit = 1
innodb_file_per_table = 1
innodb_io_capacity = 2000

# Query performance
query_cache_type = 1
query_cache_size = 256M
max_connections = 500

# Binary logging for replication
log_bin = mysql-bin
binlog_format = ROW
expire_logs_days = 7

# Slow query logging
slow_query_log = 1
long_query_time = 2
log_queries_not_using_indexes = 1
```

### Database Indexes for Performance

```sql
-- Event store indexes
CREATE INDEX idx_event_store_sequence ON event_store (sequence_number);
CREATE INDEX idx_event_store_aggregate ON event_store (aggregate_id, version);
CREATE INDEX idx_event_store_type_sequence ON event_store (event_type, sequence_number);
CREATE INDEX idx_event_store_occurred_at ON event_store (occurred_at);

-- Snapshot indexes
CREATE INDEX idx_snapshots_aggregate ON snapshots (aggregate_id);
CREATE INDEX idx_snapshots_version ON snapshots (aggregate_id, version DESC);

-- Query cache indexes
CREATE INDEX idx_query_cache_key ON query_cache (cache_key);
CREATE INDEX idx_query_cache_expires ON query_cache (expires_at);

-- Performance monitoring indexes
CREATE INDEX idx_metrics_timestamp ON performance_metrics (timestamp);
CREATE INDEX idx_metrics_type ON performance_metrics (metric_type, timestamp);
```

### Read Replica Configuration

```php
// config/database.php
'mysql' => [
    'write' => [
        'host' => env('DB_HOST', '127.0.0.1'),
        'database' => env('DB_DATABASE', 'forge'),
        'username' => env('DB_USERNAME', 'forge'),
        'password' => env('DB_PASSWORD', ''),
    ],
    'read' => [
        'host' => [
            env('DB_READ_HOST_1', '127.0.0.1'),
            env('DB_READ_HOST_2', '127.0.0.1'),
        ],
        'database' => env('DB_READ_DATABASE', 'forge'),
        'username' => env('DB_READ_USERNAME', 'forge'),
        'password' => env('DB_READ_PASSWORD', ''),
    ],
    'driver' => 'mysql',
    'port' => env('DB_PORT', '3306'),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
    'engine' => 'InnoDB',
],
```

## Caching and Redis

### Redis Cluster Configuration

```yaml
# docker-compose.yml for Redis Cluster
version: '3.8'
services:
  redis-node-1:
    image: redis:7-alpine
    command: redis-server --cluster-enabled yes --cluster-config-file nodes.conf --cluster-node-timeout 5000 --appendonly yes
    ports:
      - "7001:6379"
    volumes:
      - redis-node-1-data:/data
      
  redis-node-2:
    image: redis:7-alpine
    command: redis-server --cluster-enabled yes --cluster-config-file nodes.conf --cluster-node-timeout 5000 --appendonly yes
    ports:
      - "7002:6379"
    volumes:
      - redis-node-2-data:/data
      
  redis-node-3:
    image: redis:7-alpine
    command: redis-server --cluster-enabled yes --cluster-config-file nodes.conf --cluster-node-timeout 5000 --appendonly yes
    ports:
      - "7003:6379"
    volumes:
      - redis-node-3-data:/data

volumes:
  redis-node-1-data:
  redis-node-2-data:
  redis-node-3-data:
```

### Redis Configuration for Production

```conf
# redis.conf production settings
maxmemory 4gb
maxmemory-policy allkeys-lru

# Persistence
save 900 1
save 300 10
save 60 10000

# AOF
appendonly yes
appendfsync everysec

# Security
requirepass your-redis-password

# Network
tcp-keepalive 300
timeout 0

# Memory optimization
hash-max-ziplist-entries 512
hash-max-ziplist-value 64
list-max-ziplist-size -2
set-max-intset-entries 512
```

## Queue Configuration

### Supervisor Configuration

```ini
; /etc/supervisor/conf.d/laravel-workers.conf
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/app/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autorestart=true
user=www-data
numprocs=8
redirect_stderr=true
stdout_logfile=/var/log/supervisor/laravel-worker.log
stopwaitsecs=3600

[program:laravel-scheduler]
process_name=%(program_name)s
command=php /path/to/your/app/artisan schedule:run
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/laravel-scheduler.log

[program:event-projections]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/app/artisan queue:work redis --queue=projections --sleep=1 --tries=5
autorestart=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/supervisor/projections.log

[program:module-communication]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/app/artisan queue:work redis --queue=modules --sleep=1 --tries=3
autorestart=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/supervisor/modules.log
```

### Queue Monitoring

```php
// Queue monitoring command
class MonitorQueues extends Command
{
    protected $signature = 'ddd:monitor-queues';
    
    public function handle(): void
    {
        $queues = ['default', 'projections', 'modules', 'commands'];
        
        foreach ($queues as $queue) {
            $size = Queue::size($queue);
            
            // Alert if queue is backed up
            if ($size > 1000) {
                $this->alertHighQueueDepth($queue, $size);
            }
            
            // Log metrics
            Log::info("Queue depth: {$queue}", ['size' => $size]);
        }
    }
    
    private function alertHighQueueDepth(string $queue, int $size): void
    {
        // Send alert to monitoring system
        app('monitoring')->alert([
            'type' => 'high_queue_depth',
            'queue' => $queue,
            'size' => $size,
            'threshold' => 1000,
        ]);
    }
}
```

## Health Monitoring

### Health Check Endpoints

The package provides comprehensive health check endpoints:

```bash
# Overall system health
curl http://your-app.com/health

# Component-specific health checks
curl http://your-app.com/health/database
curl http://your-app.com/health/event-store
curl http://your-app.com/health/cache
curl http://your-app.com/health/queues
curl http://your-app.com/health/modules
```

### Load Balancer Health Checks

```nginx
# nginx.conf
upstream app_servers {
    server app1.internal:80 max_fails=3 fail_timeout=30s;
    server app2.internal:80 max_fails=3 fail_timeout=30s;
    server app3.internal:80 max_fails=3 fail_timeout=30s;
}

server {
    listen 80;
    
    location /health {
        proxy_pass http://app_servers/health;
        proxy_set_header Host $host;
        proxy_connect_timeout 5s;
        proxy_read_timeout 10s;
    }
    
    location / {
        proxy_pass http://app_servers;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }
}
```

### Kubernetes Health Checks

```yaml
# deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: laravel-ddd-app
spec:
  replicas: 3
  template:
    spec:
      containers:
      - name: app
        image: your-app:latest
        livenessProbe:
          httpGet:
            path: /health
            port: 8080
          initialDelaySeconds: 30
          periodSeconds: 10
          timeoutSeconds: 5
          failureThreshold: 3
        readinessProbe:
          httpGet:
            path: /health
            port: 8080
          initialDelaySeconds: 5
          periodSeconds: 5
          timeoutSeconds: 3
          failureThreshold: 2
```

## Performance Monitoring

### Metrics Collection

```php
// Custom metrics collector
class ProductionMetricsCollector implements MetricsCollectorInterface
{
    public function recordCommandExecution(
        string $commandClass,
        float $durationMs,
        bool $success
    ): void {
        // Send to time-series database
        $this->influxdb->write([
            'measurement' => 'command_execution',
            'tags' => [
                'command' => $commandClass,
                'success' => $success ? 'true' : 'false',
            ],
            'fields' => [
                'duration_ms' => $durationMs,
            ],
            'timestamp' => time() * 1000000000, // nanoseconds
        ]);
        
        // Send to application monitoring
        if (app()->bound('newrelic')) {
            newrelic_custom_metric('Custom/Command/Duration', $durationMs);
            newrelic_add_custom_parameter('command_class', $commandClass);
        }
    }
    
    public function recordQueryExecution(
        string $queryClass,
        float $durationMs,
        bool $cacheHit
    ): void {
        $this->influxdb->write([
            'measurement' => 'query_execution',
            'tags' => [
                'query' => $queryClass,
                'cache_hit' => $cacheHit ? 'true' : 'false',
            ],
            'fields' => [
                'duration_ms' => $durationMs,
            ],
        ]);
    }
}
```

### Dashboard Configuration

```json
{
  "dashboard": {
    "title": "Laravel DDD Monitoring",
    "panels": [
      {
        "title": "Command Execution Time",
        "type": "graph",
        "targets": [
          {
            "query": "SELECT mean(\"duration_ms\") FROM \"command_execution\" WHERE time >= now() - 1h GROUP BY time(1m), \"command\""
          }
        ]
      },
      {
        "title": "Query Cache Hit Rate",
        "type": "stat",
        "targets": [
          {
            "query": "SELECT (sum(cache_hit_count) / sum(total_queries)) * 100 FROM \"query_metrics\" WHERE time >= now() - 1h"
          }
        ]
      },
      {
        "title": "Event Store Performance",
        "type": "graph",
        "targets": [
          {
            "query": "SELECT mean(\"append_duration_ms\"), mean(\"load_duration_ms\") FROM \"event_store_metrics\" WHERE time >= now() - 1h GROUP BY time(1m)"
          }
        ]
      },
      {
        "title": "Queue Depth",
        "type": "graph",
        "targets": [
          {
            "query": "SELECT last(\"queue_size\") FROM \"queue_metrics\" WHERE time >= now() - 1h GROUP BY time(1m), \"queue_name\""
          }
        ]
      }
    ]
  }
}
```

### Alerting Rules

```yaml
# alertmanager.yml
groups:
- name: laravel-ddd
  rules:
  - alert: HighCommandLatency
    expr: rate(command_duration_seconds_sum[5m]) / rate(command_duration_seconds_count[5m]) > 1
    for: 2m
    labels:
      severity: warning
    annotations:
      summary: "High command execution latency"
      description: "Command execution latency is above 1 second for 2 minutes"
      
  - alert: LowCacheHitRate
    expr: (cache_hits / (cache_hits + cache_misses)) * 100 < 80
    for: 5m
    labels:
      severity: warning
    annotations:
      summary: "Low cache hit rate"
      description: "Cache hit rate is below 80% for 5 minutes"
      
  - alert: HighQueueDepth
    expr: queue_depth > 1000
    for: 1m
    labels:
      severity: critical
    annotations:
      summary: "High queue depth"
      description: "Queue depth is above 1000 jobs"
      
  - alert: EventStoreError
    expr: increase(event_store_errors_total[5m]) > 0
    for: 0m
    labels:
      severity: critical
    annotations:
      summary: "Event store errors detected"
      description: "Errors detected in event store operations"
```

## Scaling Strategies

### Horizontal Scaling

**Application Servers:**
```yaml
# Auto-scaling configuration
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: laravel-ddd-hpa
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: laravel-ddd-app
  minReplicas: 3
  maxReplicas: 20
  metrics:
  - type: Resource
    resource:
      name: cpu
      target:
        type: Utilization
        averageUtilization: 70
  - type: Resource
    resource:
      name: memory
      target:
        type: Utilization
        averageUtilization: 80
```

**Queue Workers:**
```bash
#!/bin/bash
# Auto-scale queue workers based on depth

while true; do
    QUEUE_DEPTH=$(redis-cli -h redis.internal llen "queues:default")
    CURRENT_WORKERS=$(pgrep -f "queue:work" | wc -l)
    
    if [ $QUEUE_DEPTH -gt 500 ] && [ $CURRENT_WORKERS -lt 20 ]; then
        echo "Scaling up workers: depth=$QUEUE_DEPTH, workers=$CURRENT_WORKERS"
        supervisorctl start "laravel-worker:*"
    elif [ $QUEUE_DEPTH -lt 100 ] && [ $CURRENT_WORKERS -gt 5 ]; then
        echo "Scaling down workers: depth=$QUEUE_DEPTH, workers=$CURRENT_WORKERS"
        supervisorctl stop "laravel-worker:*"
    fi
    
    sleep 30
done
```

### Database Scaling

**Read Replicas:**
```php
// Automatic read/write splitting
class DatabaseManager extends \Illuminate\Database\DatabaseManager
{
    public function connection($name = null)
    {
        $connection = parent::connection($name);
        
        // Route reads to replicas during high load
        if ($this->isHighLoad() && $this->isReadOperation()) {
            return $this->getReadConnection($name);
        }
        
        return $connection;
    }
    
    private function isHighLoad(): bool
    {
        return app('load-monitor')->getCurrentLoad() > 0.8;
    }
}
```

**Database Sharding:**
```php
// Event store sharding by aggregate type
class ShardedEventStore implements EventStoreInterface
{
    public function getShardForAggregate(AggregateIdInterface $aggregateId): string
    {
        $hash = crc32($aggregateId->toString());
        $shardIndex = $hash % $this->shardCount;
        
        return "shard_{$shardIndex}";
    }
    
    public function append(
        AggregateIdInterface $aggregateId,
        array $events,
        ?int $expectedVersion = null
    ): void {
        $shard = $this->getShardForAggregate($aggregateId);
        $connection = $this->getShardConnection($shard);
        
        $connection->append($aggregateId, $events, $expectedVersion);
    }
}
```

## Security Considerations

### Application Security

```php
// Production security middleware
class ProductionSecurityMiddleware
{
    public function handle($request, Closure $next)
    {
        // Rate limiting
        if (!$this->checkRateLimit($request)) {
            throw new TooManyRequestsException();
        }
        
        // IP whitelisting for admin endpoints
        if ($this->isAdminEndpoint($request) && !$this->isWhitelistedIP($request)) {
            throw new ForbiddenException();
        }
        
        // Command validation
        if ($this->isCommandEndpoint($request)) {
            $this->validateCommandSecurity($request);
        }
        
        return $next($request);
    }
}
```

### Event Store Security

```php
// Encrypted event store for sensitive data
class EncryptedEventStore implements EventStoreInterface
{
    public function append(
        AggregateIdInterface $aggregateId,
        array $events,
        ?int $expectedVersion = null
    ): void {
        $encryptedEvents = array_map(
            fn($event) => $this->encryptSensitiveData($event),
            $events
        );
        
        $this->baseStore->append($aggregateId, $encryptedEvents, $expectedVersion);
    }
    
    private function encryptSensitiveData(DomainEventInterface $event): DomainEventInterface
    {
        if (!$this->containsSensitiveData($event)) {
            return $event;
        }
        
        // Encrypt sensitive fields
        $reflection = new \ReflectionClass($event);
        // ... encryption logic
        
        return $encryptedEvent;
    }
}
```

## Backup and Recovery

### Database Backup Strategy

```bash
#!/bin/bash
# backup-event-store.sh

DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/backups/event-store"
DATABASE="production_app"

# Full backup (daily)
mysqldump --single-transaction --routines --triggers \
  --databases $DATABASE > $BACKUP_DIR/full_backup_$DATE.sql

# Compress backup
gzip $BACKUP_DIR/full_backup_$DATE.sql

# Upload to S3
aws s3 cp $BACKUP_DIR/full_backup_$DATE.sql.gz \
  s3://your-backup-bucket/event-store/

# Cleanup old backups (keep 30 days)
find $BACKUP_DIR -name "full_backup_*.sql.gz" -mtime +30 -delete

# Point-in-time recovery setup
mysql -e "FLUSH LOGS;"
```

### Event Store Snapshot Backup

```php
class SnapshotBackupService
{
    public function backupSnapshots(): void
    {
        $snapshots = DB::table('snapshots')
            ->where('created_at', '>', now()->subDays(30))
            ->get();
        
        $backupData = [
            'timestamp' => now()->toISOString(),
            'version' => '1.0',
            'snapshots' => $snapshots->toArray(),
        ];
        
        $filename = 'snapshots_backup_' . now()->format('Y_m_d_H_i_s') . '.json';
        $path = storage_path('backups/' . $filename);
        
        file_put_contents($path, json_encode($backupData, JSON_PRETTY_PRINT));
        
        // Upload to cloud storage
        Storage::disk('s3')->put('backups/snapshots/' . $filename, file_get_contents($path));
        
        // Cleanup local file
        unlink($path);
    }
}
```

### Disaster Recovery

```bash
#!/bin/bash
# disaster-recovery.sh

# 1. Restore database from backup
mysql $DATABASE < /backups/latest_full_backup.sql

# 2. Apply binary logs for point-in-time recovery
mysqlbinlog --start-datetime="2024-01-01 10:00:00" \
             --stop-datetime="2024-01-01 14:00:00" \
             /var/log/mysql/mysql-bin.000001 | mysql $DATABASE

# 3. Rebuild projections
php artisan ddd:rebuild-projections --all

# 4. Verify data integrity
php artisan ddd:verify-event-store

# 5. Start application services
sudo systemctl start nginx
sudo systemctl start php-fpm
sudo supervisorctl start all
```

## Troubleshooting

### Common Performance Issues

**High Command Latency:**
```bash
# Check database performance
SHOW PROCESSLIST;
SHOW ENGINE INNODB STATUS;

# Check slow query log
tail -f /var/log/mysql/slow.log

# Check event store fragmentation
SELECT table_name, data_free FROM information_schema.tables 
WHERE table_schema = 'production_app' AND data_free > 0;
```

**Cache Issues:**
```bash
# Check Redis memory usage
redis-cli INFO memory

# Check cache hit rates
redis-cli INFO stats | grep keyspace

# Monitor cache operations
redis-cli MONITOR
```

**Queue Backup:**
```bash
# Check queue sizes
redis-cli LLEN "queues:default"
redis-cli LLEN "queues:projections"

# Check failed jobs
php artisan queue:failed

# Restart workers
sudo supervisorctl restart laravel-worker:*
```

### Debugging Commands

```bash
# System health check
php artisan ddd:health --verbose

# Performance analysis
php artisan ddd:performance --profile=production

# Event store verification
php artisan ddd:verify-event-store --aggregate-id=user-123

# Cache analysis
php artisan ddd:cache-stats --detailed

# Queue monitoring
php artisan ddd:queue-status --all
```

### Log Analysis

```bash
# Monitor application logs
tail -f storage/logs/laravel.log | grep -E "(ERROR|CRITICAL|WARNING)"

# Monitor command execution
tail -f storage/logs/laravel.log | grep "Command execution"

# Monitor slow queries
tail -f storage/logs/laravel.log | grep "Slow query"

# Monitor circuit breaker events
tail -f storage/logs/laravel.log | grep "Circuit breaker"
```

This production deployment guide provides comprehensive coverage of deploying and operating the Laravel Modular DDD package in production environments. The next sections will cover API reference documentation and performance optimization strategies.
