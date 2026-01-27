<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Core\Mod\Uptelligence\Models\Vendor;
use Core\Mod\Uptelligence\Models\VersionRelease;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Vendor Storage Service - manages local and S3 cold storage for vendor versions.
 *
 * Handles archival, retrieval, and cleanup of upstream vendor source files.
 */
class VendorStorageService
{
    protected string $storageMode;

    protected string $bucket;

    protected string $prefix;

    protected string $tempPath;

    protected string $s3Disk;

    public function __construct()
    {
        $this->storageMode = config('upstream.storage.disk', 'local');
        $this->bucket = config('upstream.storage.s3.bucket', 'hostuk');
        $this->prefix = config('upstream.storage.s3.prefix', 'upstream/vendors/');
        $this->tempPath = config('upstream.storage.temp_path', storage_path('app/temp/upstream'));
        $this->s3Disk = config('upstream.storage.s3.disk', 's3');
    }

    /**
     * Check if S3 storage is enabled.
     */
    public function isS3Enabled(): bool
    {
        return $this->storageMode === 's3';
    }

    /**
     * Get the S3 storage disk instance.
     */
    protected function s3(): Filesystem
    {
        return Storage::disk($this->s3Disk);
    }

    /**
     * Get the local storage disk instance.
     */
    protected function local(): Filesystem
    {
        return Storage::disk('local');
    }

    /**
     * Get local path for a vendor version.
     */
    public function getLocalPath(Vendor $vendor, string $version): string
    {
        return storage_path("app/vendors/{$vendor->slug}/{$version}");
    }

    /**
     * Get S3 key for a vendor version archive.
     */
    public function getS3Key(Vendor $vendor, string $version): string
    {
        return "{$this->prefix}{$vendor->slug}/{$version}.tar.gz";
    }

    /**
     * Get temp directory for processing.
     */
    public function getTempPath(?string $suffix = null): string
    {
        $path = $this->tempPath.'/'.Str::uuid();
        if ($suffix) {
            $path .= '/'.$suffix;
        }

        return $path;
    }

    /**
     * Ensure version is available locally for processing.
     * Downloads from S3 if needed.
     */
    public function ensureLocal(VersionRelease $release): string
    {
        $localPath = $this->getLocalPath($release->vendor, $release->version);
        $relativePath = $this->getRelativeLocalPath($release->vendor, $release->version);

        // Already available locally
        if ($this->local()->exists($relativePath) && $this->local()->exists("{$relativePath}/.version_marker")) {
            return $localPath;
        }

        // Need to download from S3
        if ($release->storage_disk === 's3' && $release->s3_key) {
            $this->downloadFromS3($release, $localPath);
            $release->update(['last_downloaded_at' => now()]);

            return $localPath;
        }

        // Check if we have local files but no marker
        if ($this->local()->exists($relativePath)) {
            $this->local()->put("{$relativePath}/.version_marker", $release->version);

            return $localPath;
        }

        throw new RuntimeException(
            "Version {$release->version} not available locally or in S3"
        );
    }

    /**
     * Get relative path for local storage (relative to storage/app).
     */
    protected function getRelativeLocalPath(Vendor $vendor, string $version): string
    {
        return "vendors/{$vendor->slug}/{$version}";
    }

    /**
     * Archive a version to S3 cold storage.
     */
    public function archiveToS3(VersionRelease $release): bool
    {
        if (! $this->isS3Enabled()) {
            return false;
        }

        $localPath = $this->getLocalPath($release->vendor, $release->version);
        $relativePath = $this->getRelativeLocalPath($release->vendor, $release->version);

        if (! $this->local()->exists($relativePath)) {
            throw new RuntimeException("Local path not found: {$localPath}");
        }

        // Create tar.gz archive
        $archivePath = $this->createArchive($localPath, $release->vendor->slug, $release->version);

        // Calculate hash before upload
        $hash = hash_file('sha256', $archivePath);
        $size = filesize($archivePath);

        // Upload to S3
        $s3Key = $this->getS3Key($release->vendor, $release->version);
        $this->uploadToS3($archivePath, $s3Key);

        // Update release record
        $release->update([
            'storage_disk' => 's3',
            's3_key' => $s3Key,
            'file_hash' => $hash,
            'file_size' => $size,
            'archived_at' => now(),
        ]);

        // Cleanup archive file using Storage facade
        $this->local()->delete($this->getRelativeTempPath($archivePath));

        // Optionally delete local files
        if (config('upstream.storage.archive.delete_local_after_archive', true)) {
            $this->deleteLocalIfAllowed($release);
        }

        return true;
    }

    /**
     * Get relative path for a temp file.
     */
    protected function getRelativeTempPath(string $absolutePath): string
    {
        $storagePath = storage_path('app/');

        return str_starts_with($absolutePath, $storagePath)
            ? substr($absolutePath, strlen($storagePath))
            : $absolutePath;
    }

