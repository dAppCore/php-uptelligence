<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Services;

use Carbon\Carbon;
use Core\Mod\Uptelligence\Models\Asset;
use Core\Mod\Uptelligence\Models\AssetVersion;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Asset Tracker Service - monitors and updates package dependencies.
 *
 * Checks Packagist, NPM, and custom registries for updates.
 */
class AssetTrackerService
{
    /**
     * Valid Composer package name pattern.
     *
     * Matches vendor/package format with alphanumeric, hyphen, underscore, and dot characters.
     */
    protected const COMPOSER_PACKAGE_PATTERN = '/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$/i';

    /**
     * Valid NPM package name pattern.
     *
     * Matches scoped (@scope/package) and unscoped package names.
     */
    protected const NPM_PACKAGE_PATTERN = '/^(@[a-z0-9-~][a-z0-9-._~]*\/)?[a-z0-9-~][a-z0-9-._~]*$/i';

    /**
     * Validate a package name to prevent shell injection.
     *
     * Package names should only contain safe characters for CLI usage.
     *
     * @throws \InvalidArgumentException If the package name contains invalid characters
     */
    protected function validatePackageName(string $packageName, string $type): string
    {
        $pattern = match ($type) {
            Asset::TYPE_COMPOSER => self::COMPOSER_PACKAGE_PATTERN,
            Asset::TYPE_NPM => self::NPM_PACKAGE_PATTERN,
            default => throw new \InvalidArgumentException("Unknown package type: {$type}"),
        };

        if (! preg_match($pattern, $packageName)) {
            Log::warning('Uptelligence: Invalid package name rejected', [
                'package_name' => $packageName,
                'type' => $type,
            ]);

            throw new \InvalidArgumentException("Invalid package name format: {$packageName}");
        }

        return $packageName;
    }

    /**
     * Check all active assets for updates.
     */
    public function checkAllForUpdates(): array
    {
        $results = [];

        foreach (Asset::active()->get() as $asset) {
            $results[$asset->slug] = $this->checkForUpdate($asset);
        }

        return $results;
    }

    /**
     * Check a single asset for updates.
     */
    public function checkForUpdate(Asset $asset): array
    {
        $result = match ($asset->type) {
            Asset::TYPE_COMPOSER => $this->checkComposerPackage($asset),
            Asset::TYPE_NPM => $this->checkNpmPackage($asset),
            Asset::TYPE_FONT => $this->checkFontAsset($asset),
            default => ['status' => 'skipped', 'message' => 'No auto-check for this type'],
        };

        $asset->update(['last_checked_at' => now()]);

        return $result;
    }

    /**
     * Check Composer package for updates with rate limiting and retry logic.
     */
    protected function checkComposerPackage(Asset $asset): array
    {
        if (! $asset->package_name) {
            return ['status' => 'error', 'message' => 'No package name configured'];
        }

        // Check rate limit before making API call
        if (RateLimiter::tooManyAttempts('upstream-registry', 30)) {
            $seconds = RateLimiter::availableIn('upstream-registry');

            return [
                'status' => 'rate_limited',
                'message' => "Rate limit exceeded. Retry after {$seconds} seconds",
            ];
        }

        RateLimiter::hit('upstream-registry');

        // Try Packagist first with retry logic
        $response = Http::timeout(30)
            ->retry(3, function (int $attempt, \Exception $exception) {
                $delay = (int) pow(2, $attempt - 1) * 1000;

                Log::warning('Uptelligence: Packagist API retry', [
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                    'error' => $exception->getMessage(),
                ]);

                return $delay;
            }, function (\Exception $exception) {
                if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
                    return true;
                }
                if ($exception instanceof \Illuminate\Http\Client\RequestException) {
                    $status = $exception->response?->status();

                    return $status >= 500 || $status === 429;
                }

                return false;
            })
            ->get("https://repo.packagist.org/p2/{$asset->package_name}.json");

        if ($response->successful()) {
            $data = $response->json();
            $packages = $data['packages'][$asset->package_name] ?? [];

            if (! empty($packages)) {
                // Get latest stable version
                $latest = collect($packages)
                    ->filter(fn ($p) => ! str_contains($p['version'] ?? '', 'dev'))
                    ->sortByDesc('version')
                    ->first();

                if ($latest) {
                    $latestVersion = ltrim($latest['version'], 'v');
                    $hasUpdate = $asset->installed_version &&
                        version_compare($latestVersion, $asset->installed_version, '>');

                    $asset->update(['latest_version' => $latestVersion]);

                    // Record version if new
                    $this->recordVersion($asset, $latestVersion, $latest);

                    return [
                        'status' => 'success',
                        'latest' => $latestVersion,
                        'has_update' => $hasUpdate,
                    ];
                }
            }
        } else {
            Log::warning('Uptelligence: Packagist API request failed', [
                'package' => $asset->package_name,
                'status' => $response->status(),
            ]);
        }

