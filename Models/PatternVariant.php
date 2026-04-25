<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pattern Variant - alternative implementations of a pattern.
 *
 * Allows storing different versions (e.g. dark mode, compact, etc.)
 */
class PatternVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'pattern_id',
        'asset_id',
        'name',
        'code',
        'notes',
        'from_version',
        'to_version',
        'file_path',
        'line_range',
        'context',
        'found_at',
    ];

    protected $casts = [
        'found_at' => 'datetime',
    ];

    public function pattern(): BelongsTo
    {
        return $this->belongsTo(Pattern::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
