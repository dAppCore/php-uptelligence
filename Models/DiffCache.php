<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Diff Cache - stores file changes for version releases.
 *
 * Auto-categorises files for filtering and prioritisation.
 */
class DiffCache extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * Uses the uptelligence_ prefix for consistency with other module tables.
     */
    protected $table = 'uptelligence_diff_cache';

    // Change types
    public const CHANGE_ADDED = 'added';

    public const CHANGE_MODIFIED = 'modified';

    public const CHANGE_REMOVED = 'removed';

    // Categories (auto-detected)
    public const CATEGORY_CONTROLLER = 'controller';

    public const CATEGORY_MODEL = 'model';

    public const CATEGORY_VIEW = 'view';

    public const CATEGORY_MIGRATION = 'migration';

    public const CATEGORY_CONFIG = 'config';

    public const CATEGORY_ROUTE = 'route';

    public const CATEGORY_LANGUAGE = 'language';

    public const CATEGORY_ASSET = 'asset';

    public const CATEGORY_PLUGIN = 'plugin';

    public const CATEGORY_BLOCK = 'block';

    public const CATEGORY_SECURITY = 'security';

    public const CATEGORY_API = 'api';

    public const CATEGORY_OTHER = 'other';

    protected $fillable = [
        'version_release_id',
        'file_path',
        'change_type',
        'diff_content',
        'new_content',
        'category',
    ];

    // Relationships
    public function versionRelease(): BelongsTo
    {
        return $this->belongsTo(VersionRelease::class);
    }

    // Scopes
    public function scopeAdded($query)
    {
        return $query->where('change_type', self::CHANGE_ADDED);
    }

    public function scopeModified($query)
    {
        return $query->where('change_type', self::CHANGE_MODIFIED);
    }

    public function scopeRemoved($query)
    {
        return $query->where('change_type', self::CHANGE_REMOVED);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeSecurityRelated($query)
    {
        return $query->where('category', self::CATEGORY_SECURITY);
    }

    // Helpers
    public static function detectCategory(string $filePath): string
    {
        $path = strtolower($filePath);

        // Security-related files
        if (str_contains($path, 'security') ||
            str_contains($path, 'auth') ||
            str_contains($path, 'password') ||
            str_contains($path, 'permission') ||
            str_contains($path, 'middleware')) {
            return self::CATEGORY_SECURITY;
        }

        // Controllers
        if (str_contains($path, '/controllers/') || str_ends_with($path, 'controller.php')) {
            return self::CATEGORY_CONTROLLER;
        }

        // Models
        if (str_contains($path, '/models/') || str_ends_with($path, 'model.php')) {
            return self::CATEGORY_MODEL;
        }

        // Views/Templates
        if (str_contains($path, '/views/') ||
            str_contains($path, '/themes/') ||
            str_ends_with($path, '.blade.php')) {
            return self::CATEGORY_VIEW;
        }

        // Migrations
        if (str_contains($path, '/migrations/') || str_contains($path, '/database/')) {
            return self::CATEGORY_MIGRATION;
        }

        // Config
        if (str_contains($path, '/config/') || str_ends_with($path, 'config.php')) {
            return self::CATEGORY_CONFIG;
        }

        // Routes
        if (str_contains($path, '/routes/') || str_ends_with($path, 'routes.php')) {
            return self::CATEGORY_ROUTE;
        }

        // Languages
        if (str_contains($path, '/languages/') || str_contains($path, '/lang/')) {
            return self::CATEGORY_LANGUAGE;
        }

        // Assets
        if (preg_match('/\.(css|js|scss|less|png|jpg|gif|svg|woff|ttf)$/', $path)) {
            return self::CATEGORY_ASSET;
        }

        // Plugins
        if (str_contains($path, '/plugins/')) {
            return self::CATEGORY_PLUGIN;
        }

        // Blocks (BioLinks specific)
        if (str_contains($path, '/blocks/') || str_contains($path, 'biolink')) {
            return self::CATEGORY_BLOCK;
        }

        // API
        if (str_contains($path, '/api/') || str_contains($path, 'api.php')) {
            return self::CATEGORY_API;
        }

        return self::CATEGORY_OTHER;
    }

    public function getFileName(): string
    {
        return basename($this->file_path);
    }

    public function getDirectory(): string
    {
        return dirname($this->file_path);
    }

    public function getExtension(): string
    {
        return pathinfo($this->file_path, PATHINFO_EXTENSION);
    }

    public function getDiffLineCount(): int
    {
        if (! $this->diff_content) {
            return 0;
        }

        return substr_count($this->diff_content, "\n") + 1;
    }

    public function getAddedLines(): int
    {
        if (! $this->diff_content) {
            return 0;
        }

        return preg_match_all('/^\+[^+]/m', $this->diff_content);
    }

    public function getRemovedLines(): int
    {
        if (! $this->diff_content) {
            return 0;
        }

        return preg_match_all('/^-[^-]/m', $this->diff_content);
    }

    public function getChangeTypeIcon(): string
    {
        return match ($this->change_type) {
            self::CHANGE_ADDED => '➕',
            self::CHANGE_MODIFIED => '✏️',
            self::CHANGE_REMOVED => '➖',
            default => '📄',
        };
    }

    public function getChangeTypeBadgeClass(): string
    {
        return match ($this->change_type) {
            self::CHANGE_ADDED => 'bg-green-100 text-green-800',
            self::CHANGE_MODIFIED => 'bg-blue-100 text-blue-800',
            self::CHANGE_REMOVED => 'bg-red-100 text-red-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }

    public function getCategoryIcon(): string
    {
        return match ($this->category) {
            self::CATEGORY_CONTROLLER => '🎮',
            self::CATEGORY_MODEL => '📊',
            self::CATEGORY_VIEW => '👁️',
            self::CATEGORY_MIGRATION => '🗄️',
            self::CATEGORY_CONFIG => '⚙️',
            self::CATEGORY_ROUTE => '🛤️',
            self::CATEGORY_LANGUAGE => '🌐',
            self::CATEGORY_ASSET => '🎨',
            self::CATEGORY_PLUGIN => '🔌',
            self::CATEGORY_BLOCK => '🧱',
            self::CATEGORY_SECURITY => '🔒',
            self::CATEGORY_API => '🔌',
            default => '📄',
        };
    }
}