    /**
     * Download a version from S3.
     */
    public function downloadFromS3(VersionRelease $release, ?string $targetPath = null): string
    {
        if (! $release->s3_key) {
            throw new RuntimeException("No S3 key for version {$release->version}");
        }

        $targetPath = $targetPath ?? $this->getLocalPath($release->vendor, $release->version);
        $relativeTempPath = 'temp/upstream/'.Str::uuid().'.tar.gz';

        // Ensure temp directory exists via Storage facade
        $this->local()->makeDirectory(dirname($relativeTempPath));

        $contents = $this->s3()->get($release->s3_key);
        if ($contents === null) {
            Log::error('Uptelligence: Failed to download from S3', [
                's3_key' => $release->s3_key,
                'version' => $release->version,
            ]);
            throw new RuntimeException("Failed to download from S3: {$release->s3_key}");
        }

        $this->local()->put($relativeTempPath, $contents);
        $tempArchive = storage_path("app/{$relativeTempPath}");

        // Verify hash if available
        if ($release->file_hash) {
            $downloadedHash = hash_file('sha256', $tempArchive);
            if ($downloadedHash !== $release->file_hash) {
                $this->local()->delete($relativeTempPath);
                Log::error('Uptelligence: S3 download hash mismatch', [
                    'version' => $release->version,
                    'expected' => $release->file_hash,
                    'actual' => $downloadedHash,
                ]);
                throw new RuntimeException(
                    "Hash mismatch for {$release->version}: expected {$release->file_hash}, got {$downloadedHash}"
                );
            }
        }

        // Ensure target directory exists
        $relativeTargetPath = $this->getRelativeLocalPath($release->vendor, $release->version);
        $this->local()->makeDirectory($relativeTargetPath);

        // Extract archive
        $this->extractArchive($tempArchive, $targetPath);

        // Cleanup temp archive
        $this->local()->delete($relativeTempPath);

        // Add version marker
        $this->local()->put("{$relativeTargetPath}/.version_marker", $release->version);

        return $targetPath;
    }

    /**
     * Create a tar.gz archive of a directory.
     */
    public function createArchive(string $sourcePath, string $vendorSlug, string $version): string
    {
        $relativePath = 'temp/upstream/'.Str::uuid();
        $archiveRelativePath = "{$relativePath}/{$vendorSlug}-{$version}.tar.gz";

        // Ensure directory exists via Storage facade
        $this->local()->makeDirectory($relativePath);

        $archivePath = storage_path("app/{$archiveRelativePath}");

        // Use Symfony Process for safe command execution
        $process = new Process(['tar', '-czf', $archivePath, '-C', $sourcePath, '.']);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::error('Uptelligence: Failed to create archive', [
                'source' => $sourcePath,
                'error' => $process->getErrorOutput(),
            ]);
            throw new RuntimeException('Failed to create archive: '.$process->getErrorOutput());
        }

