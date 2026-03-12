<?php

declare(strict_types=1);

use Core\Mod\Uptelligence\Console\SyncAltumVersionsCommand;
use Core\Mod\Uptelligence\Database\Seeders\AltumCodeVendorSeeder;
use Core\Mod\Uptelligence\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Register command directly — the ConsoleBooting event doesn't fire in Testbench
    $this->app->make(\Illuminate\Contracts\Console\Kernel::class)
        ->registerCommand($this->app->make(SyncAltumVersionsCommand::class));

    (new AltumCodeVendorSeeder)->run();
});

it('updates product versions from init.php files', function () {
    $basePath = createMockServicesDirectory([
        '66analytics' => '42.0.0',
        '66biolinks' => '43.0.0',
    ]);

    $this->artisan('uptelligence:sync-altum-versions', ['--path' => $basePath])
        ->assertSuccessful();

    expect(Vendor::where('slug', '66analytics')->first()->current_version)->toBe('42.0.0')
        ->and(Vendor::where('slug', '66biolinks')->first()->current_version)->toBe('43.0.0');
});

it('updates plugin versions from config.php files', function () {
    $basePath = createMockServicesDirectory(
        productVersions: ['66biolinks' => '43.0.0'],
        pluginVersions: ['affiliate' => '2.5.0', 'teams' => '3.1.0'],
    );

    $this->artisan('uptelligence:sync-altum-versions', ['--path' => $basePath])
        ->assertSuccessful();

    expect(Vendor::where('slug', 'altum-plugin-affiliate')->first()->current_version)->toBe('2.5.0')
        ->and(Vendor::where('slug', 'altum-plugin-teams')->first()->current_version)->toBe('3.1.0');
});

it('shows WOULD UPDATE in dry-run mode without writing', function () {
    $basePath = createMockServicesDirectory(['66analytics' => '99.0.0']);

    $this->artisan('uptelligence:sync-altum-versions', [
        '--path' => $basePath,
        '--dry-run' => true,
    ])->assertSuccessful();

    // Version should NOT have changed
    expect(Vendor::where('slug', '66analytics')->first()->current_version)->toBe('0.0.0');
});

it('shows current status when version matches', function () {
    Vendor::where('slug', '66analytics')->update(['current_version' => '42.0.0']);

    $basePath = createMockServicesDirectory(['66analytics' => '42.0.0']);

    $this->artisan('uptelligence:sync-altum-versions', ['--path' => $basePath])
        ->assertSuccessful();

    expect(Vendor::where('slug', '66analytics')->first()->current_version)->toBe('42.0.0');
});

it('skips products whose init.php does not exist', function () {
    // Create directory structure but only for one product
    $basePath = createMockServicesDirectory(['66analytics' => '42.0.0']);

    $this->artisan('uptelligence:sync-altum-versions', ['--path' => $basePath])
        ->assertSuccessful();

    // 66analytics should be updated, others should remain at 0.0.0
    expect(Vendor::where('slug', '66analytics')->first()->current_version)->toBe('42.0.0')
        ->and(Vendor::where('slug', '66pusher')->first()->current_version)->toBe('0.0.0');
});

it('fails when base path does not exist', function () {
    $this->artisan('uptelligence:sync-altum-versions', ['--path' => '/nonexistent/path'])
        ->assertFailed();
});

it('skips plugins not registered in vendors table', function () {
    $basePath = createMockServicesDirectory(
        productVersions: ['66biolinks' => '43.0.0'],
        pluginVersions: ['unknown-plugin' => '1.0.0'],
    );

    $this->artisan('uptelligence:sync-altum-versions', ['--path' => $basePath])
        ->assertSuccessful();

    // Unknown plugin should not cause an error, but should be SKIPPED
    expect(Vendor::where('slug', 'altum-plugin-unknown-plugin')->first())->toBeNull();
});

/**
 * Create a temporary directory structure mimicking the SaaS services layout.
 *
 * @param  array<string, string>  $productVersions  slug => version
 * @param  array<string, string>  $pluginVersions   plugin_id => version
 */
function createMockServicesDirectory(
    array $productVersions = [],
    array $pluginVersions = [],
): string {
    $basePath = sys_get_temp_dir() . '/uptelligence-test-' . uniqid();

    $productPaths = [
        '66analytics' => '66analytics/package/product',
        '66biolinks' => '66biolinks/package/product',
        '66pusher' => '66pusher/package/product',
        '66socialproof' => '66socialproof/package/product',
    ];

    foreach ($productVersions as $slug => $version) {
        if (! isset($productPaths[$slug])) {
            continue;
        }

        $productDir = $basePath . '/' . $productPaths[$slug] . '/app';
        mkdir($productDir, 0755, true);

        file_put_contents(
            $productDir . '/init.php',
            "<?php\ndefine('PRODUCT_VERSION', '{$version}');\n",
        );
    }

    // Create plugins directory under 66biolinks
    if (! empty($pluginVersions)) {
        $pluginsBase = $basePath . '/' . $productPaths['66biolinks'] . '/plugins';

        foreach ($pluginVersions as $pluginId => $version) {
            $pluginDir = $pluginsBase . '/' . $pluginId;
            mkdir($pluginDir, 0755, true);

            file_put_contents(
                $pluginDir . '/config.php',
                "<?php\nreturn [\n    'version' => '{$version}',\n    'name' => 'Test Plugin',\n];\n",
            );
        }
    }

    // Ensure base path itself exists even if no products
    if (! is_dir($basePath)) {
        mkdir($basePath, 0755, true);
    }

    return $basePath;
}
