<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Core\Mod\Uptelligence\Models\AnalysisLog;
use Core\Mod\Uptelligence\Models\DiffCache;
use Core\Mod\Uptelligence\Models\Vendor;
use Core\Mod\Uptelligence\Models\VersionRelease;

/**
 * Diff Analyzer Service - analyses differences between vendor versions.
 *
 * Detects file changes and caches diffs for AI analysis.
 */
class DiffAnalyzerService
{
    protected Vendor $vendor;

    protected string $previousPath;

    protected string $currentPath;

    public function __construct(Vendor $vendor)
    {
        $this->vendor = $vendor;
    }

    /**
     * Analyse differences between two versions.
     */
    public function analyze(string $previousVersion, string $currentVersion): VersionRelease
    {
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

        return $files;
    }

    /**
     * Check if a file should be ignored.
     */
    protected function shouldIgnore(string $path): bool
    {
        return $this->vendor->shouldIgnorePath($path);
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

                DiffCache::create([
                    'version_release_id' => $release->id,
                    'file_path' => $file,
                    'change_type' => DiffCache::CHANGE_ADDED,
                    'new_content' => $content,
                    'category' => DiffCache::detectCategory($file),
                ]);
                $stats['added']++;
            }

            // Cache modified files with diff
            foreach ($changes['modified'] as $file) {
                $diff = $this->generateDiff($file);

                DiffCache::create([
                    'version_release_id' => $release->id,
                    'file_path' => $file,
                    'change_type' => DiffCache::CHANGE_MODIFIED,
                    'diff_content' => $diff,
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
    protected function generateDiff(string $file): string
    {
        // Validate paths before using them
        $prevPath = $this->validatePath($file, $this->previousPath);
        $currPath = $this->validatePath($file, $this->currentPath);

        // Use array syntax to prevent shell injection - paths are passed as separate arguments
        // rather than being interpolated into a shell command string
        $result = Process::run(['diff', '-u', $prevPath, $currPath]);

        return $result->output();
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
