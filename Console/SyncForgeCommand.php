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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Sync Forge repositories into the Uptelligence vendor registry.
 *
 * Fetches all repositories from a Forgejo organisation and registers
 * them as OSS vendors for version tracking and change detection.
 */
class SyncForgeCommand extends Command
{
    protected $signature = 'upstream:sync-forge
                            {--org= : Forgejo organisation (default: from config)}
                            {--dry-run : Show what would be registered without acting}';

    protected $description = 'Register Forge repositories as Uptelligence vendors';

    public function handle(): int
    {
        $baseUrl = config('upstream.gitea.url', 'https://forge.lthn.ai');
        $token = config('upstream.gitea.token');
        $org = $this->option('org') ?? config('upstream.gitea.org', 'core');
        $dryRun = $this->option('dry-run');

        if (! $token) {
            $this->error('No Forge token configured. Set FORGE_TOKEN or GITEA_TOKEN in .env');

            return self::FAILURE;
        }

        $this->info("Fetching repositories from {$baseUrl}/api/v1/orgs/{$org}/repos...");

        $repos = $this->fetchAllRepos($baseUrl, $token, $org);

        if ($repos === null) {
            $this->error('Failed to fetch repositories from Forge API.');

            return self::FAILURE;
        }

        $this->info(count($repos) . ' repositories found.');
        $this->newLine();

        $created = 0;
        $skipped = 0;
        $updated = 0;

        foreach ($repos as $repo) {
            $fullName = $repo['full_name'];
            $slug = Str::slug($fullName, '-');
            $repoUrl = "{$baseUrl}/{$fullName}";

            $existing = Vendor::withTrashed()->where('slug', $slug)->first();

            if ($existing && ! $existing->trashed()) {
                // Update git_repo_url if it changed
                if ($existing->git_repo_url !== $repoUrl) {
                    if (! $dryRun) {
                        $existing->update(['git_repo_url' => $repoUrl]);
                    }
                    $this->line('  <fg=blue>Updated</> ' . $fullName);
                    $updated++;
                } else {
                    $this->line('  <fg=gray>Exists</>  ' . $fullName);
                    $skipped++;
                }

                continue;
            }

            if ($existing && $existing->trashed()) {
                if (! $dryRun) {
                    $existing->restore();
                    $existing->update([
                        'git_repo_url' => $repoUrl,
                        'is_active' => true,
                    ]);
                }
                $this->line('  <fg=yellow>Restored</> ' . $fullName);
                $created++;

                continue;
            }

            // Detect language hint for platform
            $platform = $this->detectPlatform($repo);

            if (! $dryRun) {
                Vendor::create([
                    'slug' => $slug,
                    'name' => $repo['name'],
                    'vendor_name' => $org,
                    'source_type' => Vendor::SOURCE_OSS,
                    'plugin_platform' => $platform,
                    'git_repo_url' => $repoUrl,
                    'current_version' => null,
                    'target_repo' => $fullName,
                    'target_branch' => $repo['default_branch'] ?? 'main',
                    'is_active' => true,
                    'path_mapping' => [],
                    'ignored_paths' => ['.git/*', 'vendor/*', 'node_modules/*'],
                    'priority_paths' => [],
                ]);
            }

            $this->line('  <fg=green>Created</> ' . $fullName . ' (' . ($platform ?? 'oss') . ')');
            $created++;
        }

        $this->newLine();
        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Sync complete: {$created} created, {$updated} updated, {$skipped} unchanged.");

        return self::SUCCESS;
    }

    /**
     * Fetch all repositories from a Forge organisation, handling pagination.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchAllRepos(string $baseUrl, string $token, string $org): ?array
    {
        $allRepos = [];
        $page = 1;
        $limit = 50;

        do {
            $response = Http::withHeaders(['Authorization' => "token {$token}"])
                ->timeout(30)
                ->get("{$baseUrl}/api/v1/orgs/{$org}/repos", [
                    'page' => $page,
                    'limit' => $limit,
                ]);

            if (! $response->successful()) {
                $this->error("Forge API error: {$response->status()}");

                return null;
            }

            $repos = $response->json();

            if (empty($repos)) {
                break;
            }

            $allRepos = array_merge($allRepos, $repos);
            $page++;
        } while (count($repos) === $limit);

        return $allRepos;
    }

    /**
     * Detect the platform type from repository metadata.
     */
    private function detectPlatform(array $repo): ?string
    {
        $name = $repo['name'] ?? '';

        if (str_starts_with($name, 'php-') || str_starts_with($name, 'core-')) {
            return Vendor::PLATFORM_LARAVEL;
        }

        return Vendor::PLATFORM_OTHER;
    }
}
