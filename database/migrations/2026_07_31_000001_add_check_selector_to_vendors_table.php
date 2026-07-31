<?php

// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Where to look, for vendors that do not publish a version anywhere sane.
     *
     * The checker could read GitHub and Gitea. Everything on Packagist, npm or
     * the Go proxy — most of what an application actually depends on — fell
     * through to "unsupported source type" and was never checked. Those need
     * only registry and registry_id, which already exist.
     *
     * This column is for the two that need more: a JSON API, where it holds the
     * dot path the version sits at in the response, and a web page, where it
     * holds a CSS selector or a regular expression. Plenty of software
     * announces releases only on its own download page, and that is exactly the
     * kind of dependency nobody notices has gone stale.
     *
     * The table is uptelligence_vendors, not vendors — every table in this
     * package carries the prefix. A guard naming the wrong one returns early
     * and adds nothing, which is a migration that reports success and does
     * not run.
     */
    public function up(): void
    {
        if (! Schema::hasTable('uptelligence_vendors') || Schema::hasColumn('uptelligence_vendors', 'check_selector')) {
            return;
        }

        Schema::table('uptelligence_vendors', function (Blueprint $table): void {
            $table->string('check_selector')->nullable()->after('registry_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('uptelligence_vendors') || ! Schema::hasColumn('uptelligence_vendors', 'check_selector')) {
            return;
        }

        Schema::table('uptelligence_vendors', function (Blueprint $table): void {
            $table->dropColumn('check_selector');
        });
    }
};
