<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uptelligence module tables - uptime monitoring.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        // 1. Monitors
        Schema::create('uptelligence_monitors', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 32)->default('http');
            $table->string('url', 2048);
            $table->string('method', 10)->default('GET');
            $table->json('headers')->nullable();
            $table->text('body')->nullable();
            $table->json('expected_response')->nullable();
            $table->unsignedSmallInteger('interval_seconds')->default(300);
            $table->unsignedSmallInteger('timeout_seconds')->default(30);
            $table->unsignedTinyInteger('retries')->default(3);
            $table->string('status', 32)->default('active');
            $table->string('current_status', 32)->default('unknown');
            $table->decimal('uptime_percentage', 5, 2)->default(100);
            $table->unsignedInteger('avg_response_ms')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_up_at')->nullable();
            $table->timestamp('last_down_at')->nullable();
            $table->json('notification_channels')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'status']);
            $table->index(['status', 'current_status']);
            $table->index('last_checked_at');
        });

        // 2. Monitor Checks
        Schema::create('uptelligence_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('uptelligence_monitors')->cascadeOnDelete();
            $table->string('status', 32);
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('response_headers')->nullable();
            $table->text('response_body')->nullable();
            $table->string('checked_from', 64)->nullable();
            $table->timestamp('created_at');

            $table->index(['monitor_id', 'created_at']);
            $table->index(['monitor_id', 'status']);
        });

        // 3. Monitor Incidents
        Schema::create('uptelligence_incidents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('monitor_id')->constrained('uptelligence_monitors')->cascadeOnDelete();
            $table->string('status', 32)->default('ongoing');
            $table->text('cause')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('checks_failed')->default(1);
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['monitor_id', 'status']);
            $table->index(['status', 'started_at']);
        });

        // 4. Monitor Daily Stats
        Schema::create('uptelligence_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('uptelligence_monitors')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('checks_total')->default(0);
            $table->unsignedInteger('checks_up')->default(0);
            $table->unsignedInteger('checks_down')->default(0);
            $table->decimal('uptime_percentage', 5, 2)->default(100);
            $table->unsignedInteger('avg_response_ms')->nullable();
            $table->unsignedInteger('min_response_ms')->nullable();
            $table->unsignedInteger('max_response_ms')->nullable();
            $table->unsignedInteger('incidents_count')->default(0);
            $table->unsignedInteger('total_downtime_seconds')->default(0);
            $table->timestamps();

            $table->unique(['monitor_id', 'date']);
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('uptelligence_daily_stats');
        Schema::dropIfExists('uptelligence_incidents');
        Schema::dropIfExists('uptelligence_checks');
        Schema::dropIfExists('uptelligence_monitors');
        Schema::enableForeignKeyConstraints();
    }
};
