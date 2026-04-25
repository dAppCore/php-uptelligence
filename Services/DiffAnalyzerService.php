<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Services;

use Core\Mod\Uptelligence\Data\DiffResult;
use Core\Mod\Uptelligence\Models\AnalysisLog;
use Core\Mod\Uptelligence\Models\Asset;
use Core\Mod\Uptelligence\Models\DiffCache;
use Core\Mod\Uptelligence\Models\Vendor;
use Core\Mod\Uptelligence\Models\VersionRelease;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Throwable;

/**
 * Diff Analyzer Service - analyses differences between vendor versions.
 *
 * Detects file changes and caches diffs for AI analysis.
 */
class DiffAnalyzerService
{
    protected const MAX_DIFF_BYTES = 51200;

    protected const IGNORED_PATTERNS = [
        '.git/*',
        '.svn/*',
        '.hg/*',
        'vendor/*',
        'node_modules/*',
        'dist/*',
        'build/*',
        'public/build/*',
        'storage/framework/*',
        '*.min.js',
        '*.min.css',
        '*.map',
        '*.log',
        '.ds_store',
    ];

    protected const BINARY_EXTENSIONS = [
        '7z',
        'avif',
        'bmp',
        'bz2',
        'class',
        'eot',
        'exe',
        'gif',
        'gz',
        'ico',
        'jar',
        'jpeg',
        'jpg',
        'mp3',
        'mp4',
        'otf',
        'pdf',
        'png',
        'tar',
        'ttf',
        'webm',
        'webp',
        'woff',
        'woff2',
        'zip',
    ];

    protected ?Vendor $vendor;

    protected string $previousPath;

    protected string $currentPath;

    public function __construct(?Vendor $vendor = null)
    {
        $this->vendor = $vendor;
    }

    /**
     * Generate a structured diff between two local version directories or git refs.
     */
    public function diff(string $oldVersion, string $newVersion): DiffResult
    {
        $this->validateVersionInput($oldVersion);
        $this->validateVersionInput($newVersion);

        $cached = $this->cachedDiffResult($oldVersion, $newVersion);
        if ($cached instanceof DiffResult) {
            return $cached;
        }

        if ($this->canResolveDirectoryPair($oldVersion, $newVersion)) {
            [$this->previousPath, $this->currentPath] = $this->resolveDirectoryPair($oldVersion, $newVersion);

            $result = $this->buildDiffResult(
                changes: $this->getFileChanges(),
                fromVersion: $oldVersion,
                toVersion: $newVersion,
            );

            return $this->persistDiffResult($result, $oldVersion, $newVersion) ?? $result;
        }

        if ($this->canDiffGitRefs($oldVersion, $newVersion)) {
            return $this->diffGitRefs($oldVersion, $newVersion);
        }

        throw new InvalidArgumentException('Both versions must resolve to local directories or valid git refs.');
    }

    /**
     * RFC-compatible entry point for asset-aware diff generation.
     */
    public function generateDiff(mixed $asset, string $fromVersion, string $toVersion): DiffResult
    {
        if ($asset instanceof Vendor) {
            return (new self($asset))->diff($fromVersion, $toVersion);
        }

        if ($asset instanceof Asset && is_string($asset->install_path) && $asset->install_path !== '') {
            return $this->diff(
                rtrim($asset->install_path, '/').'/'.$fromVersion,
                rtrim($asset->install_path, '/').'/'.$toVersion,
            );
        }

        return $this->diff($fromVersion, $toVersion);
    }

