---
title: Storage
description: Vendor file storage and archival system
updated: 2026-01-29
---

# Storage

The Uptelligence module supports dual storage modes for vendor version archives: local filesystem and S3-compatible object storage.

## Storage Modes

### Local Only

Default mode. All vendor files stored on local filesystem.

```php
// config.php
'storage' => [
    'disk' => 'local',
]
```

**Paths:**
```
storage/app/vendors/{vendor-slug}/{version}/
storage/app/temp/upstream/
```

### S3 Cold Storage

For archiving older versions to reduce local disk usage.

```php
// config.php
'storage' => [
    'disk' => 's3',
    's3' => [
        'bucket' => 'hostuk',
        'prefix' => 'upstream/vendors/',
        'disk' => 's3-private',
    ],
]
```

**S3 Structure:**
```
s3://hostuk/upstream/vendors/{vendor-slug}/{version}.tar.gz
```

## Archive Flow

### Upload and Archive

When S3 mode is enabled:

```
1. Version uploaded to local storage
2. Analysis performed on local files
3. Archive created: tar.gz
4. Upload archive to S3
5. Record S3 key and file hash
6. Optionally delete local files
```

### Retrieval for Analysis

When analysing an archived version:

```
1. Check if local copy exists
2. If not, download from S3
3. Verify SHA-256 hash
4. Extract to local storage
5. Perform analysis
6. Mark last_downloaded_at
```

## Configuration Options

```php
'storage' => [
    // Primary storage mode
    'disk' => env('UPSTREAM_STORAGE_DISK', 'local'),

    // Local paths
    'base_path' => storage_path('app/vendors'),
    'licensed' => storage_path('app/vendors/licensed'),
    'oss' => storage_path('app/vendors/oss'),
    'plugins' => storage_path('app/vendors/plugins'),
    'temp_path' => storage_path('app/temp/upstream'),

    // S3 settings
    's3' => [
        'bucket' => env('UPSTREAM_S3_BUCKET', 'hostuk'),
        'prefix' => env('UPSTREAM_S3_PREFIX', 'upstream/vendors/'),
        'region' => env('UPSTREAM_S3_REGION', 'eu-west-2'),
        'private_endpoint' => env('S3_PRIVATE_ENDPOINT'),
        'public_endpoint' => env('S3_PUBLIC_ENDPOINT'),
        'disk' => env('UPSTREAM_S3_DISK', 's3-private'),
    ],

    // Archive behaviour
    'archive' => [
        'auto_archive' => env('UPSTREAM_AUTO_ARCHIVE', true),
        'delete_local_after_archive' => env('UPSTREAM_DELETE_LOCAL', true),
        'keep_local_versions' => env('UPSTREAM_KEEP_LOCAL', 2),
        'cleanup_after_hours' => env('UPSTREAM_CLEANUP_HOURS', 24),
    ],

    // Download behaviour
    'download' => [
        'max_concurrent' => 3,
        'timeout' => 300,
    ],
],
```

## Retention Policy

### Local Retention

By default, the N most recent versions are kept locally:

```php
'keep_local_versions' => 2,
```

Versions are never deleted if they are:
- The vendor's current version
- The vendor's previous version (for diff analysis)

### Archive Retention

Archives in S3 are retained indefinitely. No automatic deletion.

### Temp File Cleanup

Temporary files older than the configured hours are cleaned up:

```php
'cleanup_after_hours' => 24,
```

Run cleanup:
```bash
php artisan schedule:run  # If scheduled
# Or manually via service
$storageService->cleanupTemp();
```

## File Integrity

### Hash Verification

Each archived version records a SHA-256 hash:

```php
$hash = hash_file('sha256', $archivePath);
$release->update(['file_hash' => $hash]);
```

On download, the hash is verified:

```php
$downloadedHash = hash_file('sha256', $tempArchive);
if ($downloadedHash !== $release->file_hash) {
    throw new RuntimeException("Hash mismatch");
}
```

### Version Markers

Each extracted version has a marker file:

```
storage/app/vendors/{vendor-slug}/{version}/.version_marker
```

Contains the version string. Used to verify extraction state.

## Service Methods

### VendorStorageService

```php
// Check storage status
$service->isS3Enabled(): bool
$service->existsLocally(Vendor $vendor, string $version): bool
$service->existsInS3(Vendor $vendor, string $version): bool

// Path helpers
$service->getLocalPath(Vendor $vendor, string $version): string
$service->getS3Key(Vendor $vendor, string $version): string
$service->getTempPath(?string $suffix = null): string

// Archive operations
$service->archiveToS3(VersionRelease $release): bool
$service->downloadFromS3(VersionRelease $release, ?string $targetPath = null): string
$service->ensureLocal(VersionRelease $release): string

// Cleanup
$service->deleteLocalIfAllowed(VersionRelease $release): bool
$service->cleanupTemp(): int

// Statistics
$service->getStorageStats(): array
$service->getStorageStatus(VersionRelease $release): array
```

## Archive Format

Archives are created as gzipped tarballs:

```bash
tar -czf {vendor-slug}-{version}.tar.gz -C {source-path} .
```

Extraction:
```bash
tar -xzf {archive-path} -C {target-path}
```

**Content type:** `application/gzip`

## S3 Configuration for Hetzner Object Store

The module supports Hetzner Object Store with dual endpoints:

```php
's3' => [
    // Private endpoint for server-side access
    'private_endpoint' => env('S3_PRIVATE_ENDPOINT'),
    // Public endpoint for CDN (not used for vendor archives)
    'public_endpoint' => env('S3_PUBLIC_ENDPOINT'),
],
```

Vendor archives should always use the private bucket/endpoint.

## Dashboard Statistics

The storage service provides statistics for the admin dashboard:

```php
$stats = $storageService->getStorageStats();

// Returns:
[
    'total_versions' => 42,
    'local_only' => 5,
    's3_only' => 30,
    'both' => 7,
    'local_size' => 1073741824,  // bytes
    's3_size' => 5368709120,     // bytes
]
```

## Troubleshooting

### "Version not available locally or in S3"

1. Check if the version was ever uploaded
2. Verify S3 credentials and bucket access
3. Check `s3_key` in version_releases table

### Archive Fails with "Failed to create archive"

1. Check tar is installed and in PATH
2. Verify source directory exists and is readable
3. Check available disk space for temp files

### Download Fails with "Hash mismatch"

1. The S3 file may be corrupted
2. Re-upload the version if original exists locally
3. Check for encoding issues in hash comparison

### Local Files Not Being Deleted

1. Version may be current or previous (protected)
2. Version may be in recent N versions
3. Check `delete_local_after_archive` config
