<?php

declare(strict_types=1);

namespace Core\Uptelligence\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Asset - tracks installed packages, fonts, themes, and CDN resources.
 *
 * Monitors versions, licences, and update availability.
 */
class Asset extends Model
{
    use HasFactory;

    // Asset types
    public const TYPE_COMPOSER = 'composer';

    public const TYPE_NPM = 'npm';

    public const TYPE_FONT = 'font';

    public const TYPE_THEME = 'theme';

    public const TYPE_CDN = 'cdn';

    public const TYPE_MANUAL = 'manual';

    // Licence types
    public const LICENCE_LIFETIME = 'lifetime';

    public const LICENCE_SUBSCRIPTION = 'subscription';

    public const LICENCE_OSS = 'oss';

    public const LICENCE_TRIAL = 'trial';

    protected $fillable = [
        'slug',
        'name',
        'description',
        'type',
        'package_name',
        'registry_url',
        'licence_type',
        'licence_expires_at',
        'licence_meta',
        'installed_version',
        'latest_version',
        'last_checked_at',
        'auto_update',
        'install_path',
        'build_config',
        'used_in_projects',
        'setup_notes',
        'is_active',
    ];

    protected $casts = [
        'licence_meta' => 'array',
        'build_config' => 'array',
        'used_in_projects' => 'array',
        'licence_expires_at' => 'date',
        'last_checked_at' => 'datetime',
        'auto_update' => 'boolean',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function versions(): HasMany
    {
        return $this->hasMany(AssetVersion::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeComposer($query)
    {
        return $query->where('type', self::TYPE_COMPOSER);
    }

    public function scopeNpm($query)
    {
        return $query->where('type', self::TYPE_NPM);
    }

    public function scopeNeedsUpdate($query)
    {
        return $query->whereColumn('installed_version', '!=', 'latest_version')
            ->whereNotNull('latest_version');
    }

    public function scopeAutoUpdate($query)
    {
        return $query->where('auto_update', true);
    }

    // Helpers
    public function hasUpdate(): bool
    {
        return $this->latest_version
            && $this->installed_version
            && version_compare($this->latest_version, $this->installed_version, '>');
    }

    public function isLicenceExpired(): bool
    {
        return $this->licence_expires_at && $this->licence_expires_at->isPast();
    }

    public function isLicenceExpiringSoon(int $days = 30): bool
    {
        return $this->licence_expires_at
            && $this->licence_expires_at->isFuture()
            && $this->licence_expires_at->diffInDays(now()) <= $days;
    }

    public function getTypeIcon(): string
    {
        return match ($this->type) {
            self::TYPE_COMPOSER => '📦',
            self::TYPE_NPM => '📦',
            self::TYPE_FONT => '🔤',
            self::TYPE_THEME => '🎨',
            self::TYPE_CDN => '🌐',
            self::TYPE_MANUAL => '📁',
            default => '📄',
        };
    }

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_COMPOSER => 'Composer',
            self::TYPE_NPM => 'NPM',
            self::TYPE_FONT => 'Font',
            self::TYPE_THEME => 'Theme',
            self::TYPE_CDN => 'CDN',
            self::TYPE_MANUAL => 'Manual',
            default => ucfirst($this->type),
        };
    }

    public function getLicenceIcon(): string
    {
        if ($this->isLicenceExpired()) {
            return '🔴';
        }
        if ($this->isLicenceExpiringSoon()) {
            return '🟡';
        }

        return match ($this->licence_type) {
            self::LICENCE_LIFETIME => '♾️',
            self::LICENCE_SUBSCRIPTION => '🔄',
            self::LICENCE_OSS => '🌐',
            self::LICENCE_TRIAL => '⏳',
            default => '📄',
        };
    }

    public function getInstallCommand(): ?string
    {
        if (! $this->package_name) {
            return null;
        }

        return match ($this->type) {
            self::TYPE_COMPOSER => "composer require {$this->package_name}",
            self::TYPE_NPM => "npm install {$this->package_name}",
            default => null,
        };
    }

    public function getUpdateCommand(): ?string
    {
        if (! $this->package_name) {
            return null;
        }

        return match ($this->type) {
            self::TYPE_COMPOSER => "composer update {$this->package_name}",
            self::TYPE_NPM => "npm update {$this->package_name}",
            default => null,
        };
    }

    // For MCP context
    public function toMcpContext(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'package' => $this->package_name,
            'version' => $this->installed_version,
            'latest' => $this->latest_version,
            'has_update' => $this->hasUpdate(),
            'licence' => $this->licence_type,
            'install_path' => $this->install_path,
            'install_command' => $this->getInstallCommand(),
            'setup_notes' => $this->setup_notes,
            'build_config' => $this->build_config,
        ];
    }
}
