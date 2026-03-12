<?php

declare(strict_types=1);

use Core\Mod\Uptelligence\Models\Vendor;
use Core\Mod\Uptelligence\Services\VendorUpdateCheckerService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->service = new VendorUpdateCheckerService;
});

// ---------------------------------------------------------------------------
// isAltumPlatform
// ---------------------------------------------------------------------------

it('identifies altum platform vendors', function () {
    $vendor = new Vendor([
        'slug' => '66analytics',
        'plugin_platform' => Vendor::PLATFORM_ALTUM,
    ]);

    $method = new ReflectionMethod($this->service, 'isAltumPlatform');
    $method->setAccessible(true);

    expect($method->invoke($this->service, $vendor))->toBeTrue();
});

it('rejects non-altum platform vendors', function () {
    $vendor = new Vendor([
        'slug' => 'some-wp-plugin',
        'plugin_platform' => Vendor::PLATFORM_WORDPRESS,
    ]);

    $method = new ReflectionMethod($this->service, 'isAltumPlatform');
    $method->setAccessible(true);

    expect($method->invoke($this->service, $vendor))->toBeFalse();
});

it('rejects vendors with no platform set', function () {
    $vendor = new Vendor([
        'slug' => 'some-oss',
        'plugin_platform' => null,
    ]);

    $method = new ReflectionMethod($this->service, 'isAltumPlatform');
    $method->setAccessible(true);

    expect($method->invoke($this->service, $vendor))->toBeFalse();
});

// ---------------------------------------------------------------------------
// checkAltumProduct
// ---------------------------------------------------------------------------

it('fetches latest version from altum product info endpoint', function () {
    Http::fake([
        'https://66analytics.com/info.php' => Http::response([
            'latest_release_version' => '42.0.0',
            'product_name' => '66analytics',
        ], 200),
    ]);

    $vendor = new Vendor([
        'slug' => '66analytics',
        'name' => '66analytics',
        'plugin_platform' => Vendor::PLATFORM_ALTUM,
        'source_type' => Vendor::SOURCE_LICENSED,
        'current_version' => '41.0.0',
    ]);

    $method = new ReflectionMethod($this->service, 'checkAltumProduct');
    $method->setAccessible(true);

    $result = $method->invoke($this->service, $vendor);

    expect($result)
        ->toBeArray()
        ->and($result['status'])->toBe('success')
        ->and($result['latest'])->toBe('42.0.0')
        ->and($result['has_update'])->toBeTrue()
        ->and($result['release_info']['source'])->toBe('altum_product');
});

it('detects no update when product version is current', function () {
    Http::fake([
        'https://66biolinks.com/info.php' => Http::response([
            'latest_release_version' => '39.0.0',
        ], 200),
    ]);

    $vendor = new Vendor([
        'slug' => '66biolinks',
        'name' => '66biolinks',
        'plugin_platform' => Vendor::PLATFORM_ALTUM,
        'source_type' => Vendor::SOURCE_LICENSED,
        'current_version' => '39.0.0',
    ]);

    $method = new ReflectionMethod($this->service, 'checkAltumProduct');
    $method->setAccessible(true);

    $result = $method->invoke($this->service, $vendor);

    expect($result['status'])->toBe('success')
        ->and($result['has_update'])->toBeFalse()
        ->and($result['latest'])->toBe('39.0.0');
});

it('returns error when altum product endpoint fails', function () {
    Http::fake([
        'https://66analytics.com/info.php' => Http::response('Server Error', 500),
    ]);

    $vendor = new Vendor([
        'slug' => '66analytics',
        'name' => '66analytics',
        'plugin_platform' => Vendor::PLATFORM_ALTUM,
        'source_type' => Vendor::SOURCE_LICENSED,
        'current_version' => '40.0.0',
    ]);

    $method = new ReflectionMethod($this->service, 'checkAltumProduct');
    $method->setAccessible(true);

    $result = $method->invoke($this->service, $vendor);

    expect($result['status'])->toBe('error')
        ->and($result['message'])->toContain('500');
});

