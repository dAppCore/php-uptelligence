<?php

declare(strict_types=1);

namespace Core\Uptelligence\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Core\Uptelligence\Models\UpstreamTodo;
use Core\Uptelligence\Models\Vendor;

/**
 * Vendor Update Checker Service - checks upstream sources for new releases.
 *
 * Supports GitHub releases, Packagist, and NPM registries.
 */
class VendorUpdateCheckerService
{
    /**
     * Check all active vendors for updates.
     *
     * @return array<string, array{status: string, current: ?string, latest: ?string, has_update: bool, message?: string}>
     */
    public function checkAllVendors(): array
    {
        $results = [];

        foreach (Vendor::active()->get() as $vendor) {
            $results[$vendor->slug] = $this->checkVendor($vendor);
        }

        return $results;
    }

    /**
     * Check a single vendor for updates.
     *
     * @return array{status: string, current: ?string, latest: ?string, has_update: bool, message?: string}
     */
    public function checkVendor(Vendor $vendor): array
    {
        // Determine check method based on source type and git URL
        $result = match (true) {
            $vendor->isOss() && $this->isGitHubUrl($vendor->git_repo_url) => $this->checkGitHub($vendor),
            $vendor->isOss() && $this->isGiteaUrl($vendor->git_repo_url) => $this->checkGitea($vendor),
            default => $this->skipCheck($vendor),
        };

        // Update last_checked_at
        $vendor->update(['last_checked_at' => now()]);

        // If update found and it's significant, create a todo
        if ($result['has_update'] && $result['latest']) {
            $this->createUpdateTodo($vendor, $result['latest']);
        }

        return $result;
    }

    /**
     * Check GitHub repository for new releases.
     */
    protected function checkGitHub(Vendor $vendor): array
    {
        if (! $vendor->git_repo_url) {
            return $this->errorResult('No Git repository URL configured');
        }

        // Rate limit check
        if (RateLimiter::tooManyAttempts('upstream-registry', 30)) {
            $seconds = RateLimiter::availableIn('upstream-registry');

            return $this->rateLimitedResult($seconds);
        }

        RateLimiter::hit('upstream-registry');

        // Parse owner/repo from URL
        $parsed = $this->parseGitHubUrl($vendor->git_repo_url);
        if (! $parsed) {
            return $this->errorResult('Invalid GitHub URL format');
        }

        [$owner, $repo] = $parsed;

        // Build request with optional token
        $request = Http::timeout(30)
            ->retry(3, function (int $attempt) {
                return (int) pow(2, $attempt - 1) * 1000;
            }, function (\Exception $exception) {
                if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
                    return true;
                }
                if ($exception instanceof \Illuminate\Http\Client\RequestException) {
                    $status = $exception->response?->status();

                    return $status >= 500 || $status === 429;
                }

                return false;
            });

        // Add auth token if configured
        $token = config('upstream.github.token');
        if ($token) {
            $request->withToken($token);
        }

        // Fetch latest release
        $response = $request->get("https://api.github.com/repos/{$owner}/{$repo}/releases/latest");

        if ($response->status() === 404) {
            // No releases - try tags instead
            return $this->checkGitHubTags($vendor, $owner, $repo, $token);
        }