    /**
     * Analyse differences between two versions.
     */
    public function analyze(string $previousVersion, string $currentVersion): VersionRelease
    {
        if (! $this->vendor instanceof Vendor) {
            throw new InvalidArgumentException('A vendor is required to analyse stored vendor versions.');
        }

        $this->previousPath = $this->vendor->getStoragePath($previousVersion);
        $this->currentPath = $this->vendor->getStoragePath($currentVersion);

        // Create version release record
        $release = VersionRelease::create([
            'vendor_id' => $this->vendor->id,
            'version' => $currentVersion,
            'previous_version' => $previousVersion,
            'storage_path' => $this->currentPath,
        ]);

        AnalysisLog::logAnalysisStarted($release);

        try {
            // Get all file changes
            $changes = $this->getFileChanges();

            // Cache the diffs
            $stats = $this->cacheDiffs($release, $changes);

            // Update release with stats
            $release->update([
                'files_added' => $stats['added'],
                'files_modified' => $stats['modified'],
                'files_removed' => $stats['removed'],
                'analyzed_at' => now(),
            ]);

            AnalysisLog::logAnalysisCompleted($release, $stats);

            return $release;
        } catch (\Exception $e) {
            AnalysisLog::logAnalysisFailed($release, $e->getMessage());
            throw $e;
        }
    }

    protected function validateVersionInput(string $version): void
    {
        if ($version === '' || str_contains($version, "\0")) {
            throw new InvalidArgumentException('Version path/ref must be a non-empty string without null bytes.');
        }
    }

    protected function canResolveDirectoryPair(string $oldVersion, string $newVersion): bool
    {
        if (File::isDirectory($oldVersion) && File::isDirectory($newVersion)) {
            return true;
        }

        if (! $this->vendor instanceof Vendor) {
            return false;
        }

        return File::isDirectory($this->vendor->getStoragePath($oldVersion))
            && File::isDirectory($this->vendor->getStoragePath($newVersion));
    }

    protected function resolveDirectoryPair(string $oldVersion, string $newVersion): array
    {
        if (File::isDirectory($oldVersion) && File::isDirectory($newVersion)) {
            return [
                realpath($oldVersion) ?: $oldVersion,
                realpath($newVersion) ?: $newVersion,
            ];
        }

        if ($this->vendor instanceof Vendor) {
            return [
                $this->vendor->getStoragePath($oldVersion),
                $this->vendor->getStoragePath($newVersion),
            ];
        }

        throw new InvalidArgumentException('Unable to resolve version directories.');
    }

    protected function cachedDiffResult(string $fromVersion, string $toVersion): ?DiffResult
    {
        if (! $this->vendor instanceof Vendor || ! $this->vendor->exists) {
            return null;
        }

        try {
            $release = VersionRelease::query()
                ->where('vendor_id', $this->vendor->getKey())
                ->where('version', $toVersion)
                ->where('previous_version', $fromVersion)
                ->with('diffs')
                ->first();
        } catch (QueryException) {
            return null;
        }

        if (! $release instanceof VersionRelease || $release->diffs->isEmpty()) {
            return null;
        }

        return $this->diffResultFromCachedRelease($release);
    }

    protected function diffResultFromCachedRelease(VersionRelease $release): DiffResult
    {
        $byFile = $release->diffs
            ->map(fn (DiffCache $diff): array => [
                'cache_id' => $diff->id,
                'file_path' => $diff->file_path,
                'change_type' => $diff->change_type,
                'category' => $diff->category,
                'diff_content' => $diff->diff_content ?? $diff->new_content,
                'new_content' => $diff->new_content,
                'lines_added' => $diff->lines_added,
                'lines_removed' => $diff->lines_removed,
                'metadata' => $diff->metadata ?? [],
            ])
            ->values()
            ->all();

        return new DiffResult(
            changedFiles: array_column($byFile, 'file_path'),
            breakingChanges: $this->detectBreakingChanges($byFile),
            migrationSteps: $this->buildMigrationSteps($byFile),
            filesChanged: count($byFile),
            additions: (int) $release->diffs->sum('lines_added'),
            deletions: (int) $release->diffs->sum('lines_removed'),
            byFile: $byFile,
            metadata: [
                'cache_key' => $this->makeCacheKey($release->previous_version ?? '', $release->version, $byFile),
                'cached' => true,
                'from_version' => $release->previous_version,
                'to_version' => $release->version,
                'version_release_id' => $release->id,
                'diff_cache_ids' => $release->diffs->pluck('id')->all(),
            ],
        );
    }