it('returns error when altum product has no version in response', function () {
    Http::fake([
        'https://66pusher.com/info.php' => Http::response([
            'product_name' => '66pusher',
            // No latest_release_version key
        ], 200),
    ]);

    $vendor = new Vendor([
        'slug' => '66pusher',
        'name' => '66pusher',
        'plugin_platform' => Vendor::PLATFORM_ALTUM,
        'source_type' => Vendor::SOURCE_LICENSED,
        'current_version' => '5.0.0',
    ]);

    $method = new ReflectionMethod($this->service, 'checkAltumProduct');
    $method->setAccessible(true);

    $result = $method->invoke($this->service, $vendor);

    expect($result['status'])->toBe('error')
        ->and($result['message'])->toContain('Could not determine latest version');
});

// ---------------------------------------------------------------------------
// checkAltumPlugin
// ---------------------------------------------------------------------------

it('fetches latest version for an altum plugin', function () {
    Http::fake([
        'https://dev.altumcode.com/plugins-versions' => Http::response([
            'affiliate' => ['version' => '2.0.1'],
            'email-notifications' => ['version' => '3.1.0'],
        ], 200),
    ]);

    $vendor = new Vendor([
        'slug' => 'altum-plugin-affiliate',
        'name' => 'AltumCode Affiliate Plugin',
        'plugin_platform' => Vendor::PLATFORM_ALTUM,
        'source_type' => Vendor::SOURCE_PLUGIN,
        'current_version' => '1.5.0',
    ]);

    $method = new ReflectionMethod($this->service, 'checkAltumPlugin');
    $method->setAccessible(true);

    $result = $method->invoke($this->service, $vendor);

    expect($result['status'])->toBe('success')
        ->and($result['latest'])->toBe('2.0.1')
        ->and($result['has_update'])->toBeTrue()
        ->and($result['release_info']['source'])->toBe('altum_plugin')
        ->and($result['release_info']['plugin_id'])->toBe('affiliate');
});

it('returns error when plugin is not found in altum registry', function () {
    Http::fake([
        'https://dev.altumcode.com/plugins-versions' => Http::response([
            'affiliate' => ['version' => '2.0.1'],
        ], 200),
    ]);

    $vendor = new Vendor([
        'slug' => 'altum-plugin-nonexistent',
        'name' => 'Nonexistent Plugin',
        'plugin_platform' => Vendor::PLATFORM_ALTUM,
        'source_type' => Vendor::SOURCE_PLUGIN,
        'current_version' => '1.0.0',
    ]);

    $method = new ReflectionMethod($this->service, 'checkAltumPlugin');
    $method->setAccessible(true);

    $result = $method->invoke($this->service, $vendor);

    expect($result['status'])->toBe('error')
        ->and($result['message'])->toContain('nonexistent')
        ->and($result['message'])->toContain('not found');
});

it('returns error when plugin versions endpoint fails', function () {
    Http::fake([
        'https://dev.altumcode.com/plugins-versions' => Http::response('Bad Gateway', 502),
    ]);

    $vendor = new Vendor([
        'slug' => 'altum-plugin-affiliate',
        'name' => 'AltumCode Affiliate Plugin',
        'plugin_platform' => Vendor::PLATFORM_ALTUM,
        'source_type' => Vendor::SOURCE_PLUGIN,
        'current_version' => '1.0.0',
    ]);

    $method = new ReflectionMethod($this->service, 'checkAltumPlugin');
    $method->setAccessible(true);

    $result = $method->invoke($this->service, $vendor);

    expect($result['status'])->toBe('error')
        ->and($result['message'])->toContain('Failed to fetch');
});

// ---------------------------------------------------------------------------
// In-memory cache
// ---------------------------------------------------------------------------

it('caches plugin versions across multiple calls', function () {
    Http::fake([
        'https://dev.altumcode.com/plugins-versions' => Http::response([
            'affiliate' => ['version' => '2.0.1'],
            'email-notifications' => ['version' => '3.1.0'],
        ], 200),
    ]);

    $method = new ReflectionMethod($this->service, 'getAltumPluginVersions');
    $method->setAccessible(true);

    // First call — should hit the HTTP endpoint
    $first = $method->invoke($this->service);
    expect($first)->toBeArray()->toHaveKey('affiliate');

    // Second call — should use cache (no additional HTTP request)
    $second = $method->invoke($this->service);
    expect($second)->toBe($first);

    // Verify HTTP was only called once
    Http::assertSentCount(1);
});

