<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Models;

use Core\Mod\Tenant\Concerns\BelongsToWorkspace;
use Core\Mod\Tenant\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UptelligenceDigest - stores user preferences for digest email notifications.
 *
 * Tracks which users want to receive periodic summaries of vendor updates,
 * new releases, and pending todos from the Uptelligence module.
 */
class UptelligenceDigest extends Model
{
    use BelongsToWorkspace;

    // Frequency options
    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    protected $table = 'uptelligence_digests';

    protected $fillable = [
        'user_id',
        'workspace_id',
        'frequency',
        'last_sent_at',
        'preferences',
        'is_enabled',
    ];

    protected $casts = [
        'preferences' => 'array',
        'is_enabled' => 'boolean',
        'last_sent_at' => 'datetime',
    ];

    protected $attributes = [
        'frequency' => self::FREQUENCY_WEEKLY,
        'is_enabled' => true,
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Scope to enabled digests only.
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope to digests with specific frequency.
     */
    public function scopeWithFrequency(Builder $query, string $frequency): Builder
    {
        return $query->where('frequency', $frequency);
    }

    /**
     * Scope to digests that are due to be sent.
     *
     * Daily: last_sent_at is null or older than 24 hours
     * Weekly: last_sent_at is null or older than 7 days
     * Monthly: last_sent_at is null or older than 30 days
     */
    public function scopeDueForDigest(Builder $query, string $frequency): Builder
    {
        $cutoff = match ($frequency) {
            self::FREQUENCY_DAILY => now()->subDay(),
            self::FREQUENCY_WEEKLY => now()->subWeek(),
            self::FREQUENCY_MONTHLY => now()->subMonth(),
            default => now()->subWeek(),
        };

        return $query->enabled()
            ->withFrequency($frequency)
            ->where(function (Builder $q) use ($cutoff) {
                $q->whereNull('last_sent_at')
                    ->orWhere('last_sent_at', '<=', $cutoff);
            });
    }

    // -------------------------------------------------------------------------
    // Preferences Helpers
    // -------------------------------------------------------------------------

    /**
     * Get the list of vendor IDs to include in the digest.
     * Returns null if all vendors should be included.
     */
    public function getVendorIds(): ?array
    {
        return $this->preferences['vendor_ids'] ?? null;
    }

    /**
     * Set the vendor IDs to include in the digest.
     */
    public function setVendorIds(?array $vendorIds): void
    {
        $this->preferences = array_merge($this->preferences ?? [], [
            'vendor_ids' => $vendorIds,
        ]);
    }

    /**
     * Check if a specific vendor should be included in the digest.
     */
    public function includesVendor(int $vendorId): bool
    {
        $vendorIds = $this->getVendorIds();

        // If no filter set, include all vendors
        if ($vendorIds === null) {
            return true;
        }

        return in_array($vendorId, $vendorIds);
    }

    /**
     * Get the update types to include (releases, todos, security).
     * Returns all types if not specified.
     */
    public function getIncludedTypes(): array
    {
        return $this->preferences['include_types'] ?? [
            'releases',
            'todos',
            'security',
        ];
    }

    /**
     * Set which update types to include.
     */
    public function setIncludedTypes(array $types): void
    {
        $this->preferences = array_merge($this->preferences ?? [], [
            'include_types' => $types,
        ]);
    }

    /**
     * Check if releases should be included.
     */
    public function includesReleases(): bool
    {
        return in_array('releases', $this->getIncludedTypes());
    }

    /**
     * Check if todos should be included.
     */
    public function includesTodos(): bool
    {
        return in_array('todos', $this->getIncludedTypes());
    }

    /**
     * Check if security updates should be highlighted.
     */
    public function includesSecurity(): bool
    {
        return in_array('security', $this->getIncludedTypes());
    }

    /**
     * Get minimum priority threshold for todos.
     * Returns null if no threshold (include all priorities).
     */
    public function getMinPriority(): ?int
    {
        return $this->preferences['min_priority'] ?? null;
    }

    /**
     * Set minimum priority threshold.
     */
    public function setMinPriority(?int $priority): void
    {
        $this->preferences = array_merge($this->preferences ?? [], [
            'min_priority' => $priority,
        ]);
    }

    // -------------------------------------------------------------------------
    // Status Helpers
    // -------------------------------------------------------------------------

    /**
     * Check if this digest is due to be sent.
     */
    public function isDue(): bool
    {
        if (! $this->is_enabled) {
            return false;
        }

        if ($this->last_sent_at === null) {
            return true;
        }

        return match ($this->frequency) {
            self::FREQUENCY_DAILY => $this->last_sent_at->lte(now()->subDay()),
            self::FREQUENCY_WEEKLY => $this->last_sent_at->lte(now()->subWeek()),
            self::FREQUENCY_MONTHLY => $this->last_sent_at->lte(now()->subMonth()),
            default => false,
        };
    }

    /**
     * Mark the digest as sent.
     */
    public function markAsSent(): void
    {
        $this->update(['last_sent_at' => now()]);
    }

    /**
     * Get a human-readable frequency label.
     */
    public function getFrequencyLabel(): string
    {
        return match ($this->frequency) {
            self::FREQUENCY_DAILY => 'Daily',
            self::FREQUENCY_WEEKLY => 'Weekly',
            self::FREQUENCY_MONTHLY => 'Monthly',
            default => ucfirst($this->frequency),
        };
    }

    /**
     * Get the next scheduled send date.
     */
    public function getNextSendDate(): ?\Carbon\Carbon
    {
        if (! $this->is_enabled) {
            return null;
        }

        $lastSent = $this->last_sent_at ?? now();

        return match ($this->frequency) {
            self::FREQUENCY_DAILY => $lastSent->copy()->addDay(),
            self::FREQUENCY_WEEKLY => $lastSent->copy()->addWeek(),
            self::FREQUENCY_MONTHLY => $lastSent->copy()->addMonth(),
            default => null,
        };
    }

    /**
     * Get available frequency options for forms.
     */
    public static function getFrequencyOptions(): array
    {
        return [
            self::FREQUENCY_DAILY => 'Daily',
            self::FREQUENCY_WEEKLY => 'Weekly',
            self::FREQUENCY_MONTHLY => 'Monthly',
        ];
    }
}
