<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asset Version - tracks version history for assets.
 *
 * Stores changelog, breaking changes, and download information.
 */
class AssetVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_id',
        'version',
        'changelog',
        'breaking_changes',
        'download_url',
        'local_path',
        'released_at',
    ];

    protected $casts = [
        'breaking_changes' => 'array',
        'released_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function hasBreakingChanges(): bool
    {
        return ! empty($this->breaking_changes);
    }

    public function isStored(): bool
    {
        return $this->local_path && file_exists($this->local_path);
    }
}
