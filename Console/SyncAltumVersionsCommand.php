<?php

/*
 * Core PHP Framework
 *
 * Licensed under the European Union Public Licence (EUPL) v1.2.
 * See LICENSE file for details.
 */

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Console;

use Core\Mod\Uptelligence\Models\Vendor;
use Illuminate\Console\Command;

/**
 * Sync deployed AltumCode product and plugin versions from source files on disk.
 *
 * Reads PRODUCT_VERSION from each product's init.php and plugin versions from
 * config.php files, then updates the uptelligence_vendors table to reflect
 * what is actually deployed.
 */
class SyncAltumVersionsCommand extends Command
{
    protected $signature = 'uptelligence:sync-altum-versions
                            {--dry-run : Show what would change without writing to the database}
                            {--path= : Base path to the SaaS services directory}';

    protected $description = 'Sync deployed AltumCode product and plugin versions from source files';

    /**
     * Product slug => relative path from base to the product directory.
     *
     * @var array<string, string>
     */
    protected array $productPaths = [
        '66analytics' => '66analytics/package/product',
        '66biolinks' => '66biolinks/package/product',
        '66pusher' => '66pusher/package/product',
        '66socialproof' => '66socialproof/package/product',
    ];

    public function handle(): int
    {
        $basePath = $this->option('path')
            ?? env('SAAS_SERVICES_PATH', base_path('../lthn/saas/services'));

        $dryRun = (bool) $this->option('dry-run');

        if (! is_dir($basePath)) {
            $this->error("Base path does not exist: {$basePath}");

            return self::FAILURE;
        }

        $this->info('Syncing AltumCode versions from: ' . $basePath);
        if ($dryRun) {
            $this->warn('[DRY RUN] No changes will be written to the database.');
        }
        $this->newLine();

        $results = [];

        // Sync product versions
        foreach ($this->productPaths as $slug => $relativePath) {
            $results[] = $this->syncProductVersion($basePath, $slug, $relativePath, $dryRun);
        }

        // Sync plugin versions from the canonical source (66biolinks plugins directory)
        $pluginsDir = $basePath . '/' . $this->productPaths['66biolinks'] . '/plugins';
        $results = array_merge($results, $this->syncPluginVersions($pluginsDir, $dryRun));

        // Display results table
        $this->table(
            ['Vendor', 'Old Version', 'New Version', 'Status'],
            $results,
        );

        // Summary
        $this->newLine();
        $updated = collect($results)->filter(fn (array $r) => in_array($r[3], ['UPDATED', 'WOULD UPDATE']))->count();
        $current = collect($results)->filter(fn (array $r) => $r[3] === 'current')->count();
        $skipped = collect($results)->filter(fn (array $r) => $r[3] === 'SKIPPED')->count();

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Sync complete: {$updated} updated, {$current} current, {$skipped} skipped.");

        return self::SUCCESS;
    }

    /**
     * Sync a single product's version from its init.php file.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    protected function syncProductVersion(string $basePath, string $slug, string $relativePath, bool $dryRun): array
    {
        $initFile = $basePath . '/' . $relativePath . '/app/init.php';

        if (! file_exists($initFile)) {
            return [$slug, '-', '-', 'SKIPPED'];
        }

        $version = $this->parseProductVersion($initFile);

        if ($version === null) {
            return [$slug, '-', '-', 'SKIPPED'];
        }

        return $this->updateVendorVersion($slug, $version, $dryRun);
    }

    /**
     * Sync plugin versions from the plugins directory.
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    protected function syncPluginVersions(string $pluginsDir, bool $dryRun): array
    {
        $results = [];

        if (! is_dir($pluginsDir)) {
            $this->warn("Plugins directory not found: {$pluginsDir}");

            return $results;
        }

        $dirs = scandir($pluginsDir);

        if ($dirs === false) {
            return $results;
        }

        foreach ($dirs as $pluginId) {
            if ($pluginId === '.' || $pluginId === '..') {
                continue;
            }

            $configFile = $pluginsDir . '/' . $pluginId . '/config.php';

            if (! file_exists($configFile)) {
                continue;
            }

            $version = $this->parsePluginVersion($configFile);

            if ($version === null) {
                continue;
            }

            $slug = 'altum-plugin-' . $pluginId;
            $results[] = $this->updateVendorVersion($slug, $version, $dryRun);
        }

        return $results;
    }

    /**
     * Parse product version from an init.php file.
     *
     * Looks for: define('PRODUCT_VERSION', '65.0.0');
     */
    protected function parseProductVersion(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);

        if ($contents === false) {
            return null;
        }

        if (preg_match("/define\(\s*'PRODUCT_VERSION'\s*,\s*'([^']+)'\s*\)/", $contents, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Parse plugin version from a config.php file.
     *
     * Looks for: 'version' => '2.0.0',
     */
    protected function parsePluginVersion(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);

        if ($contents === false) {
            return null;
        }

        if (preg_match("/'version'\s*=>\s*'([^']+)'/", $contents, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Update a vendor's current_version in the database.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    protected function updateVendorVersion(string $slug, string $newVersion, bool $dryRun): array
    {
        $vendor = Vendor::where('slug', $slug)->first();

        if (! $vendor) {
            return [$slug, '-', $newVersion, 'SKIPPED'];
        }

        $oldVersion = $vendor->current_version ?? '0.0.0';

        if ($oldVersion === $newVersion) {
            return [$slug, $oldVersion, $newVersion, 'current'];
        }

        if (! $dryRun) {
            $vendor->update(['current_version' => $newVersion]);

            return [$slug, $oldVersion, $newVersion, 'UPDATED'];
        }

        return [$slug, $oldVersion, $newVersion, 'WOULD UPDATE'];
    }
}
