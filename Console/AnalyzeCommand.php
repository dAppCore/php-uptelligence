<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Console;

use Illuminate\Console\Command;
use Core\Mod\Uptelligence\Models\Vendor;
use Core\Mod\Uptelligence\Models\VersionRelease;
use Core\Mod\Uptelligence\Services\DiffAnalyzerService;
use Core\Mod\Uptelligence\Services\VendorStorageService;

class AnalyzeCommand extends Command
{
    protected $signature = 'upstream:analyze
                            {vendor : Vendor slug to analyze}
                            {--from= : Previous version (defaults to vendor.previous_version)}
                            {--to= : Current version (defaults to vendor.current_version)}
                            {--summary : Show summary only, no file details}';

    protected $description = 'Analyze differences between vendor versions';

    public function handle(VendorStorageService $storageService): int
    {
        $vendorSlug = $this->argument('vendor');
        $vendor = Vendor::where('slug', $vendorSlug)->first();

        if (! $vendor) {
            $this->error("Vendor not found: {$vendorSlug}");

            return self::FAILURE;
        }

        $fromVersion = $this->option('from') ?? $vendor->previous_version;
        $toVersion = $this->option('to') ?? $vendor->current_version;

        if (! $fromVersion || ! $toVersion) {
            $this->error('Both from and to versions are required.');
            $this->line('Use --from and --to options, or ensure vendor has previous_version and current_version set.');

            return self::FAILURE;
        }

        // Verify both versions exist locally
        if (! $storageService->existsLocally($vendor, $fromVersion)) {
            $this->error("Version not found locally: {$fromVersion}");
            $this->line("Expected path: {$vendor->getStoragePath($fromVersion)}");

            return self::FAILURE;
        }

        if (! $storageService->existsLocally($vendor, $toVersion)) {
            $this->error("Version not found locally: {$toVersion}");
            $this->line("Expected path: {$vendor->getStoragePath($toVersion)}");

            return self::FAILURE;
        }

        $this->info("Analyzing {$vendor->name}: {$fromVersion} → {$toVersion}");
        $this->newLine();

        // Check if we have an existing release or need to create one
        $release = VersionRelease::where('vendor_id', $vendor->id)
            ->where('version', $toVersion)
            ->where('previous_version', $fromVersion)
            ->first();

        if ($release && $release->analyzed_at) {
            $this->line('Using cached analysis from '.$release->analyzed_at->diffForHumans());
        } else {
            $this->line('Running diff analysis...');

            $analyzer = new DiffAnalyzerService($vendor);
            $release = $analyzer->analyze($fromVersion, $toVersion);

            $this->info('Analysis complete.');
        }

        // Display summary
        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Files Added', $release->files_added ?? 0],
                ['Files Modified', $release->files_modified ?? 0],
                ['Files Removed', $release->files_removed ?? 0],
                ['Total Changes', ($release->files_added ?? 0) + ($release->files_modified ?? 0) + ($release->files_removed ?? 0)],
            ]
        );

        // Show file details unless --summary
        if (! $this->option('summary') && $release->diffs) {
            $diffs = $release->diffs()->get();

            if ($diffs->isNotEmpty()) {
                $this->newLine();
                $this->line('<fg=cyan>Changes by category:</>');

                $byCategory = $diffs->groupBy('category');
                foreach ($byCategory as $category => $categoryDiffs) {
                    $this->line("  {$category}: {$categoryDiffs->count()}");
                }

                // Show modified files
                $modified = $diffs->where('change_type', 'modified')->take(20);
                if ($modified->isNotEmpty()) {
                    $this->newLine();
                    $this->line('<fg=yellow>Modified files (up to 20):</>');
                    foreach ($modified as $diff) {
                        $priority = $vendor->isPriorityPath($diff->file_path) ? ' <fg=magenta>[PRIORITY]</>' : '';
                        $this->line("  M {$diff->file_path}{$priority}");
                    }
                }

                // Show added files
                $added = $diffs->where('change_type', 'added')->take(10);
                if ($added->isNotEmpty()) {
                    $this->newLine();
                    $this->line('<fg=green>Added files (up to 10):</>');
                    foreach ($added as $diff) {
                        $this->line("  A {$diff->file_path}");
                    }
                }

                // Show removed files
                $removed = $diffs->where('change_type', 'removed')->take(10);
                if ($removed->isNotEmpty()) {
                    $this->newLine();
                    $this->line('<fg=red>Removed files (up to 10):</>');
                    foreach ($removed as $diff) {
                        $this->line("  D {$diff->file_path}");
                    }
                }
            }
        }

        $vendor->update(['last_analyzed_at' => now()]);

        return self::SUCCESS;
    }
}