        if (! $response->successful()) {
            Log::warning('Uptelligence: GitHub API request failed', [
                'vendor' => $vendor->slug,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return $this->errorResult("GitHub API error: {$response->status()}");
        }

        $data = $response->json();
        $latestVersion = $this->normaliseVersion($data['tag_name'] ?? '');

        if (! $latestVersion) {
            return $this->errorResult('Could not determine latest version');
        }

        return $this->buildResult(
            vendor: $vendor,
            latestVersion: $latestVersion,
            releaseInfo: [
                'name' => $data['name'] ?? null,
                'body' => $data['body'] ?? null,
                'published_at' => $data['published_at'] ?? null,
                'html_url' => $data['html_url'] ?? null,
            ]
        );
    }

    /**
     * Check GitHub tags when no releases exist.
     */
    protected function checkGitHubTags(Vendor $vendor, string $owner, string $repo, ?string $token): array
    {
        $request = Http::timeout(30);
        if ($token) {
            $request->withToken($token);
        }

        $response = $request->get("https://api.github.com/repos/{$owner}/{$repo}/tags", [
            'per_page' => 1,
        ]);

        if (! $response->successful()) {
            return $this->errorResult("GitHub tags API error: {$response->status()}");
        }

        $tags = $response->json();
        if (empty($tags)) {
            return $this->errorResult('No releases or tags found');
        }

        $latestVersion = $this->normaliseVersion($tags[0]['name'] ?? '');

        return $this->buildResult(
            vendor: $vendor,
            latestVersion: $latestVersion,
            releaseInfo: ['tag' => $tags[0]['name'] ?? null]
        );
    }

    /**
     * Check Gitea repository for new releases.
     */
    protected function checkGitea(Vendor $vendor): array
    {
        if (! $vendor->git_repo_url) {
            return $this->errorResult('No Git repository URL configured');
        }

        // Rate limit check
        if (RateLimiter::tooManyAttempts('upstream-registry', 30)) {
            $seconds = RateLimiter::availableIn('upstream-registry');

            return $this->rateLimitedResult($seconds);
        }

        RateLimiter::hit('upstream-registry');

        // Parse owner/repo from URL
        $parsed = $this->parseGiteaUrl($vendor->git_repo_url);
        if (! $parsed) {
            return $this->errorResult('Invalid Gitea URL format');
        }

        [$baseUrl, $owner, $repo] = $parsed;

        $request = Http::timeout(30);

        // Add auth token if configured
        $token = config('upstream.gitea.token');
        if ($token) {
            $request->withHeaders(['Authorization' => "token {$token}"]);
        }

        // Fetch latest release
        $response = $request->get("{$baseUrl}/api/v1/repos/{$owner}/{$repo}/releases/latest");

        if ($response->status() === 404) {
            // No releases - try tags
            return $this->checkGiteaTags($vendor, $baseUrl, $owner, $repo, $token);
        }

        if (! $response->successful()) {
            Log::warning('Uptelligence: Gitea API request failed', [
                'vendor' => $vendor->slug,
                'status' => $response->status(),
            ]);

            return $this->errorResult("Gitea API error: {$response->status()}");
        }

        $data = $response->json();
        $latestVersion = $this->normaliseVersion($data['tag_name'] ?? '');

        return $this->buildResult(
            vendor: $vendor,
            latestVersion: $latestVersion,
            releaseInfo: [
                'name' => $data['name'] ?? null,
                'body' => $data['body'] ?? null,
                'published_at' => $data['published_at'] ?? null,
            ]
        );
    }

    /**
     * Check Gitea tags when no releases exist.
     */
    protected function checkGiteaTags(Vendor $vendor, string $baseUrl, string $owner, string $repo, ?string $token): array
    {
        $request = Http::timeout(30);
        if ($token) {
            $request->withHeaders(['Authorization' => "token {$token}"]);
        }

        $response = $request->get("{$baseUrl}/api/v1/repos/{$owner}/{$repo}/tags", [
            'limit' => 1,
        ]);

        if (! $response->successful()) {
            return $this->errorResult("Gitea tags API error: {$response->status()}");
        }

        $tags = $response->json();
        if (empty($tags)) {
            return $this->errorResult('No releases or tags found');
        }

        $latestVersion = $this->normaliseVersion($tags[0]['name'] ?? '');

        return $this->buildResult(
            vendor: $vendor,
            latestVersion: $latestVersion,
            releaseInfo: ['tag' => $tags[0]['name'] ?? null]
        );
    }

    /**
     * Skip check for vendors that don't support auto-checking.
     */
    protected function skipCheck(Vendor $vendor): array
    {
        $message = match (true) {
            $vendor->isLicensed() => 'Licensed software - manual check required',
            $vendor->isPlugin() => 'Plugin - check vendor marketplace manually',
            ! $vendor->git_repo_url => 'No Git repository URL configured',
            default => 'Unsupported source type for auto-checking',
        };

        return [
            'status' => 'skipped',
            'current' => $vendor->current_version,
            'latest' => null,
            'has_update' => false,
            'message' => $message,
        ];
    }

    /**
     * Build the result array.
     */
    protected function buildResult(Vendor $vendor, ?string $latestVersion, array $releaseInfo = []): array
    {
        if (! $latestVersion) {
            return $this->errorResult('Could not determine latest version');
        }

        $currentVersion = $this->normaliseVersion($vendor->current_version ?? '');
        $hasUpdate = $currentVersion && version_compare($latestVersion, $currentVersion, '>');

        // Store latest version info on vendor if new
        if ($hasUpdate) {
            Log::info('Uptelligence: New version detected', [
                'vendor' => $vendor->slug,
                'current' => $currentVersion,
                'latest' => $latestVersion,
            ]);
        }

        return [
            'status' => 'success',
            'current' => $currentVersion,
            'latest' => $latestVersion,
            'has_update' => $hasUpdate,
            'release_info' => $releaseInfo,
        ];
    }

    /**
     * Create an update todo when new version is detected.
     */
    protected function createUpdateTodo(Vendor $vendor, string $newVersion): void
    {
        // Check if we already have a pending todo for this version
        $existing = UpstreamTodo::where('vendor_id', $vendor->id)
            ->where('to_version', $newVersion)
            ->whereIn('status', [UpstreamTodo::STATUS_PENDING, UpstreamTodo::STATUS_IN_PROGRESS])
            ->exists();

        if ($existing) {
            return;
        }

        // Create new todo
        UpstreamTodo::create([
            'vendor_id' => $vendor->id,
            'from_version' => $vendor->current_version,
            'to_version' => $newVersion,
            'type' => UpstreamTodo::TYPE_DEPENDENCY,
            'status' => UpstreamTodo::STATUS_PENDING,
            'title' => "Update {$vendor->name} to {$newVersion}",
            'description' => "A new version of {$vendor->name} is available.\n\n"
                ."Current: {$vendor->current_version}\n"
                ."Latest: {$newVersion}\n\n"
                .'Review the changelog and update as appropriate.',
            'priority' => 5,
            'effort' => UpstreamTodo::EFFORT_MEDIUM,
            'tags' => ['auto-detected', 'update-available'],
        ]);

        Log::info('Uptelligence: Created update todo', [
            'vendor' => $vendor->slug,
            'from' => $vendor->current_version,
            'to' => $newVersion,
        ]);
    }

    /**
     * Build an error result.
     */
    protected function errorResult(string $message): array
    {
        return [
            'status' => 'error',
            'current' => null,
            'latest' => null,
            'has_update' => false,
            'message' => $message,
        ];
    }

    /**
     * Build a rate-limited result.
     */
    protected function rateLimitedResult(int $seconds): array
    {
        return [
            'status' => 'rate_limited',
            'current' => null,
            'latest' => null,
            'has_update' => false,
            'message' => "Rate limit exceeded. Retry after {$seconds} seconds",
        ];
    }

    /**
     * Check if URL is a GitHub URL.
     */
    protected function isGitHubUrl(?string $url): bool
    {
        if (! $url) {
            return false;
        }

        return str_contains($url, 'github.com');
    }

    /**
     * Check if URL is a Gitea URL.
     */
    protected function isGiteaUrl(?string $url): bool
    {
        if (! $url) {
            return false;
        }

        $giteaUrl = config('upstream.gitea.url', 'https://git.host.uk');

        return str_contains($url, parse_url($giteaUrl, PHP_URL_HOST) ?? '');
    }

    /**
     * Parse GitHub URL to extract owner/repo.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function parseGitHubUrl(string $url): ?array
    {
        // Match github.com/owner/repo patterns
        if (preg_match('#github\.com[/:]([^/]+)/([^/.]+)#i', $url, $matches)) {
            return [$matches[1], rtrim($matches[2], '.git')];
        }

        return null;
    }

    /**
     * Parse Gitea URL to extract base URL, owner, and repo.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    protected function parseGiteaUrl(string $url): ?array
    {
        // Match gitea URLs like https://git.host.uk/owner/repo
        if (preg_match('#(https?://[^/]+)/([^/]+)/([^/.]+)#i', $url, $matches)) {
            return [$matches[1], $matches[2], rtrim($matches[3], '.git')];
        }

        return null;
    }

    /**
     * Normalise version string (remove 'v' prefix, etc.).
     */
    protected function normaliseVersion(?string $version): ?string
    {
        if (! $version) {
            return null;
        }

        // Remove leading 'v' or 'V'
        $version = ltrim($version, 'vV');

        // Remove any leading/trailing whitespace
        $version = trim($version);

        return $version ?: null;
    }
}
