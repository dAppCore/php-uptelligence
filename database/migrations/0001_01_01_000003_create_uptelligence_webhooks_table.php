<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uptelligence webhooks tables - receive vendor release notifications.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        // 1. Webhook endpoints per vendor
        Schema::create('uptelligence_webhooks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('provider', 32); // github, gitlab, npm, packagist, custom
            $table->text('secret')->nullable(); // encrypted, for signature verification
            $table->text('previous_secret')->nullable(); // encrypted, for grace period
            $table->timestamp('secret_rotated_at')->nullable();
            $table->unsignedInteger('grace_period_seconds')->default(86400); // 24 hours
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('last_received_at')->nullable();
            $table->json('settings')->nullable(); // provider-specific settings
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vendor_id', 'is_active']);
            $table->index('provider');
        });

        // 2. Webhook delivery logs
        Schema::create('uptelligence_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained('uptelligence_webhooks')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('event_type', 64); // release.published, package.updated, etc.
            $table->string('provider', 32);
            $table->string('version')->nullable(); // extracted version
            $table->string('tag_name')->nullable(); // original tag name
            $table->json('payload'); // raw payload
            $table->json('parsed_data')->nullable(); // normalised release data
            $table->string('status', 32)->default('pending'); // pending, processing, completed, failed
            $table->text('error_message')->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->string('signature_status', 16)->nullable(); // valid, invalid, missing
            $table->timestamp('processed_at')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->unsignedTinyInteger('max_retries')->default(3);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            $table->index(['webhook_id', 'status']);
            $table->index(['vendor_id', 'created_at']);
            $table->index(['status', 'next_retry_at']);
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('uptelligence_webhook_deliveries');
        Schema::dropIfExists('uptelligence_webhooks');
        Schema::enableForeignKeyConstraints();
    }
};