    /**
     * Get all file changes between versions using diff.
     */
    protected function getFileChanges(): array
    {
        $changes = [
            'added' => [],
            'modified' => [],
            'removed' => [],
        ];

        // Get list of all files in both versions
        $previousFiles = $this->getFileList($this->previousPath);
        $currentFiles = $this->getFileList($this->currentPath);

        // Find added files
        $addedFiles = array_diff($currentFiles, $previousFiles);
        foreach ($addedFiles as $file) {
            if (! $this->shouldIgnore($file)) {
                $changes['added'][] = $file;
            }
        }

        // Find removed files
        $removedFiles = array_diff($previousFiles, $currentFiles);
        foreach ($removedFiles as $file) {
            if (! $this->shouldIgnore($file)) {
                $changes['removed'][] = $file;
            }
        }

        // Find modified files
        $commonFiles = array_intersect($previousFiles, $currentFiles);
        foreach ($commonFiles as $file) {
            if ($this->shouldIgnore($file)) {
                continue;
            }

            $prevPath = $this->previousPath.'/'.$file;
            $currPath = $this->currentPath.'/'.$file;

            if ($this->filesAreDifferent($prevPath, $currPath)) {
                $changes['modified'][] = $file;
            }
        }

        return $changes;
    }

    /**
     * Get list of all files in a directory recursively.
     */
    protected function getFileList(string $basePath): array
    {
        if (! File::isDirectory($basePath)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = str_replace($basePath.'/', '', $file->getPathname());
                $files[] = $relativePath;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Check if a file should be ignored.
     */
    protected function shouldIgnore(string $path): bool
    {
        $normalised = strtolower(ltrim(str_replace('\\', '/', $path), '/'));

        foreach (self::IGNORED_PATTERNS as $pattern) {
            if (fnmatch($pattern, $normalised)) {
                return true;
            }
        }

        if (in_array(strtolower(pathinfo($normalised, PATHINFO_EXTENSION)), self::BINARY_EXTENSIONS, true)) {
            return true;
        }

        return $this->vendor?->shouldIgnorePath($path) ?? false;
    }

    /**
     * Check if two files are different.
     */
    protected function filesAreDifferent(string $path1, string $path2): bool
    {
        if (! File::exists($path1) || ! File::exists($path2)) {
            return true;
        }

        // Quick hash comparison
        return md5_file($path1) !== md5_file($path2);
    }

    protected function buildDiffResult(array $changes, string $fromVersion, string $toVersion): DiffResult
    {
        $byFile = [];

        foreach ($changes as $changeType => $files) {
            sort($files);

            foreach ($files as $file) {
                $byFile[] = $this->buildFileDiffEntry($file, $changeType);
            }
        }

        $additions = array_sum(array_column($byFile, 'lines_added'));
        $deletions = array_sum(array_column($byFile, 'lines_removed'));
        $cacheKey = $this->makeCacheKey($fromVersion, $toVersion, $byFile);

        return new DiffResult(
            changedFiles: array_column($byFile, 'file_path'),
            breakingChanges: $this->detectBreakingChanges($byFile),
            migrationSteps: $this->buildMigrationSteps($byFile),
            filesChanged: count($byFile),
            additions: (int) $additions,
            deletions: (int) $deletions,
            byFile: $byFile,
            metadata: [
                'cache_key' => $cacheKey,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'previous_path' => $this->previousPath,
                'current_path' => $this->currentPath,
            ],
        );
    }

    protected function buildFileDiffEntry(string $file, string $changeType): array
    {
        $diff = $this->generateFileDiff($file, $changeType);
        $lineStats = $this->countDiffLines($diff);

        if ($changeType === DiffCache::CHANGE_ADDED && $lineStats['added'] === 0) {
            $lineStats['added'] = $this->countContentLines($this->currentPath.'/'.$file);
        }

        if ($changeType === DiffCache::CHANGE_REMOVED && $lineStats['removed'] === 0) {
            $lineStats['removed'] = $this->countContentLines($this->previousPath.'/'.$file);
        }

        return [
            'file_path' => $file,
            'change_type' => $changeType,
            'category' => DiffCache::detectCategory($file),
            'diff_content' => $diff,
            'new_content' => $changeType === DiffCache::CHANGE_ADDED
                ? $this->truncateDiff(File::get($this->currentPath.'/'.$file))
                : null,
            'lines_added' => $lineStats['added'],
            'lines_removed' => $lineStats['removed'],
            'metadata' => [
                'truncated' => strlen($diff) >= self::MAX_DIFF_BYTES,
            ],
        ];
    }

    protected function detectBreakingChanges(array $byFile): array
    {
        $breaking = [];

        foreach ($byFile as $file) {
            $path = (string) ($file['file_path'] ?? '');
            $changeType = (string) ($file['change_type'] ?? '');
            $category = (string) ($file['category'] ?? DiffCache::CATEGORY_OTHER);
            $diff = (string) ($file['diff_content'] ?? '');

            if ($changeType === DiffCache::CHANGE_REMOVED) {
                $breaking[] = "Removed file {$path}";
            }

            if (in_array($category, [
                DiffCache::CATEGORY_API,
                DiffCache::CATEGORY_CONFIG,
                DiffCache::CATEGORY_MIGRATION,
                DiffCache::CATEGORY_ROUTE,
                DiffCache::CATEGORY_SECURITY,
            ], true)) {
                $breaking[] = "Review {$path} for {$category} compatibility changes";
            }

            if (preg_match('/^-\s*(public|protected)\s+function\s+\w+\s*\(/m', $diff) === 1) {
                $breaking[] = "Public or protected method signature changed in {$path}";
            }

            if (preg_match('/^-\s*(class|interface|trait)\s+\w+/m', $diff) === 1) {
                $breaking[] = "Class, interface, or trait declaration changed in {$path}";
            }

            if ($path === 'composer.json' && preg_match('/^-\s*"[^"]+"\s*:\s*"[\^~]?\d+/m', $diff) === 1) {
                $breaking[] = 'Composer dependency constraints changed';
            }
        }

        return array_values(array_unique($breaking));
    }

    protected function buildMigrationSteps(array $byFile): array
    {
        if ($byFile === []) {
            return [];
        }

        $steps = [
            'Review the generated unified diffs before porting the upstream changes.',
        ];

        $categories = array_values(array_unique(array_filter(array_column($byFile, 'category'))));

        if (in_array(DiffCache::CATEGORY_MIGRATION, $categories, true)) {
            $steps[] = 'Run database migrations in a disposable environment and verify schema compatibility.';
        }

        if (in_array(DiffCache::CATEGORY_CONFIG, $categories, true)) {
            $steps[] = 'Compare configuration defaults and update environment-specific overrides.';
        }

        if (in_array(DiffCache::CATEGORY_SECURITY, $categories, true)) {
            $steps[] = 'Prioritise security-related changes and add regression coverage around authentication and permissions.';
        }

        if (collect($byFile)->contains(fn (array $file): bool => $file['change_type'] === DiffCache::CHANGE_REMOVED)) {
            $steps[] = 'Search downstream code for references to removed files before upgrading.';
        }

        $steps[] = 'Run the package test suite and targeted smoke tests after applying the upgrade.';

        return array_values(array_unique($steps));
    }

    protected function makeCacheKey(string $fromVersion, string $toVersion, array $byFile): string
    {
        return hash('sha256', json_encode([
            'from' => $fromVersion,
            'to' => $toVersion,
            'files' => collect($byFile)
                ->map(fn (array $file): array => [
                    'path' => $file['file_path'] ?? '',
                    'type' => $file['change_type'] ?? '',
                    'hash' => hash('sha256', (string) ($file['diff_content'] ?? '')),
                ])
                ->values()
                ->all(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    protected function persistDiffResult(DiffResult $result, string $fromVersion, string $toVersion): ?DiffResult
    {
        if (! $this->vendor instanceof Vendor || ! $this->vendor->exists) {
            return null;
        }

        try {
            $release = null;
            $cacheIds = [];
            $byFileWithCacheIds = [];

            DB::transaction(function () use ($result, $fromVersion, $toVersion, &$release, &$cacheIds, &$byFileWithCacheIds): void {
                $release = VersionRelease::updateOrCreate(
                    [
                        'vendor_id' => $this->vendor->getKey(),
                        'version' => $toVersion,
                    ],
                    [
                        'previous_version' => $fromVersion,
                        'storage_path' => $this->currentPath,
                        'files_added' => collect($result->byFile)->where('change_type', DiffCache::CHANGE_ADDED)->count(),
                        'files_modified' => collect($result->byFile)->where('change_type', DiffCache::CHANGE_MODIFIED)->count(),
                        'files_removed' => collect($result->byFile)->where('change_type', DiffCache::CHANGE_REMOVED)->count(),
                        'analyzed_at' => now(),
                    ],
                );

                DiffCache::where('version_release_id', $release->id)->delete();

                foreach ($result->byFile as $file) {
                    $cache = DiffCache::create([
                        'version_release_id' => $release->id,
                        'file_path' => $file['file_path'],
                        'change_type' => $file['change_type'],
                        'category' => $file['category'],
                        'diff_content' => $file['diff_content'],
                        'new_content' => $file['new_content'] ?? null,
                        'lines_added' => $file['lines_added'],
                        'lines_removed' => $file['lines_removed'],
                        'metadata' => array_merge($file['metadata'] ?? [], [
                            'analysis_cache_key' => $result->cacheKey(),
                        ]),
                    ]);

                    $cacheIds[] = $cache->id;
                    $byFileWithCacheIds[] = array_merge($file, ['cache_id' => $cache->id]);
                }
            });

            if (! $release instanceof VersionRelease) {
                return null;
            }

            return new DiffResult(
                changedFiles: $result->changedFiles,
                breakingChanges: $result->breakingChanges,
                migrationSteps: $result->migrationSteps,
                filesChanged: $result->filesChanged,
                additions: $result->additions,
                deletions: $result->deletions,
                byFile: $byFileWithCacheIds,
                metadata: array_merge($result->metadata, [
                    'version_release_id' => $release->id,
                    'diff_cache_ids' => $cacheIds,
                ]),
            );
        } catch (Throwable $e) {
            Log::warning('Uptelligence: failed to persist diff cache', [
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Cache all diffs in the database.
     *
     * Uses a database transaction to ensure atomic operations -
     * if any diff fails to save, all changes are rolled back.
     */
    protected function cacheDiffs(VersionRelease $release, array $changes): array
    {
        $stats = ['added' => 0, 'modified' => 0, 'removed' => 0];

        DB::transaction(function () use ($release, $changes, &$stats) {
            // Cache added files
            foreach ($changes['added'] as $file) {
                $filePath = $this->currentPath.'/'.$file;
                $content = File::exists($filePath) ? File::get($filePath) : null;
                $content = is_string($content) ? $this->truncateDiff($content) : null;
                $lineStats = $this->countContentLines($content ?? '');

                DiffCache::create([
                    'version_release_id' => $release->id,
                    'file_path' => $file,
                    'change_type' => DiffCache::CHANGE_ADDED,
                    'new_content' => $content,
                    'lines_added' => $lineStats,
                    'lines_removed' => 0,
                    'category' => DiffCache::detectCategory($file),
                ]);
                $stats['added']++;
            }

            // Cache modified files with diff
            foreach ($changes['modified'] as $file) {
                $diff = $this->generateFileDiff($file);
                $lineStats = $this->countDiffLines($diff);

                DiffCache::create([
                    'version_release_id' => $release->id,
                    'file_path' => $file,
                    'change_type' => DiffCache::CHANGE_MODIFIED,
                    'diff_content' => $diff,
                    'lines_added' => $lineStats['added'],
                    'lines_removed' => $lineStats['removed'],
                    'category' => DiffCache::detectCategory($file),
                ]);
                $stats['modified']++;
            }

            // Cache removed files
            foreach ($changes['removed'] as $file) {
                DiffCache::create([
                    'version_release_id' => $release->id,
                    'file_path' => $file,
                    'change_type' => DiffCache::CHANGE_REMOVED,
                    'category' => DiffCache::detectCategory($file),
                ]);
                $stats['removed']++;
            }
        });

        return $stats;
    }

    /**
     * Validate that a path is safe and doesn't contain path traversal attempts.
     *
     * @throws InvalidArgumentException if path is invalid
     */
    protected function validatePath(string $path, string $basePath): string
    {
        // Check for path traversal attempts
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            Log::warning('Uptelligence: Path traversal attempt detected', [
                'path' => $path,
                'basePath' => $basePath,
            ]);
            throw new InvalidArgumentException('Invalid path: path traversal not allowed');
        }

        $fullPath = $basePath.'/'.$path;
        $realPath = realpath($fullPath);
        $realBasePath = realpath($basePath);

        // If path doesn't exist yet, validate the directory portion
        if ($realPath === false) {
            $dirPath = dirname($fullPath);
            $realDirPath = realpath($dirPath);

            if ($realDirPath === false || ! str_starts_with($realDirPath, $realBasePath)) {
                Log::warning('Uptelligence: Path escapes base directory', [
                    'path' => $path,
                    'basePath' => $basePath,
                ]);
                throw new InvalidArgumentException('Invalid path: must be within base directory');
            }

            return $fullPath;
        }

        // Ensure the real path is within the base path
        if (! str_starts_with($realPath, $realBasePath)) {
            Log::warning('Uptelligence: Path escapes base directory', [
                'path' => $path,
                'realPath' => $realPath,
                'basePath' => $basePath,
            ]);
            throw new InvalidArgumentException('Invalid path: must be within base directory');
        }

        return $realPath;
    }

    /**
     * Generate diff for a file.
     *
     * Uses array-based Process invocation to prevent shell injection.
     * Validates paths to prevent path traversal attacks.
     */
    protected function generateFileDiff(string $file, string $changeType = DiffCache::CHANGE_MODIFIED): string
    {
        $prevPath = $changeType === DiffCache::CHANGE_ADDED
            ? '/dev/null'
            : $this->validatePath($file, $this->previousPath);
        $currPath = $changeType === DiffCache::CHANGE_REMOVED
            ? '/dev/null'
            : $this->validatePath($file, $this->currentPath);

        if (($prevPath !== '/dev/null' && $this->isProbablyBinary($prevPath)) ||
            ($currPath !== '/dev/null' && $this->isProbablyBinary($currPath))) {
            return '';
        }

        // Use array syntax to prevent shell injection - paths are passed as separate arguments
        // rather than being interpolated into a shell command string
        $result = Process::run(['diff', '-u', $prevPath, $currPath]);

        return $this->truncateDiff($result->output() ?: $result->errorOutput());
    }

    protected function truncateDiff(string $content): string
    {
        if (strlen($content) <= self::MAX_DIFF_BYTES) {
            return $content;
        }

        return substr($content, 0, self::MAX_DIFF_BYTES)."\n[diff truncated at 50KB]\n";
    }

    protected function countDiffLines(string $diff): array
    {
        $added = 0;
        $removed = 0;

        foreach (preg_split('/\R/', $diff) ?: [] as $line) {
            if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
                continue;
            }

            if (str_starts_with($line, '+')) {
                $added++;
            } elseif (str_starts_with($line, '-')) {
                $removed++;
            }
        }

        return ['added' => $added, 'removed' => $removed];
    }

    protected function countContentLines(string $contentOrPath): int
    {
        $content = File::exists($contentOrPath) ? File::get($contentOrPath) : $contentOrPath;

        if ($content === '') {
            return 0;
        }

        return substr_count($content, "\n") + (str_ends_with($content, "\n") ? 0 : 1);
    }

    protected function isProbablyBinary(string $path): bool
    {
        if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::BINARY_EXTENSIONS, true)) {
            return true;
        }

        $chunk = @file_get_contents($path, false, null, 0, 512);

        return is_string($chunk) && str_contains($chunk, "\0");
    }

    protected function canDiffGitRefs(string $oldVersion, string $newVersion): bool
    {
        $insideGit = Process::run(['git', 'rev-parse', '--is-inside-work-tree']);
        if (! $insideGit->successful() || trim($insideGit->output()) !== 'true') {
            return false;
        }

        $oldRef = Process::run(['git', 'rev-parse', '--verify', '--quiet', $oldVersion.'^{commit}']);
        $newRef = Process::run(['git', 'rev-parse', '--verify', '--quiet', $newVersion.'^{commit}']);

        return $oldRef->successful() && $newRef->successful();
    }

    protected function diffGitRefs(string $oldVersion, string $newVersion): DiffResult
    {
        $nameStatus = Process::run(['git', 'diff', '--name-status', '--find-renames', $oldVersion, $newVersion]);
        if (! $nameStatus->successful()) {
            throw new InvalidArgumentException('Unable to diff git refs.');
        }

        $byFile = [];
        foreach (array_filter(preg_split('/\R/', trim($nameStatus->output())) ?: []) as $line) {
            $parts = preg_split('/\s+/', $line) ?: [];
            $status = $parts[0] ?? '';
            $file = $parts[count($parts) - 1] ?? '';

            if ($file === '' || $this->shouldIgnore($file)) {
                continue;
            }

            $changeType = match ($status[0] ?? 'M') {
                'A' => DiffCache::CHANGE_ADDED,
                'D' => DiffCache::CHANGE_REMOVED,
                default => DiffCache::CHANGE_MODIFIED,
            };

            $diff = Process::run(['git', 'diff', '--unified=3', $oldVersion, $newVersion, '--', $file])->output();
            $diff = $this->truncateDiff($diff);
            $lineStats = $this->countDiffLines($diff);

            $byFile[] = [
                'file_path' => $file,
                'change_type' => $changeType,
                'category' => DiffCache::detectCategory($file),
                'diff_content' => $diff,
                'lines_added' => $lineStats['added'],
                'lines_removed' => $lineStats['removed'],
                'metadata' => ['source' => 'git'],
            ];
        }

        return new DiffResult(
            changedFiles: array_column($byFile, 'file_path'),
            breakingChanges: $this->detectBreakingChanges($byFile),
            migrationSteps: $this->buildMigrationSteps($byFile),
            filesChanged: count($byFile),
            additions: (int) array_sum(array_column($byFile, 'lines_added')),
            deletions: (int) array_sum(array_column($byFile, 'lines_removed')),
            byFile: $byFile,
            metadata: [
                'cache_key' => $this->makeCacheKey($oldVersion, $newVersion, $byFile),
                'from_version' => $oldVersion,
                'to_version' => $newVersion,
                'source' => 'git',
            ],
        );
    }

    /**
     * Get priority files that changed.
     */
    public function getPriorityChanges(VersionRelease $release): Collection
    {
        return $release->diffs()
            ->get()
            ->filter(fn ($diff) => $this->vendor->isPriorityPath($diff->file_path));
    }

    /**
     * Get security-related changes.
     */
    public function getSecurityChanges(VersionRelease $release): Collection
    {
        return $release->diffs()
            ->where('category', DiffCache::CATEGORY_SECURITY)
            ->get();
    }

    /**
     * Generate summary statistics.
     */
    public function getSummary(VersionRelease $release): array
    {
        $diffs = $release->diffs;

        return [
            'total_changes' => $diffs->count(),
            'by_type' => [
                'added' => $diffs->where('change_type', DiffCache::CHANGE_ADDED)->count(),
                'modified' => $diffs->where('change_type', DiffCache::CHANGE_MODIFIED)->count(),
                'removed' => $diffs->where('change_type', DiffCache::CHANGE_REMOVED)->count(),
            ],
            'by_category' => $diffs->groupBy('category')->map->count()->toArray(),
            'priority_files' => $this->getPriorityChanges($release)->count(),
            'security_files' => $this->getSecurityChanges($release)->count(),
        ];
    }
}
