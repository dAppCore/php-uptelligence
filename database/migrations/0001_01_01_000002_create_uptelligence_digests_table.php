<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the uptelligence_digests table for email digest preferences.
     */
    public function up(): void
    {
        Schema::create('uptelligence_digests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('frequency', 16)->default('weekly'); // daily, weekly, monthly
            $table->timestamp('last_sent_at')->nullable();
            $table->json('preferences')->nullable(); // vendor filters, update types, etc.
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            // Each user can only have one digest preference per workspace
            $table->unique(['user_id', 'workspace_id']);

            // Index for finding users due for digest
            $table->index(['is_enabled', 'frequency', 'last_sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uptelligence_digests');
    }
};
