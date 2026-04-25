<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendVendors();
        $this->extendAssets();
        $this->extendAssetVersions();
        $this->extendVersionReleases();
        $this->extendAnalysisLogs();
        $this->extendDiffCache();
        $this->extendUpstreamTodos();
        $this->extendDigests();
        $this->extendWebhooks();
        $this->extendWebhookDeliveries();
    }

    public function down(): void
    {
        $this->dropColumns('uptelligence_webhook_deliveries', [
            'event',
            'response_code',
            'response_body',
            'retries',
            'delivered_at',
        ]);

        $this->dropColumns('uptelligence_webhooks', [
            'workspace_id',
            'url',
            'events',
            'active',
            'last_triggered_at',
        ]);

        $this->dropColumns('uptelligence_digests', [
            'recipient_email',
        ]);

        $this->dropColumns('uptelligence_upstream_todos', [
            'workspace_id',
            'asset_id',
            'analysis_log_id',
            'issue_url',
            'issue_platform',
            'estimated_effort_hours',
            'suggested_solution',
            'priority_label',
        ]);

        $this->dropColumns('uptelligence_diff_cache', [
            'asset_id',
            'from_version',
            'to_version',
            'diff_hash',
            'expires_at',
        ]);

        $this->dropColumns('uptelligence_analysis_logs', [
            'asset_id',
            'asset_version_id',
            'from_version',
            'to_version',
            'ai_model',
            'categories',
            'summary',
            'findings',
            'analyzed_at',
        ]);

        $this->dropColumns('uptelligence_version_releases', [
            'asset_version_id',
            'files_changed',
            'additions',
            'deletions',
            'release_notes',
            'published_at',
        ]);

        $this->dropColumns('uptelligence_asset_versions', [
            'changelog_url',
            'file_count',
            'total_size',
            'checksum',
            'notes',
            'storage_disk',
            's3_key',
            'archived_at',
        ]);

        $this->dropColumns('uptelligence_assets', [
            'vendor_id',
            'repository_url',
            'docs_url',
        ]);

        $this->dropColumns('uptelligence_vendors', [
            'workspace_id',
            'type',
            'url',
            'registry',
            'registry_id',
            'status',
        ]);
    }

    private function extendVendors(): void
    {
        if (! Schema::hasTable('uptelligence_vendors')) {
            return;
        }

        Schema::table('uptelligence_vendors', function (Blueprint $table): void {
            $this->addUnsignedBigInteger($table, 'workspace_id', 'id');
            $this->addString($table, 'type', 32, 'workspace_id');
            $this->addString($table, 'url', 512, 'type');
            $this->addString($table, 'registry', 32, 'url');
            $this->addString($table, 'registry_id', 256, 'registry');
            $this->addString($table, 'status', 32, 'registry_id', 'active');
        });
    }

    private function extendAssets(): void
    {
        if (! Schema::hasTable('uptelligence_assets')) {
            return;
        }

        Schema::table('uptelligence_assets', function (Blueprint $table): void {
            $this->addUnsignedBigInteger($table, 'vendor_id', 'id');
            $this->addString($table, 'repository_url', 512, 'latest_version');
            $this->addString($table, 'docs_url', 512, 'repository_url');
        });
    }

    private function extendAssetVersions(): void
    {
        if (! Schema::hasTable('uptelligence_asset_versions')) {
            return;
        }

        Schema::table('uptelligence_asset_versions', function (Blueprint $table): void {
            $this->addString($table, 'changelog_url', 512, 'released_at');
            $this->addUnsignedInteger($table, 'file_count', 'download_url');
            $this->addUnsignedBigInteger($table, 'total_size', 'file_count');
            $this->addString($table, 'checksum', 64, 'total_size');
            $this->addJson($table, 'notes', 'checksum');
            $this->addString($table, 'storage_disk', 32, 'local_path');
            $this->addString($table, 's3_key', 512, 'storage_disk');
            $this->addTimestamp($table, 'archived_at', 's3_key');
        });
    }

    private function extendVersionReleases(): void
    {
        if (! Schema::hasTable('uptelligence_version_releases')) {
            return;
        }

        Schema::table('uptelligence_version_releases', function (Blueprint $table): void {
            $this->addUnsignedBigInteger($table, 'asset_version_id', 'vendor_id');
            $this->addUnsignedInteger($table, 'files_changed', 'files_removed');
            $this->addUnsignedInteger($table, 'additions', 'files_changed');
            $this->addUnsignedInteger($table, 'deletions', 'additions');
            $this->addText($table, 'release_notes', 'deletions');
            $this->addTimestamp($table, 'published_at', 'release_notes');
        });
    }

    private function extendAnalysisLogs(): void
    {
        if (! Schema::hasTable('uptelligence_analysis_logs')) {
            return;
        }

        Schema::table('uptelligence_analysis_logs', function (Blueprint $table): void {
            $this->addUnsignedBigInteger($table, 'asset_id', 'vendor_id');
            $this->addUnsignedBigInteger($table, 'asset_version_id', 'asset_id');
            $this->addString($table, 'from_version', 64, 'version_release_id');
            $this->addString($table, 'to_version', 64, 'from_version');
            $this->addString($table, 'ai_model', 128, 'to_version');
            $this->addJson($table, 'categories', 'ai_model');
            $this->addText($table, 'summary', 'categories');
            $this->addJson($table, 'findings', 'summary');
            $this->addTimestamp($table, 'analyzed_at', 'findings');
        });
    }

    private function extendDiffCache(): void
    {
        if (! Schema::hasTable('uptelligence_diff_cache')) {
            return;
        }

        Schema::table('uptelligence_diff_cache', function (Blueprint $table): void {
            $this->addUnsignedBigInteger($table, 'asset_id', 'version_release_id');
            $this->addString($table, 'from_version', 64, 'asset_id');
            $this->addString($table, 'to_version', 64, 'from_version');
            $this->addString($table, 'diff_hash', 64, 'diff_content');
            $this->addTimestamp($table, 'expires_at', 'diff_hash');
        });
    }

    private function extendUpstreamTodos(): void
    {
        if (! Schema::hasTable('uptelligence_upstream_todos')) {
            return;
        }

        Schema::table('uptelligence_upstream_todos', function (Blueprint $table): void {
            $this->addUnsignedBigInteger($table, 'workspace_id', 'id');
            $this->addUnsignedBigInteger($table, 'asset_id', 'vendor_id');
            $this->addUnsignedBigInteger($table, 'analysis_log_id', 'asset_id');
            $this->addString($table, 'issue_url', 1024, 'github_issue_number');
            $this->addString($table, 'issue_platform', 32, 'issue_url');
            $this->addUnsignedSmallInteger($table, 'estimated_effort_hours', 'effort');
            $this->addJson($table, 'suggested_solution', 'estimated_effort_hours');
            $this->addString($table, 'priority_label', 16, 'priority');
        });
    }

    private function extendDigests(): void
    {
        if (! Schema::hasTable('uptelligence_digests')) {
            return;
        }

        Schema::table('uptelligence_digests', function (Blueprint $table): void {
            $this->addString($table, 'recipient_email', 255, 'workspace_id');
        });
    }

    private function extendWebhooks(): void
    {
        if (! Schema::hasTable('uptelligence_webhooks')) {
            return;
        }

        Schema::table('uptelligence_webhooks', function (Blueprint $table): void {
            $this->addUnsignedBigInteger($table, 'workspace_id', 'id');
            $this->addString($table, 'url', 2048, 'provider');
            $this->addJson($table, 'events', 'url');
            $this->addBoolean($table, 'active', 'events');
            $this->addTimestamp($table, 'last_triggered_at', 'last_received_at');
        });
    }

    private function extendWebhookDeliveries(): void
    {
        if (! Schema::hasTable('uptelligence_webhook_deliveries')) {
            return;
        }

        Schema::table('uptelligence_webhook_deliveries', function (Blueprint $table): void {
            $this->addString($table, 'event', 64, 'webhook_id');
            $this->addUnsignedSmallInteger($table, 'response_code', 'error_message');
            $this->addText($table, 'response_body', 'response_code');
            $this->addUnsignedTinyInteger($table, 'retries', 'retry_count');
            $this->addTimestamp($table, 'delivered_at', 'processed_at');
        });
    }

    private function addUnsignedBigInteger(Blueprint $table, string $column, string $after): void
    {
        if (! Schema::hasColumn($table->getTable(), $column)) {
            $table->unsignedBigInteger($column)->nullable()->index();
        }
    }

    private function addUnsignedInteger(Blueprint $table, string $column, string $after): void
    {
        if (! Schema::hasColumn($table->getTable(), $column)) {
            $table->unsignedInteger($column)->nullable();
        }
    }

    private function addUnsignedSmallInteger(Blueprint $table, string $column, string $after): void
    {
        if (! Schema::hasColumn($table->getTable(), $column)) {
            $table->unsignedSmallInteger($column)->nullable();
        }
    }

    private function addUnsignedTinyInteger(Blueprint $table, string $column, string $after): void
    {
        if (! Schema::hasColumn($table->getTable(), $column)) {
            $table->unsignedTinyInteger($column)->nullable();
        }
    }

    private function addString(Blueprint $table, string $column, int $length, string $after, ?string $default = null): void
    {
        if (! Schema::hasColumn($table->getTable(), $column)) {
            $definition = $table->string($column, $length)->nullable();

            if ($default !== null) {
                $definition->default($default);
            }
        }
    }

    private function addText(Blueprint $table, string $column, string $after): void
    {
        if (! Schema::hasColumn($table->getTable(), $column)) {
            $table->text($column)->nullable();
        }
    }

    private function addJson(Blueprint $table, string $column, string $after): void
    {
        if (! Schema::hasColumn($table->getTable(), $column)) {
            $table->json($column)->nullable();
        }
    }

    private function addBoolean(Blueprint $table, string $column, string $after): void
    {
        if (! Schema::hasColumn($table->getTable(), $column)) {
            $table->boolean($column)->default(true);
        }
    }

    private function addTimestamp(Blueprint $table, string $column, string $after): void
    {
        if (! Schema::hasColumn($table->getTable(), $column)) {
            $table->timestamp($column)->nullable();
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function dropColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $existing = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        if ($existing === []) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($existing): void {
            $table->dropColumn($existing);
        });
    }
};
