<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Jobs;

use Core\Mod\Uptelligence\Models\Vendor;
use Core\Mod\Uptelligence\Services\AssetTrackerService;
use Core\Mod\Uptelligence\Services\VendorUpdateCheckerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to check vendors and assets for upstream updates.
 *
 * Can be scheduled to run daily/weekly via the scheduler.
 * Checks OSS vendors via GitHub/Gitea APIs and assets via registries.
 */
class CheckVendorUpdatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Whether to also check package assets.
     */
    protected bool $checkAssets;

    /**
     * Specific vendor slug to check (null = all vendors).
     */
    protected ?string $vendorSlug;

    /**
     * Create a new job instance.
     */
    public function __construct(bool $checkAssets = true, ?string $vendorSlug = null)
    {
        $this->checkAssets = $checkAssets;
        $this->vendorSlug = $vendorSlug;
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(
        VendorUpdateCheckerService $vendorChecker,
        AssetTrackerService $assetChecker
    ): void {
        $vendorResults = $this->checkVendors($vendorChecker);
        $assetResults = $this->checkAssets ? $this->checkAssets($assetChecker) : [];

        $this->logSummary($vendorResults, $assetResults);
    }

    /**
     * Check vendors for updates.
     */
    protected function checkVendors(VendorUpdateCheckerService $checker): array
    {
        if ($this->vendorSlug) {
            $vendor = Vendor::where('slug', $this->vendorSlug)->first();
            if (! $vendor) {
                Log::warning('Uptelligence: Vendor not found for update check', [
                    'slug' => $this->vendorSlug,
                ]);

                return [];
            }

            return [$vendor->slug => $checker->checkVendor($vendor)];
        }

        return $checker->checkAllVendors();
    }

    /**
     * Check assets for updates.
     */
    protected function checkAssets(AssetTrackerService $checker): array
    {
        return $checker->checkAllForUpdates();
    }

    /**
     * Log a summary of the check results.
     */
    protected function logSummary(array $vendorResults, array $assetResults): void
    {
        $vendorUpdates = collect($vendorResults)->filter(fn ($r) => $r['has_update'] ?? false)->count();
        $vendorErrors = collect($vendorResults)->filter(fn ($r) => ($r['status'] ?? '') === 'error')->count();
        $vendorSkipped = collect($vendorResults)->filter(fn ($r) => ($r['status'] ?? '') === 'skipped')->count();

        $assetUpdates = collect($assetResults)->filter(fn ($r) => $r['has_update'] ?? false)->count();
        $assetErrors = collect($assetResults)->filter(fn ($r) => ($r['status'] ?? '') === 'error')->count();

        Log::info('Uptelligence: Update check complete', [
            'vendors_checked' => count($vendorResults),
            'vendors_with_updates' => $vendorUpdates,
            'vendors_skipped' => $vendorSkipped,
            'vendor_errors' => $vendorErrors,
            'assets_checked' => count($assetResults),
            'assets_with_updates' => $assetUpdates,
            'asset_errors' => $assetErrors,
        ]);

        // Log individual updates found
        foreach ($vendorResults as $slug => $result) {
            if ($result['has_update'] ?? false) {
                Log::info("Uptelligence: Vendor update available - {$slug}", [
                    'current' => $result['current'] ?? 'unknown',
                    'latest' => $result['latest'] ?? 'unknown',
                ]);
            }
        }

        foreach ($assetResults as $slug => $result) {
            if ($result['has_update'] ?? false) {
                Log::info("Uptelligence: Asset update available - {$slug}", [
                    'latest' => $result['latest'] ?? 'unknown',
                ]);
            }
        }
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        $tags = ['uptelligence', 'update-check'];

        if ($this->vendorSlug) {
            $tags[] = "vendor:{$this->vendorSlug}";
        }

        return $tags;
    }
}