        return $archivePath;
    }

    /**
     * Extract a tar.gz archive.
     */
    public function extractArchive(string $archivePath, string $targetPath): void
    {
        // Ensure target directory exists via Storage facade
        $relativeTargetPath = str_replace(storage_path('app/'), '', $targetPath);
        if (str_starts_with($relativeTargetPath, '/')) {
            // Absolute path outside storage - use direct mkdir
            if (! is_dir($targetPath)) {
                mkdir($targetPath, 0755, true);
            }
        } else {
            $this->local()->makeDirectory($relativeTargetPath);
        }

        // Use Symfony Process for safe command execution
        $process = new Process(['tar', '-xzf', $archivePath, '-C', $targetPath]);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::error('Uptelligence: Failed to extract archive', [
                'archive' => $archivePath,
                'target' => $targetPath,
                'error' => $process->getErrorOutput(),
            ]);
            throw new RuntimeException('Failed to extract archive: '.$process->getErrorOutput());
        }
    }

    /**
     * Upload a file to S3.
     */
    protected function uploadToS3(string $localPath, string $s3Key): void
    {
        // Read file using Storage facade if path is within storage/app
        $relativePath = $this->getRelativeTempPath($localPath);

        if ($this->local()->exists($relativePath)) {
            $contents = $this->local()->get($relativePath);
        } else {
            // Fallback for absolute paths outside storage
            $contents = file_get_contents($localPath);
        }

        $uploaded = $this->s3()->put($s3Key, $contents, [
            'ContentType' => 'application/gzip',
        ]);

        if (! $uploaded) {
            Log::error('Uptelligence: Failed to upload to S3', ['s3_key' => $s3Key]);
            throw new RuntimeException("Failed to upload to S3: {$s3Key}");
        }
    }

    /**
     * Delete local version files if allowed by retention policy.
     */
    public function deleteLocalIfAllowed(VersionRelease $release): bool
    {
        $keepVersions = config('upstream.storage.archive.keep_local_versions', 2);

        // Get vendor's recent versions
        $recentVersions = VersionRelease::where('vendor_id', $release->vendor_id)
            ->orderByDesc('created_at')
            ->take($keepVersions)
            ->pluck('version')
            ->toArray();

        // Don't delete if in recent list
        if (in_array($release->version, $recentVersions)) {
            return false;
        }

        // Don't delete current or previous version
        $vendor = $release->vendor;
        if ($release->version === $vendor->current_version ||
            $release->version === $vendor->previous_version) {
            return false;
        }

        $relativePath = $this->getRelativeLocalPath($vendor, $release->version);

        if ($this->local()->exists($relativePath)) {
            $this->local()->deleteDirectory($relativePath);

            return true;
        }

        return false;
    }

    /**
     * Extract metadata from a version directory.
     * This metadata can be used for analysis without downloading.
     */
    public function extractMetadata(string $path): array
    {
        $metadata = [
            'file_count' => 0,
            'total_size' => 0,
            'directories' => [],
            'file_types' => [],
            'key_files' => [],
        ];

        if (! File::isDirectory($path)) {
            return $metadata;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $metadata['file_count']++;
                $metadata['total_size'] += $file->getSize();

                $ext = strtolower($file->getExtension());
                $metadata['file_types'][$ext] = ($metadata['file_types'][$ext] ?? 0) + 1;

                // Track key files
                $relativePath = str_replace($path.'/', '', $file->getPathname());
                if ($this->isKeyFile($relativePath)) {
                    $metadata['key_files'][] = $relativePath;
                }
            }
        }

        // Get top-level directories
        $dirs = File::directories($path);
        $metadata['directories'] = array_map(fn ($d) => basename($d), $dirs);

        return $metadata;
    }

    /**
     * Check if a file is considered a "key file" worth tracking in metadata.
     */
    protected function isKeyFile(string $path): bool
    {
        $keyPatterns = [
            'composer.json',
            'package.json',
            'readme.md',
            'readme.txt',
            'changelog.md',
            'changelog.txt',
            'version.php',
            'config/*.php',
            'database/migrations/*',
        ];

        $lowercasePath = strtolower($path);
        foreach ($keyPatterns as $pattern) {
            if (fnmatch($pattern, $lowercasePath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a version exists in S3.
     */
    public function existsInS3(Vendor $vendor, string $version): bool
    {
        $s3Key = $this->getS3Key($vendor, $version);

        return $this->s3()->exists($s3Key);
    }

    /**
     * Check if a version exists locally.
     */
    public function existsLocally(Vendor $vendor, string $version): bool
    {
        $relativePath = $this->getRelativeLocalPath($vendor, $version);

        return $this->local()->exists($relativePath);
    }

    /**
     * Get storage status for a version.
     */
    public function getStorageStatus(VersionRelease $release): array
    {
        $relativePath = $this->getRelativeLocalPath($release->vendor, $release->version);

        return [
            'version' => $release->version,
            'storage_disk' => $release->storage_disk,
            'local_exists' => $this->local()->exists($relativePath),
            's3_exists' => $release->s3_key ? $this->s3()->exists($release->s3_key) : false,
            's3_key' => $release->s3_key,
            'file_size' => $release->file_size,
            'file_hash' => $release->file_hash,
            'archived_at' => $release->archived_at?->toIso8601String(),
            'last_downloaded_at' => $release->last_downloaded_at?->toIso8601String(),
        ];
    }

    /**
     * Cleanup old temp files.
     */
    public function cleanupTemp(): int
    {
        $maxAge = config('upstream.storage.archive.cleanup_after_hours', 24);
        $cutoff = now()->subHours($maxAge);
        $cleaned = 0;

        $tempRelativePath = 'temp/upstream';

        if (! $this->local()->exists($tempRelativePath)) {
            return 0;
        }

        $directories = $this->local()->directories($tempRelativePath);
        foreach ($directories as $dir) {
            $mtime = $this->local()->lastModified($dir);
            if ($mtime < $cutoff->timestamp) {
                $this->local()->deleteDirectory($dir);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Get storage statistics for dashboard.
     */
    public function getStorageStats(): array
    {
        $releases = VersionRelease::with('vendor')->get();

        $stats = [
            'total_versions' => $releases->count(),
            'local_only' => 0,
            's3_only' => 0,
            'both' => 0,
            'local_size' => 0,
            's3_size' => 0,
        ];

        foreach ($releases as $release) {
            $localExists = $this->existsLocally($release->vendor, $release->version);
            $s3Exists = $release->storage_disk === 's3';

            if ($localExists && $s3Exists) {
                $stats['both']++;
            } elseif ($localExists) {
                $stats['local_only']++;
            } elseif ($s3Exists) {
                $stats['s3_only']++;
            }

            if ($release->file_size) {
                $stats['s3_size'] += $release->file_size;
            }

            if ($localExists) {
                $localPath = $this->getLocalPath($release->vendor, $release->version);
                $stats['local_size'] += $this->getDirectorySize($localPath);
            }
        }

        return $stats;
    }

    /**
     * Get size of a directory in bytes.
     */
    protected function getDirectorySize(string $path): int
    {
        if (! File::isDirectory($path)) {
            return 0;
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }
}
