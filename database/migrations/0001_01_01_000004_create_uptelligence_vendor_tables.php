<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for Uptelligence vendor tracking tables.
 *
 * This migration creates tables for tracking upstream software vendors,
 * version releases, todos, diffs, analysis logs, and assets.
 *
 * Note: The uptelligence_monitors/checks/incidents/daily_stats tables
 * (in migration 000001) are for uptime monitoring, which is a separate
 * concern. This package serves dual purposes:
 * - Uptime monitoring (for server health tracking)
 * - Vendor tracking (for upstream dependency intelligence)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        // 1. Vendors - upstream software sources to track
        if (! Schema::hasTable('uptelligence_vendors')) {
            Schema::create('uptelligence_vendors', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 64)->unique();
                $table->string('name');
                $table->string('vendor_name')->nullable();
                $table->string('source_type', 32)->default('oss'); // licensed, oss, plugin
                $table->string('plugin_platform', 32)->nullable(); // altum, wordpress, laravel, other
                $table->string('git_repo_url', 512)->nullable();
                $table->string('current_version', 64)->nullable();
                $table->string('previous_version', 64)->nullable();
                $table->json('path_mapping')->nullable();
                $table->json('ignored_paths')->nullable();
                $table->json('priority_paths')->nullable();
                $table->string('target_repo', 256)->nullable(); // owner/repo for issue creation
                $table->string('target_branch', 64)->default('main');
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamp('last_analyzed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['is_active', 'source_type']);
                $table->index('last_checked_at');
            });
        }

        // 2. Version Releases - tracked releases from vendors
        if (! Schema::hasTable('uptelligence_version_releases')) {
            Schema::create('uptelligence_version_releases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vendor_id')->constrained('uptelligence_vendors')->cascadeOnDelete();
                $table->string('version', 64);
                $table->string('previous_version', 64)->nullable();
                $table->string('status', 32)->default('pending'); // pending, analyzed, skipped
                $table->json('metadata_json')->nullable();
                $table->json('summary')->nullable();
                $table->unsignedInteger('files_changed')->default(0);
                $table->unsignedInteger('todos_created')->default(0);
                $table->timestamp('released_at')->nullable();
                $table->timestamp('analyzed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['vendor_id', 'version']);
                $table->index(['vendor_id', 'status']);
                $table->index('released_at');
            });
        }

        // 3. Upstream Todos - porting tasks from vendor changes
        if (! Schema::hasTable('uptelligence_upstream_todos')) {
            Schema::create('uptelligence_upstream_todos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vendor_id')->constrained('uptelligence_vendors')->cascadeOnDelete();
                $table->string('from_version', 64)->nullable();
                $table->string('to_version', 64)->nullable();
                $table->string('type', 32)->default('feature'); // feature, bugfix, security, ui, block, api, refactor, dependency
                $table->string('status', 32)->default('pending'); // pending, in_progress, completed, skipped
                $table->string('title');
                $table->text('description')->nullable();
                $table->text('port_notes')->nullable();
                $table->unsignedTinyInteger('priority')->default(5); // 1-10
                $table->string('effort', 16)->default('medium'); // low, medium, high
                $table->boolean('has_conflicts')->default(false);
                $table->text('conflict_reason')->nullable();
                $table->json('files')->nullable();
                $table->json('dependencies')->nullable();
                $table->json('tags')->nullable();
                $table->json('ai_analysis')->nullable();
                $table->decimal('ai_confidence', 3, 2)->nullable();
                $table->string('github_issue_number', 32)->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['vendor_id', 'status']);
                $table->index(['status', 'priority']);
                $table->index('type');
            });
        }

        // 4. Diff Cache - cached diffs between versions
        if (! Schema::hasTable('uptelligence_diff_cache')) {
            Schema::create('uptelligence_diff_cache', function (Blueprint $table) {
                $table->id();
                $table->foreignId('version_release_id')->constrained('uptelligence_version_releases')->cascadeOnDelete();
                $table->string('file_path', 512);
                $table->string('change_type', 16); // added, modified, deleted, renamed
                $table->string('category', 32)->nullable(); // controller, model, view, migration, config, etc.
                $table->mediumText('diff_content')->nullable();
                $table->unsignedInteger('lines_added')->default(0);
                $table->unsignedInteger('lines_removed')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index('version_release_id');
                $table->index('change_type');
                $table->index('category');
            });
        }

        // 5. Analysis Logs - audit trail for analysis operations
        if (! Schema::hasTable('uptelligence_analysis_logs')) {
            Schema::create('uptelligence_analysis_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vendor_id')->nullable()->constrained('uptelligence_vendors')->nullOnDelete();
                $table->foreignId('todo_id')->nullable()->constrained('uptelligence_upstream_todos')->nullOnDelete();
                $table->string('action', 64);
                $table->string('status', 32)->default('success');
                $table->json('context')->nullable();
                $table->text('message')->nullable();
                $table->timestamps();

                $table->index(['vendor_id', 'created_at']);
                $table->index('action');
            });
        }

        // 6. Assets - tracked software assets (Composer/NPM packages)
        if (! Schema::hasTable('uptelligence_assets')) {
            Schema::create('uptelligence_assets', function (Blueprint $table) {
                $table->id();
                $table->string('package_name');
                $table->string('type', 16); // composer, npm
                $table->string('current_version', 64)->nullable();
                $table->string('latest_version', 64)->nullable();
                $table->string('registry_url', 512)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_checked_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['package_name', 'type']);
                $table->index(['type', 'is_active']);
            });
        }

        // 7. Asset Versions - version history for assets
        if (! Schema::hasTable('uptelligence_asset_versions')) {
            Schema::create('uptelligence_asset_versions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('asset_id')->constrained('uptelligence_assets')->cascadeOnDelete();
                $table->string('version', 64);
                $table->timestamp('released_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['asset_id', 'version']);
                $table->index(['asset_id', 'released_at']);
            });
        }

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('uptelligence_asset_versions');
        Schema::dropIfExists('uptelligence_assets');
        Schema::dropIfExists('uptelligence_analysis_logs');
        Schema::dropIfExists('uptelligence_diff_cache');
        Schema::dropIfExists('uptelligence_upstream_todos');
        Schema::dropIfExists('uptelligence_version_releases');
        Schema::dropIfExists('uptelligence_vendors');
        Schema::enableForeignKeyConstraints();
    }
};
