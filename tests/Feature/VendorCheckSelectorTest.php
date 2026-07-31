<?php

// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

use Core\Mod\Uptelligence\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds check_selector to the table the model actually uses', function () {
    // The first version of this migration guarded on `vendors`. Every table in
    // this package is prefixed, so the guard was false, the migration returned
    // early, and it reported success while adding nothing. Nothing caught it,
    // because the resolver tests never touch the column.
    expect((new Vendor())->getTable())->toBe('uptelligence_vendors')
        ->and(Schema::hasColumn('uptelligence_vendors', 'check_selector'))->toBeTrue();
});

it('stores a selector on a vendor', function () {
    $vendor = Vendor::query()->create([
        'slug' => 'acme',
        'name' => 'Acme',
        'source_type' => 'scrape',
        'url' => 'https://acme.example/download',
        'check_selector' => '.version',
    ]);

    expect($vendor->fresh()->check_selector)->toBe('.version');
});
