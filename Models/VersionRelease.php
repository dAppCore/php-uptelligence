<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Version Release - tracks a specific version of upstream software.
 *
 * Stores file changes, analysis results, and S3 archive status.
 */
class VersionRelease extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'uptelligence_version_releases';

    // Storage disk options
    public const DISK_LOCAL = 'local';

    public const DISK_S3 = 's3';

    protected $fillable = [
        'vendor_id',
        'version',
        'previous_version',
        'files_added',
        'files_modified',
        'files_removed',
        'todos_created',
        'summary',
        'storage_path',
        'storage_disk',
        's3_key',
        'file_hash',
        'file_size',
        'metadata_json',
        'analyzed_at',
        'archived_at',
        'last_downloaded_at',
    ];

    protected $casts = [
        'summary' => 'array',
        'metadata_json' => 'array',
        'files_added' => 'integer',
        'files_modified' => 'integer',
        'files_removed' => 'integer',
        'todos_created' => 'integer',
        'file_size' => 'integer',
        'analyzed_at' => 'datetime',
        'archived_at' => 'datetime',
        'last_downloaded_at' => 'datetime',
    ];

    // Relationships
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function diffs(): HasMany
    {
        return $this->hasMany(DiffCache::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AnalysisLog::class);
    }

    // Scopes
    public function scopeAnalyzed($query)
    {
        return $query->whereNotNull('analyzed_at');
    }

    public function scopePendingAnalysis($query)
    {
        return $query->whereNull('analyzed_at');
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeArchived($query)
    {
        return $query->where('storage_disk', self::DISK_S3)->whereNotNull('archived_at');
    }

    public function scopeLocal($query)
    {
        return $query->where(function ($q) {
            $q->where('storage_disk', self::DISK_LOCAL)
                ->orWhereNull('storage_disk');
        });
    }

    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at');
    }

    // Helpers
    public function getTotalChanges(): int
    {
        return $this->files_added + $this->files_modified + $this->files_removed;
    }

    public function isAnalyzed(): bool
    {
        return $this->analyzed_at !== null;
    }

    public function getVersionCompare(): string
    {
        if ($this->previous_version) {
            return "{$this->previous_version} → {$this->version}";
        }

        return $this->version;
    }

    public function getStoragePath(): string
    {
        return $this->storage_path ?? storage_path("app/vendors/{$this->vendor->slug}/{$this->version}");
    }

    public function getSummaryHighlights(): array
    {
        $summary = $this->summary ?? [];

        return [
            'features' => $summary['features'] ?? [],
            'fixes' => $summary['fixes'] ?? [],
            'security' => $summary['security'] ?? [],
            'breaking' => $summary['breaking_changes'] ?? [],
        ];
    }

    public function getImpactLevel(): string
    {
        $total = $this->getTotalChanges();
        $security = $this->diffs()->where('category', 'security')->count();

        if ($security > 0) {
            return 'critical';
        }

        return match (true) {
            $total >= 100 => 'major',
            $total >= 20 => 'moderate',
            default => 'minor',
        };
    }

    public function getImpactBadgeClass(): string
    {
        return match ($this->getImpactLevel()) {
            'critical' => 'bg-red-100 text-red-800',
            'major' => 'bg-orange-100 text-orange-800',
            'moderate' => 'bg-yellow-100 text-yellow-800',
            default => 'bg-green-100 text-green-800',
        };
    }

    // Storage helpers
    public function isArchivedToS3(): bool
    {
        return $this->storage_disk === self::DISK_S3 && ! empty($this->s3_key);
    }

    public function isLocal(): bool
    {
        return $this->storage_disk === self::DISK_LOCAL || empty($this->storage_disk);
    }

    public function hasMetadata(): bool
    {
        return ! empty($this->metadata_json);
    }

    public function getFileSizeForHumans(): string
    {
        if (! $this->file_size) {
            return 'Unknown';
        }

        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;

        return number_format($bytes / pow(1024, $power), 2).' '.$units[$power];
    }

    public function getStorageStatusBadge(): array
    {
        if ($this->isArchivedToS3()) {
            return [
                'label' => 'S3 Archived',
                'class' => 'bg-blue-100 text-blue-800',
                'icon' => 'cloud',
            ];
        }

        return [
            'label' => 'Local',
            'class' => 'bg-gray-100 text-gray-800',
            'icon' => 'folder',
        ];
    }
}
