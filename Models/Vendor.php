<?php

declare(strict_types=1);

namespace Core\Uptelligence\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Vendor - tracks upstream software sources.
 *
 * Supports licensed software, OSS repos, and plugin platforms.
 */
class Vendor extends Model
{
    use HasFactory;
    use SoftDeletes;

    // Source types
    public const SOURCE_LICENSED = 'licensed';

    public const SOURCE_OSS = 'oss';

    public const SOURCE_PLUGIN = 'plugin';

    // Plugin platforms
    public const PLATFORM_ALTUM = 'altum';

    public const PLATFORM_WORDPRESS = 'wordpress';

    public const PLATFORM_LARAVEL = 'laravel';

    public const PLATFORM_OTHER = 'other';

    protected $fillable = [
        'slug',
        'name',
        'vendor_name',
        'source_type',
        'plugin_platform',
        'git_repo_url',
        'current_version',
        'previous_version',
        'path_mapping',
        'ignored_paths',
        'priority_paths',
        'target_repo',
        'target_branch',
        'is_active',
        'last_checked_at',
        'last_analyzed_at',
    ];

    protected $casts = [
        'path_mapping' => 'array',
        'ignored_paths' => 'array',
        'priority_paths' => 'array',
        'is_active' => 'boolean',
        'last_checked_at' => 'datetime',
        'last_analyzed_at' => 'datetime',
    ];

    // Relationships
    public function todos(): HasMany
    {
        return $this->hasMany(UpstreamTodo::class);
    }

    public function releases(): HasMany
    {
        return $this->hasMany(VersionRelease::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AnalysisLog::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(UptelligenceWebhook::class);
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(UptelligenceWebhookDelivery::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeLicensed($query)
    {
        return $query->where('source_type', self::SOURCE_LICENSED);
    }

    public function scopeOss($query)
    {
        return $query->where('source_type', self::SOURCE_OSS);
    }

    public function scopePlugins($query)
    {
        return $query->where('source_type', self::SOURCE_PLUGIN);
    }

    public function scopeByPlatform($query, string $platform)
    {
        return $query->where('plugin_platform', $platform);
    }

    // Helpers
    public function getStoragePath(string $version = 'current'): string
    {
        return storage_path("app/vendors/{$this->slug}/{$version}");
    }

    public function shouldIgnorePath(string $path): bool
    {
        foreach ($this->ignored_paths ?? [] as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    public function isPriorityPath(string $path): bool
    {
        foreach ($this->priority_paths ?? [] as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    public function mapToHostHub(string $upstreamPath): ?string
    {
        foreach ($this->path_mapping ?? [] as $from => $to) {
            if (str_starts_with($upstreamPath, $from)) {
                return str_replace($from, $to, $upstreamPath);
            }
        }

        return null;
    }

    public function getPendingTodosCount(): int
    {
        return $this->todos()->where('status', 'pending')->count();
    }

    public function getQuickWinsCount(): int
    {
        return $this->todos()
            ->where('status', 'pending')
            ->where('effort', 'low')
            ->where('priority', '>=', 5)
            ->count();
    }

    // Source type helpers
    public function isLicensed(): bool
    {
        return $this->source_type === self::SOURCE_LICENSED;
    }

    public function isOss(): bool
    {
        return $this->source_type === self::SOURCE_OSS;
    }

    public function isPlugin(): bool
    {
        return $this->source_type === self::SOURCE_PLUGIN;
    }

    public function canGitSync(): bool
    {
        return $this->isOss() && ! empty($this->git_repo_url);
    }

    public function requiresManualUpload(): bool
    {
        return $this->isLicensed() || $this->isPlugin();
    }

    public function getSourceTypeLabel(): string
    {
        return match ($this->source_type) {
            self::SOURCE_LICENSED => 'Licensed Software',
            self::SOURCE_OSS => 'Open Source',
            self::SOURCE_PLUGIN => 'Plugin',
            default => 'Unknown',
        };
    }

    public function getSourceTypeIcon(): string
    {
        return match ($this->source_type) {
            self::SOURCE_LICENSED => '🔐',
            self::SOURCE_OSS => '🌐',
            self::SOURCE_PLUGIN => '🔌',
            default => '📦',
        };
    }

    public function getPlatformLabel(): ?string
    {
        if (! $this->plugin_platform) {
            return null;
        }

        return match ($this->plugin_platform) {
            self::PLATFORM_ALTUM => 'Altum/phpBioLinks',
            self::PLATFORM_WORDPRESS => 'WordPress',
            self::PLATFORM_LARAVEL => 'Laravel Package',
            default => 'Other',
        };
    }
}
