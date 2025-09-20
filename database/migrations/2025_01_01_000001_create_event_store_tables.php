<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create main event store table
        Schema::create('event_store', function (Blueprint $table) {
            $table->bigIncrements('sequence_number')->comment('Global sequence number for ordering');
            $table->uuid('aggregate_id')->comment('Aggregate identifier');
            $table->string('aggregate_type', 150)->comment('Type of aggregate');
            $table->string('event_type', 150)->comment('Full class name of the event');
            $table->integer('event_version')->default(1)->comment('Event schema version');
            $table->json('event_data')->comment('Event payload data');
            $table->json('metadata')->nullable()->comment('Event metadata');
            $table->integer('version')->comment('Aggregate version');
            $table->timestamp('occurred_at', 6)->comment('When the event occurred');
            $table->timestamps();

            // Indexes for performance
            $table->index(['aggregate_id', 'version'], 'idx_aggregate_version');
            $table->index(['aggregate_type', 'occurred_at'], 'idx_type_time');
            $table->index(['event_type', 'occurred_at'], 'idx_event_type_time');
            $table->index('occurred_at', 'idx_occurred_at');

            // Unique constraint to prevent duplicate versions for same aggregate
            $table->unique(['aggregate_id', 'version'], 'uk_aggregate_version');
        });

        // Create snapshots table
        Schema::create('snapshots', function (Blueprint $table) {
            $table->uuid('aggregate_id')->comment('Aggregate identifier');
            $table->string('aggregate_type', 150)->comment('Type of aggregate');
            $table->integer('version')->comment('Aggregate version at snapshot');
            $table->json('state')->comment('Serialized aggregate state');
            $table->string('hash', 64)->comment('Hash for integrity verification');
            $table->timestamp('created_at', 6)->comment('When snapshot was created');

            $table->primary(['aggregate_id', 'version']);
            $table->index(['aggregate_type', 'created_at'], 'idx_type_created');
            $table->index('created_at', 'idx_created_at');
        });

        // Create projections tracking table
        Schema::create('projections', function (Blueprint $table) {
            $table->string('projection_name', 150)->primary()->comment('Name of the projection');
            $table->bigInteger('last_processed_sequence')->default(0)->comment('Last processed event sequence');
            $table->json('state')->nullable()->comment('Projection state data');
            $table->boolean('locked')->default(false)->comment('Lock for processing');
            $table->timestamp('locked_until')->nullable()->comment('Lock expiration');
            $table->timestamp('updated_at')->comment('Last update time');
        });

        // Add partitioning for event_store table (MySQL 8.0+)
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->addMySQLPartitioning();
        }

        // Add PostgreSQL specific optimizations
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->addPostgreSQLOptimizations();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('projections');
        Schema::dropIfExists('snapshots');
        Schema::dropIfExists('event_store');
    }

    private function addMySQLPartitioning(): void
    {
        try {
            // Check MySQL version
            $version = DB::select('SELECT VERSION() as version')[0]->version;
            if (version_compare($version, '8.0', '>=')) {
                DB::statement("
                    ALTER TABLE event_store
                    PARTITION BY RANGE (YEAR(occurred_at)) (
                        PARTITION p2025 VALUES LESS THAN (2026),
                        PARTITION p2026 VALUES LESS THAN (2027),
                        PARTITION p2027 VALUES LESS THAN (2028),
                        PARTITION p2028 VALUES LESS THAN (2029),
                        PARTITION p2029 VALUES LESS THAN (2030),
                        PARTITION pmax VALUES LESS THAN MAXVALUE
                    )
                ");
            }
        } catch (\Exception $e) {
            // Partitioning failed, but table is still usable
            // Log warning but don't fail migration
            error_log("Warning: Could not add partitioning to event_store table: " . $e->getMessage());
        }
    }

    private function addPostgreSQLOptimizations(): void
    {
        try {
            // Add PostgreSQL specific indexes
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_event_store_gin_metadata ON event_store USING gin (metadata)");
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_event_store_gin_event_data ON event_store USING gin (event_data)");

            // Add table partitioning for PostgreSQL
            DB::statement("
                CREATE TABLE IF NOT EXISTS event_store_y2025 PARTITION OF event_store
                FOR VALUES FROM ('2025-01-01') TO ('2026-01-01')
            ");
        } catch (\Exception $e) {
            // PostgreSQL optimizations failed, but table is still usable
            error_log("Warning: Could not add PostgreSQL optimizations: " . $e->getMessage());
        }
    }
};