<?php

declare(strict_types=1);

namespace Core\Uptelligence\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Pattern Collection - groups related patterns together.
 *
 * Useful for bundling patterns that work together.
 */
class PatternCollection extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'description',
        'pattern_ids',
        'required_assets',
        'is_active',
    ];

    protected $casts = [
        'pattern_ids' => 'array',
        'required_assets' => 'array',
        'is_active' => 'boolean',
    ];

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Helpers
    public function getPatterns()
    {
        if (empty($this->pattern_ids)) {
            return collect();
        }

        return Pattern::whereIn('id', $this->pattern_ids)->get();
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
            'description' => $this->description,
            'patterns' => $this->getPatterns()->map(fn ($p) => $p->toMcpContext())->all(),
            'required_assets' => $this->required_assets,
        ];
    }
}