// ---------------------------------------------------------------------------
// Match routing via checkVendor (integration-level)
// ---------------------------------------------------------------------------

it('routes altum licensed vendor through checkAltumProduct', function () {
    Http::fake([
        'https://66analytics.com/info.php' => Http::response([
            'latest_release_version' => '42.0.0',
        ], 200),
    ]);

    $vendor = Mockery::mock(Vendor::class)->makePartial();
    $vendor->shouldReceive('getAttribute')->with('slug')->andReturn('66analytics');
    $vendor->shouldReceive('getAttribute')->with('name')->andReturn('66analytics');
    $vendor->shouldReceive('getAttribute')->with('plugin_platform')->andReturn(Vendor::PLATFORM_ALTUM);
    $vendor->shouldReceive('getAttribute')->with('source_type')->andReturn(Vendor::SOURCE_LICENSED);
    $vendor->shouldReceive('getAttribute')->with('current_version')->andReturn('41.0.0');
    $vendor->shouldReceive('getAttribute')->with('git_repo_url')->andReturn(null);
    $vendor->shouldReceive('update')->once()->with(Mockery::on(function ($data) {
        return array_key_exists('last_checked_at', $data);
    }));
    $vendor->shouldReceive('isLicensed')->andReturn(true);
    $vendor->shouldReceive('isPlugin')->andReturn(false);
    $vendor->shouldReceive('isOss')->andReturn(false);

    // Use a partial mock of the service to prevent createUpdateTodo from hitting DB
    $service = Mockery::mock(VendorUpdateCheckerService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('createUpdateTodo')->once();

    $result = $service->checkVendor($vendor);

    expect($result['status'])->toBe('success')
        ->and($result['latest'])->toBe('42.0.0')
        ->and($result['has_update'])->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://66analytics.com/info.php';
    });
});

it('routes altum plugin vendor through checkAltumPlugin', function () {
    Http::fake([
        'https://dev.altumcode.com/plugins-versions' => Http::response([
            'affiliate' => ['version' => '2.0.1'],
        ], 200),
    ]);

    $vendor = Mockery::mock(Vendor::class)->makePartial();
    $vendor->shouldReceive('getAttribute')->with('slug')->andReturn('altum-plugin-affiliate');
    $vendor->shouldReceive('getAttribute')->with('name')->andReturn('Affiliate Plugin');
    $vendor->shouldReceive('getAttribute')->with('plugin_platform')->andReturn(Vendor::PLATFORM_ALTUM);
    $vendor->shouldReceive('getAttribute')->with('source_type')->andReturn(Vendor::SOURCE_PLUGIN);
    $vendor->shouldReceive('getAttribute')->with('current_version')->andReturn('1.5.0');
    $vendor->shouldReceive('getAttribute')->with('git_repo_url')->andReturn(null);
    $vendor->shouldReceive('update')->once()->with(Mockery::on(function ($data) {
        return array_key_exists('last_checked_at', $data);
    }));
    $vendor->shouldReceive('isLicensed')->andReturn(false);
    $vendor->shouldReceive('isPlugin')->andReturn(true);
    $vendor->shouldReceive('isOss')->andReturn(false);

    // Use a partial mock of the service to prevent createUpdateTodo from hitting DB
    $service = Mockery::mock(VendorUpdateCheckerService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('createUpdateTodo')->once();

    $result = $service->checkVendor($vendor);

    expect($result['status'])->toBe('success')
        ->and($result['latest'])->toBe('2.0.1')
        ->and($result['has_update'])->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://dev.altumcode.com/plugins-versions';
    });
});

it('normalises version with v prefix from altum product', function () {
    Http::fake([
        'https://66socialproof.com/info.php' => Http::response([
            'latest_release_version' => 'v7.0.0',
        ], 200),
    ]);

    $vendor = new Vendor([
        'slug' => '66socialproof',
        'name' => '66socialproof',
        'plugin_platform' => Vendor::PLATFORM_ALTUM,
        'source_type' => Vendor::SOURCE_LICENSED,
        'current_version' => '6.0.0',
    ]);

    $method = new ReflectionMethod($this->service, 'checkAltumProduct');
    $method->setAccessible(true);

    $result = $method->invoke($this->service, $vendor);

    expect($result['latest'])->toBe('7.0.0')
        ->and($result['has_update'])->toBeTrue();
});
