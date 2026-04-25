<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('patterns')) {
            Schema::create('patterns', function (Blueprint $table): void {
                $table->id();
                $table->string('slug', 128)->nullable()->unique();
                $table->string('name');
                $table->string('category', 32)->default('feature');
                $table->text('description')->nullable();
                $table->string('severity', 16)->default('medium');
                $table->json('detection_rule')->nullable();
                $table->json('tags')->nullable();
                $table->string('language', 32)->nullable();
                $table->longText('code')->nullable();
                $table->text('usage_example')->nullable();
                $table->json('required_assets')->nullable();
                $table->string('source_url', 512)->nullable();
                $table->string('source_type', 32)->nullable();
                $table->string('author')->nullable();
                $table->unsignedInteger('usage_count')->default(0);
                $table->decimal('quality_score', 3, 2)->nullable();
                $table->boolean('is_vetted')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['category', 'severity']);
                $table->index('is_active');
            });
        }

        if (! Schema::hasTable('pattern_variants')) {
            Schema::create('pattern_variants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('pattern_id')->nullable()->constrained('patterns')->nullOnDelete();
                $table->unsignedBigInteger('asset_id')->nullable()->index();
                $table->string('name')->nullable();
                $table->longText('code')->nullable();
                $table->text('notes')->nullable();
                $table->string('from_version', 64)->nullable();
                $table->string('to_version', 64)->nullable();
                $table->string('file_path', 512)->nullable();
                $table->string('line_range', 64)->nullable();
                $table->text('context')->nullable();
                $table->timestamp('found_at')->nullable();
                $table->timestamps();

                $table->index(['pattern_id', 'asset_id']);
                $table->index(['from_version', 'to_version']);
            });
        }

        if (! Schema::hasTable('pattern_collections')) {
            Schema::create('pattern_collections', function (Blueprint $table): void {
                $table->id();
                $table->string('slug', 128)->nullable()->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->json('patterns')->nullable();
                $table->json('pattern_ids')->nullable();
                $table->json('required_assets')->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pattern_collections');
        Schema::dropIfExists('pattern_variants');
        Schema::dropIfExists('patterns');
    }
};