        // Try custom registry (e.g., Flux Pro)
        if ($asset->registry_url) {
            return $this->checkCustomComposerRegistry($asset);
        }

        return ['status' => 'error', 'message' => 'Could not fetch package info'];
    }

    /**
     * Check custom Composer registry (like Flux Pro).
     *
     * Uses array-based Process invocation to prevent shell injection.
     */
    protected function checkCustomComposerRegistry(Asset $asset): array
    {
        // Validate package name to prevent shell injection
        try {
            $packageName = $this->validatePackageName($asset->package_name, Asset::TYPE_COMPOSER);
        } catch (\InvalidArgumentException $e) {
            return ['status' => 'error', 'message' => 'Invalid package name format'];
        }

        // For licensed packages, we need to check the installed version via composer show
        // Use array syntax to prevent shell injection
        $result = Process::run(['composer', 'show', $packageName, '--format=json']);

        if ($result->successful()) {
            $data = json_decode($result->output(), true);
            $installedVersion = $data['versions'][0] ?? null;

            if ($installedVersion) {
                $asset->update(['installed_version' => $installedVersion]);

                return [
                    'status' => 'success',
                    'installed' => $installedVersion,
                    'message' => 'Check registry manually for latest version',
                ];
            }
        }

        return ['status' => 'info', 'message' => 'Licensed package - check registry manually'];
    }

    /**
     * Check NPM package for updates with rate limiting and retry logic.
     */
    protected function checkNpmPackage(Asset $asset): array
    {
        if (! $asset->package_name) {
            return ['status' => 'error', 'message' => 'No package name configured'];
        }

        // Check rate limit before making API call
        if (RateLimiter::tooManyAttempts('upstream-registry', 30)) {
            $seconds = RateLimiter::availableIn('upstream-registry');

            return [
                'status' => 'rate_limited',
                'message' => "Rate limit exceeded. Retry after {$seconds} seconds",
            ];
        }

        RateLimiter::hit('upstream-registry');

        // Check npm registry with retry logic
        $response = Http::timeout(30)
            ->retry(3, function (int $attempt, \Exception $exception) {
                $delay = (int) pow(2, $attempt - 1) * 1000;

                Log::warning('Uptelligence: NPM registry API retry', [
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                    'error' => $exception->getMessage(),
                ]);

                return $delay;
            }, function (\Exception $exception) {
                if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
                    return true;
                }
                if ($exception instanceof \Illuminate\Http\Client\RequestException) {
                    $status = $exception->response?->status();

                    return $status >= 500 || $status === 429;
                }

                return false;
            })
            ->get("https://registry.npmjs.org/{$asset->package_name}");

        if ($response->successful()) {
            $data = $response->json();
            $latestVersion = $data['dist-tags']['latest'] ?? null;

            if ($latestVersion) {
                $hasUpdate = $asset->installed_version &&
                    version_compare($latestVersion, $asset->installed_version, '>');

                $asset->update(['latest_version' => $latestVersion]);

                // Record version if new
                $versionData = $data['versions'][$latestVersion] ?? [];
                $this->recordVersion($asset, $latestVersion, $versionData);

                return [
                    'status' => 'success',
                    'latest' => $latestVersion,
                    'has_update' => $hasUpdate,
                ];
            }
        } else {
            Log::warning('Uptelligence: NPM registry API request failed', [
                'package' => $asset->package_name,
                'status' => $response->status(),
            ]);
        }

        // Check for scoped/private packages via npm view
        // Use array syntax to prevent shell injection
        $result = Process::run(['npm', 'view', $asset->package_name, 'version']);
        if ($result->successful()) {
            $latestVersion = trim($result->output());
            if ($latestVersion) {
                $asset->update(['latest_version' => $latestVersion]);

                return [
                    'status' => 'success',
                    'latest' => $latestVersion,
                    'has_update' => $asset->installed_version &&
                        version_compare($latestVersion, $asset->installed_version, '>'),
                ];
            }
        }

        return ['status' => 'error', 'message' => 'Could not fetch package info'];
    }

    /**
     * Check Font Awesome kit for updates.
     */
    protected function checkFontAsset(Asset $asset): array
    {
        // Font Awesome kits auto-update, just verify the kit is valid
        $kitId = $asset->licence_meta['kit_id'] ?? null;

        if (! $kitId) {
            return ['status' => 'info', 'message' => 'No kit ID configured'];
        }

        // Can't easily check FA API without auth, mark as checked
        return [
            'status' => 'success',
            'message' => 'Font kit configured - auto-updates via CDN',
        ];
    }

    /**
     * Parse a release timestamp safely.
     *
     * Handles various timestamp formats from Packagist and NPM.
     */
    protected function parseReleaseTimestamp(?string $time): ?Carbon
    {
        if (empty($time)) {
            return null;
        }

        try {
            return Carbon::parse($time);
        } catch (\Exception $e) {
            Log::warning('Uptelligence: Failed to parse release timestamp', [
                'time' => $time,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Record a new version in history.
     */
    protected function recordVersion(Asset $asset, string $version, array $data = []): void
    {
        $releasedAt = $this->parseReleaseTimestamp($data['time'] ?? null);

        AssetVersion::updateOrCreate(
            [
                'asset_id' => $asset->id,
                'version' => $version,
            ],
            [
                'changelog' => $data['description'] ?? null,
                'download_url' => $data['dist']['url'] ?? null,
                'released_at' => $releasedAt,
            ]
        );
    }

    /**
     * Update an asset to its latest version.
     */
    public function updateAsset(Asset $asset): array
    {
        return match ($asset->type) {
            Asset::TYPE_COMPOSER => $this->updateComposerPackage($asset),
            Asset::TYPE_NPM => $this->updateNpmPackage($asset),
            default => ['status' => 'skipped', 'message' => 'Manual update required'],
        };
    }

    /**
     * Update a Composer package.
     *
     * Uses array-based Process invocation to prevent shell injection.
     */
    protected function updateComposerPackage(Asset $asset): array
    {
        if (! $asset->package_name) {
            return ['status' => 'error', 'message' => 'No package name'];
        }

        // Validate package name to prevent shell injection
        try {
            $packageName = $this->validatePackageName($asset->package_name, Asset::TYPE_COMPOSER);
        } catch (\InvalidArgumentException $e) {
            return ['status' => 'error', 'message' => 'Invalid package name format'];
        }

        // Use array syntax to prevent shell injection
        $result = Process::timeout(300)->run(
            ['composer', 'update', $packageName, '--no-interaction']
        );

        if ($result->successful()) {
            // Get new installed version using array syntax
            $showResult = Process::run(['composer', 'show', $packageName, '--format=json']);
            if ($showResult->successful()) {
                $data = json_decode($showResult->output(), true);
                $newVersion = $data['versions'][0] ?? $asset->latest_version;
                $asset->update(['installed_version' => $newVersion]);
            }

            return ['status' => 'success', 'message' => 'Package updated'];
        }

        return ['status' => 'error', 'message' => $result->errorOutput()];
    }

    /**
     * Update an NPM package.
     *
     * Uses array-based Process invocation to prevent shell injection.
     */
    protected function updateNpmPackage(Asset $asset): array
    {
        if (! $asset->package_name) {
            return ['status' => 'error', 'message' => 'No package name'];
        }

        // Validate package name to prevent shell injection
        try {
            $packageName = $this->validatePackageName($asset->package_name, Asset::TYPE_NPM);
        } catch (\InvalidArgumentException $e) {
            return ['status' => 'error', 'message' => 'Invalid package name format'];
        }

        // Use array syntax to prevent shell injection
        $result = Process::timeout(300)->run(['npm', 'update', $packageName]);

        if ($result->successful()) {
            $asset->update(['installed_version' => $asset->latest_version]);

            return ['status' => 'success', 'message' => 'Package updated'];
        }

        return ['status' => 'error', 'message' => $result->errorOutput()];
    }

    /**
     * Sync installed versions from composer.lock and package-lock.json.
     */
    public function syncInstalledVersions(string $projectPath): array
    {
        $synced = [];

        // Sync from composer.lock
        $composerLock = $projectPath.'/composer.lock';
        if (file_exists($composerLock)) {
            $lock = json_decode(file_get_contents($composerLock), true);
            $packages = array_merge(
                $lock['packages'] ?? [],
                $lock['packages-dev'] ?? []
            );

            foreach ($packages as $package) {
                $asset = Asset::where('package_name', $package['name'])
                    ->where('type', Asset::TYPE_COMPOSER)
                    ->first();

                if ($asset) {
                    $version = ltrim($package['version'], 'v');
                    $asset->update(['installed_version' => $version]);
                    $synced[] = $asset->slug;
                }
            }
        }

        // Sync from package-lock.json
        $packageLock = $projectPath.'/package-lock.json';
        if (file_exists($packageLock)) {
            $lock = json_decode(file_get_contents($packageLock), true);
            $packages = $lock['packages'] ?? [];

            foreach ($packages as $name => $data) {
                // Skip root package and nested deps
                if (! $name || str_starts_with($name, 'node_modules/node_modules')) {
                    continue;
                }

                $packageName = str_replace('node_modules/', '', $name);
                $asset = Asset::where('package_name', $packageName)
                    ->where('type', Asset::TYPE_NPM)
                    ->first();

                if ($asset) {
                    $asset->update(['installed_version' => $data['version']]);
                    $synced[] = $asset->slug;
                }
            }
        }

        return $synced;
    }
}
