<?php

declare(strict_types=1);

namespace Core\Uptelligence\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pattern - reusable code patterns for development.
 *
 * Stores components, layouts, snippets with variants and required assets.
 */
class Pattern extends Model
{
    use HasFactory;

    // Categories
    public const CATEGORY_COMPONENT = 'component';

    public const CATEGORY_LAYOUT = 'layout';

    public const CATEGORY_THEME = 'theme';

    public const CATEGORY_SNIPPET = 'snippet';

    public const CATEGORY_WORKFLOW = 'workflow';

    public const CATEGORY_TEMPLATE = 'template';

    // Source types
    public const SOURCE_PURCHASED = 'purchased';

    public const SOURCE_OSS = 'oss';

    public const SOURCE_INTERNAL = 'internal';

    protected $fillable = [
        'slug',
        'name',
        'description',
        'category',
        'tags',
        'language',
        'code',
        'usage_example',
        'required_assets',
        'source_url',
        'source_type',
        'author',
        'usage_count',
        'quality_score',
        'is_vetted',
        'is_active',
    ];

    protected $casts = [
        'tags' => 'array',
        'required_assets' => 'array',
        'quality_score' => 'decimal:2',
        'is_vetted' => 'boolean',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function variants(): HasMany
    {
        return $this->hasMany(PatternVariant::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeVetted($query)
    {
        return $query->where('is_vetted', true);
    }

    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeLanguage($query, string $language)
    {
        return $query->where('language', $language);
    }

    public function scopeWithTag($query, string $tag)
    {
        return $query->whereJsonContains('tags', $tag);
    }

    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhereJsonContains('tags', $search);
        });
    }

    // Helpers
    public function getCategoryIcon(): string
    {
        return match ($this->category) {
            self::CATEGORY_COMPONENT => '🧩',
            self::CATEGORY_LAYOUT => '📐',
            self::CATEGORY_THEME => '🎨',
            self::CATEGORY_SNIPPET => '📝',
            self::CATEGORY_WORKFLOW => '⚙️',
            self::CATEGORY_TEMPLATE => '📄',
            default => '📦',
        };
    }

    public function getLanguageIcon(): string
    {
        return match ($this->language) {
            'blade' => '🔹',
            'vue' => '💚',
            'react' => '⚛️',
            'css' => '🎨',
            'php' => '🐘',
            'javascript', 'js' => '💛',
            'typescript', 'ts' => '💙',
            default => '📄',
        };
    }

    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    public function getRequiredAssetsObjects(): array
    {
        if (empty($this->required_assets)) {
            return [];
        }

        return Asset::whereIn('slug', $this->required_assets)->get()->all();
    }

    // For MCP context
    public function toMcpContext(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'category' => $this->category,
            'language' => $this->language,
            'description' => $this->description,
            'tags' => $this->tags,
            'code' => $this->code,
            'usage_example' => $this->usage_example,
            'required_assets' => $this->required_assets,
            'source' => $this->source_type,
            'is_vetted' => $this->is_vetted,
            'variants' => $this->variants->map(fn ($v) => [
                'name' => $v->name,
                'code' => $v->code,
                'notes' => $v->notes,
            ])->all(),
        ];
    }
}
