<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Console;

use Core\Mod\Uptelligence\Models\Vendor;
use Core\Mod\Uptelligence\Services\AssetTrackerService;
use Core\Mod\Uptelligence\Services\VendorStorageService;
use Illuminate\Console\Command;

class CheckCommand extends Command
{
    protected $signature = 'upstream:check
                            {vendor? : Vendor slug to check (optional, checks all if omitted)}
                            {--assets : Also check package assets for updates}';

    protected $description = 'Check vendors for upstream updates';

    public function handle(
        VendorStorageService $storageService,
        AssetTrackerService $assetService
    ): int {
        $vendorSlug = $this->argument('vendor');

        if ($vendorSlug) {
            $vendors = Vendor::where('slug', $vendorSlug)->get();
            if ($vendors->isEmpty()) {
                $this->error("Vendor not found: {$vendorSlug}");

                return self::FAILURE;
            }
        } else {
            $vendors = Vendor::active()->get();
        }

        if ($vendors->isEmpty()) {
            $this->warn('No active vendors found.');

            return self::SUCCESS;
        }

        $this->info('Checking vendors for updates...');
        $this->newLine();

        $table = [];
        foreach ($vendors as $vendor) {
            $localExists = $storageService->existsLocally($vendor, $vendor->current_version ?? 'current');
            $hasCurrentVersion = ! empty($vendor->current_version);
            $hasPreviousVersion = ! empty($vendor->previous_version);

            $status = match (true) {
                ! $hasCurrentVersion => '<fg=yellow>No version tracked</>',
                $localExists && $hasPreviousVersion => '<fg=green>Ready to analyze</>',
                $localExists => '<fg=blue>Current only</>',
                default => '<fg=red>Files missing</>',
            };

            $table[] = [
                $vendor->slug,
                $vendor->name,
                $vendor->getSourceTypeLabel(),
                $vendor->current_version ?? '-',
                $vendor->previous_version ?? '-',
                $vendor->getPendingTodosCount(),
                $status,
            ];

            $vendor->update(['last_checked_at' => now()]);
        }

        $this->table(
            ['Slug', 'Name', 'Type', 'Current', 'Previous', 'Pending', 'Status'],
            $table
        );

        if ($this->option('assets')) {
            $this->newLine();
            $this->info('Checking package assets...');

            $results = $assetService->checkAllForUpdates();
            $assetTable = [];

            foreach ($results as $slug => $result) {
                $statusIcon = match ($result['status']) {
                    'success' => $result['has_update'] ?? false
                        ? '<fg=yellow>Update available</>'
                        : '<fg=green>Up to date</>',
                    'rate_limited' => '<fg=red>Rate limited</>',
                    'skipped' => '<fg=gray>Skipped</>',
                    default => '<fg=red>Error</>',
                };

                $assetTable[] = [
                    $slug,
                    $result['latest'] ?? $result['installed'] ?? '-',
                    $statusIcon,
                ];
            }

            $this->table(['Asset', 'Version', 'Status'], $assetTable);
        }

        $this->newLine();
        $this->info('Check complete.');

        return self::SUCCESS;
    }
}
