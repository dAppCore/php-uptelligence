<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Console;

use Core\Mod\Uptelligence\Models\Vendor;
use Core\Mod\Uptelligence\Services\AssetTrackerService;
use Core\Mod\Uptelligence\Services\VendorUpdateCheckerService;
use Illuminate\Console\Command;

/**
 * Artisan command to check vendors and assets for upstream updates.
 *
 * Can be run manually or scheduled via the scheduler.
 */
class CheckUpdatesCommand extends Command
{
    protected $signature = 'upstream:check-updates
                            {--vendor= : Specific vendor slug to check}
                            {--assets : Also check package assets for updates}
                            {--no-todos : Do not create todos for updates found}
                            {--json : Output results as JSON}';

    protected $aliases = ['uptelligence:check-updates'];

    protected $description = 'Check vendors and assets for upstream updates';

    public function handle(
        VendorUpdateCheckerService $vendorChecker,
        AssetTrackerService $assetChecker
    ): int {
        $vendorSlug = $this->option('vendor');
        $checkAssets = $this->option('assets');
        $jsonOutput = $this->option('json');

        if (! $jsonOutput) {
            $this->info('Checking for upstream updates...');
            $this->newLine();
        }

        // Check vendors
        $vendorResults = $this->checkVendors($vendorChecker, $vendorSlug);

        // Check assets if requested
        $assetResults = [];
        if ($checkAssets) {
            $assetResults = $this->checkAssets($assetChecker);
        }

        // Output results
        if ($jsonOutput) {
            $this->outputJson($vendorResults, $assetResults);
        } else {
            $this->outputTable($vendorResults, $assetResults);
        }

        // Return appropriate exit code
        $hasUpdates = collect($vendorResults)->contains(fn ($r) => $r['has_update'] ?? false)
            || collect($assetResults)->contains(fn ($r) => $r['has_update'] ?? false);

        return $hasUpdates ? self::SUCCESS : self::SUCCESS;
    }

    /**
     * Check vendors for updates.
     */
    protected function checkVendors(VendorUpdateCheckerService $checker, ?string $vendorSlug): array
    {
        if ($vendorSlug) {
            $vendor = Vendor::where('slug', $vendorSlug)->first();
            if (! $vendor) {
                $this->error("Vendor not found: {$vendorSlug}");

                return [];
            }

            $this->line("Checking vendor: {$vendor->name}");

            return [$vendor->slug => $checker->checkVendor($vendor)];
        }

        $vendors = Vendor::active()->get();
        if ($vendors->isEmpty()) {
            $this->warn('No active vendors found.');

            return [];
        }

        $this->line("Checking {$vendors->count()} vendor(s)...");

        return $checker->checkAllVendors();
    }

    /**
     * Check assets for updates.
     */
    protected function checkAssets(AssetTrackerService $checker): array
    {
        $this->newLine();
        $this->line('Checking package assets...');

        return $checker->checkAllForUpdates();
    }

    /**
     * Output results as a table.
     */
    protected function outputTable(array $vendorResults, array $assetResults): void
    {
        if (! empty($vendorResults)) {
            $this->newLine();
            $this->line('<fg=cyan>Vendor Update Check Results:</>');

            $table = [];
            foreach ($vendorResults as $slug => $result) {
                $status = match ($result['status'] ?? 'unknown') {
                    'success' => $result['has_update']
                        ? '<fg=yellow>Update available</>'
                        : '<fg=green>Up to date</>',
                    'skipped' => '<fg=gray>Skipped</>',
                    'rate_limited' => '<fg=red>Rate limited</>',
                    'error' => '<fg=red>Error</>',
                    default => '<fg=gray>Unknown</>',
                };

                $table[] = [
                    $slug,
                    $result['current'] ?? '-',
                    $result['latest'] ?? '-',
                    $status,
                    $result['message'] ?? '',
                ];
            }

            $this->table(
                ['Vendor', 'Current', 'Latest', 'Status', 'Message'],
                $table
            );
        }

        if (! empty($assetResults)) {
            $this->newLine();
            $this->line('<fg=cyan>Asset Update Check Results:</>');

            $table = [];
            foreach ($assetResults as $slug => $result) {
                $status = match ($result['status'] ?? 'unknown') {
                    'success' => $result['has_update'] ?? false
                        ? '<fg=yellow>Update available</>'
                        : '<fg=green>Up to date</>',
                    'skipped' => '<fg=gray>Skipped</>',
                    'rate_limited' => '<fg=red>Rate limited</>',
                    'info' => '<fg=blue>Info</>',
                    'error' => '<fg=red>Error</>',
                    default => '<fg=gray>Unknown</>',
                };

                $table[] = [
                    $slug,
                    $result['installed'] ?? $result['latest'] ?? '-',
                    $status,
                    $result['message'] ?? '',
                ];
            }

            $this->table(
                ['Asset', 'Version', 'Status', 'Message'],
                $table
            );
        }

        // Summary
        $this->newLine();
        $vendorUpdates = collect($vendorResults)->filter(fn ($r) => $r['has_update'] ?? false)->count();
        $assetUpdates = collect($assetResults)->filter(fn ($r) => $r['has_update'] ?? false)->count();
        $totalUpdates = $vendorUpdates + $assetUpdates;

        if ($totalUpdates > 0) {
            $this->warn("Found {$totalUpdates} update(s) available.");
        } else {
            $this->info('All vendors and assets are up to date.');
        }
    }

    /**
     * Output results as JSON.
     */
    protected function outputJson(array $vendorResults, array $assetResults): void
    {
        $output = [
            'vendors' => $vendorResults,
            'assets' => $assetResults,
            'summary' => [
                'vendors_checked' => count($vendorResults),
                'vendors_with_updates' => collect($vendorResults)->filter(fn ($r) => $r['has_update'] ?? false)->count(),
                'assets_checked' => count($assetResults),
                'assets_with_updates' => collect($assetResults)->filter(fn ($r) => $r['has_update'] ?? false)->count(),
            ],
        ];

        $this->line(json_encode($output, JSON_PRETTY_PRINT));
    }
}
