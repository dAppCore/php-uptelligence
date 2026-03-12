<?php

declare(strict_types=1);

use Core\Mod\Uptelligence\Database\Seeders\AltumCodeVendorSeeder;
use Core\Mod\Uptelligence\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds 4 altum products', function () {
    $seeder = new AltumCodeVendorSeeder;
    $seeder->run();

    $products = Vendor::where('source_type', Vendor::SOURCE_LICENSED)
        ->where('plugin_platform', Vendor::PLATFORM_ALTUM)
        ->get();

    expect($products)->toHaveCount(4)
        ->and($products->pluck('slug')->sort()->values()->all())->toBe([
            '66analytics',
            '66biolinks',
            '66pusher',
            '66socialproof',
        ]);

    $products->each(function (Vendor $vendor) {
        expect($vendor->vendor_name)->toBe('AltumCode')
            ->and($vendor->is_active)->toBeTrue()
            ->and($vendor->current_version)->toBe('0.0.0');
    });
});

it('seeds 13 altum plugins', function () {
    $seeder = new AltumCodeVendorSeeder;
    $seeder->run();

    $plugins = Vendor::where('source_type', Vendor::SOURCE_PLUGIN)
        ->where('plugin_platform', Vendor::PLATFORM_ALTUM)
        ->get();

    expect($plugins)->toHaveCount(13);

    $plugins->each(function (Vendor $vendor) {
        expect($vendor->vendor_name)->toBe('AltumCode')
            ->and($vendor->is_active)->toBeTrue()
            ->and($vendor->current_version)->toBe('0.0.0')
            ->and($vendor->slug)->toStartWith('altum-plugin-');
    });
});

it('is idempotent — running twice still yields 17 total', function () {
    $seeder = new AltumCodeVendorSeeder;

    $seeder->run();
    expect(Vendor::count())->toBe(17);

    $seeder->run();
    expect(Vendor::count())->toBe(17);
});
