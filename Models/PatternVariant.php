<?php

declare(strict_types=1);

namespace Core\Uptelligence\Models;

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
        'name',
        'code',
        'notes',
    ];

    public function pattern(): BelongsTo
    {
        return $this->belongsTo(Pattern::class);
    }
}
