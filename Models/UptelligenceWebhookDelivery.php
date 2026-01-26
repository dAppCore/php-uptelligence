<?php

declare(strict_types=1);

namespace Core\Uptelligence\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * UptelligenceWebhookDelivery - log of incoming webhook deliveries.
 *
 * Records each webhook delivery, its payload, parsing results,
 * and processing status for debugging and audit purposes.
 *
 * @property int $id
 * @property int $webhook_id
 * @property int $vendor_id
 * @property string $event_type
 * @property string $provider
 * @property string|null $version
 * @property string|null $tag_name
 * @property array $payload
 * @property array|null $parsed_data
 * @property string $status
 * @property string|null $error_message
 * @property string|null $source_ip
 * @property string|null $signature_status
 * @property Carbon|null $processed_at
 * @property int $retry_count
 * @property int $max_retries
 * @property Carbon|null $next_retry_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class UptelligenceWebhookDelivery extends Model
{
    use HasFactory;

    protected $table = 'uptelligence_webhook_deliveries';

    // Status values
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    // Signature status values
    public const SIGNATURE_VALID = 'valid';

    public const SIGNATURE_INVALID = 'invalid';

    public const SIGNATURE_MISSING = 'missing';

    // Default max retries
    public const DEFAULT_MAX_RETRIES = 3;

    protected $fillable = [
        'webhook_id',
        'vendor_id',
        'event_type',
        'provider',
        'version',
        'tag_name',
        'payload',
        'parsed_data',
        'status',
        'error_message',
        'source_ip',
        'signature_status',
        'processed_at',
        'retry_count',
        'max_retries',
        'next_retry_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'parsed_data' => 'array',
        'processed_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'retry_count' => 'integer',
        'max_retries' => 'integer',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'retry_count' => 0,
        'max_retries' => self::DEFAULT_MAX_RETRIES,
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(UptelligenceWebhook::class, 'webhook_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeForWebhook($query, int $webhookId)
    {
        return $query->where('webhook_id', $webhookId);
    }

    public function scopeForVendor($query, int $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    /**
     * Scope to webhooks that are ready for retry.
     */
    public function scopeRetryable($query)
    {
        return $query->where(function ($q) {
            $q->where('status', self::STATUS_PENDING)
                ->orWhere('status', self::STATUS_FAILED);
        })
            ->where(function ($q) {
                $q->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->whereColumn('retry_count', '<', 'max_retries');
    }

    // -------------------------------------------------------------------------
    // Status Management
    // -------------------------------------------------------------------------

    public function markProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    public function markCompleted(?array $parsedData = null): void
    {
        $update = [
            'status' => self::STATUS_COMPLETED,
            'processed_at' => now(),
            'error_message' => null,
        ];

        if ($parsedData !== null) {
            $update['parsed_data'] = $parsedData;
        }

        $this->update($update);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'processed_at' => now(),
            'error_message' => $error,
        ]);
    }

    public function markSkipped(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_SKIPPED,
            'processed_at' => now(),
            'error_message' => $reason,
        ]);
    }

    /**
     * Schedule a retry with exponential backoff.
     */
    public function scheduleRetry(): void
    {
        $retryCount = $this->retry_count + 1;
        $delaySeconds = (int) pow(2, $retryCount) * 30; // 30s, 60s, 120s, 240s...

        $this->update([
            'status' => self::STATUS_PENDING,
            'retry_count' => $retryCount,
            'next_retry_at' => now()->addSeconds($delaySeconds),
        ]);
    }

    // -------------------------------------------------------------------------
    // State Checks
    // -------------------------------------------------------------------------

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function hasExceededMaxRetries(): bool
    {
        return $this->retry_count >= $this->max_retries;
    }

    public function canRetry(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_FAILED])
            && ! $this->hasExceededMaxRetries();
    }

    // -------------------------------------------------------------------------
    // Display Helpers
    // -------------------------------------------------------------------------

    /**
     * Get Flux badge colour for status.
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'yellow',
            self::STATUS_PROCESSING => 'blue',
            self::STATUS_COMPLETED => 'green',
            self::STATUS_FAILED => 'red',
            self::STATUS_SKIPPED => 'zinc',
            default => 'zinc',
        };
    }

    /**
     * Get icon for status.
     */
    public function getStatusIconAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'clock',
            self::STATUS_PROCESSING => 'arrow-path',
            self::STATUS_COMPLETED => 'check',
            self::STATUS_FAILED => 'x-mark',
            self::STATUS_SKIPPED => 'minus',
            default => 'question-mark-circle',
        };
    }

    /**
     * Get Flux badge colour for event type.
     */
    public function getEventColorAttribute(): string
    {
        return match (true) {
            str_contains($this->event_type, 'release') => 'green',
            str_contains($this->event_type, 'publish') => 'blue',
            str_contains($this->event_type, 'tag') => 'purple',
            str_contains($this->event_type, 'update') => 'blue',
            default => 'zinc',
        };
    }

    /**
     * Get Flux badge colour for signature status.
     */
    public function getSignatureColorAttribute(): string
    {
        return match ($this->signature_status) {
            self::SIGNATURE_VALID => 'green',
            self::SIGNATURE_INVALID => 'red',
            self::SIGNATURE_MISSING => 'yellow',
            default => 'zinc',
        };
    }

    /**
     * Get retry progress as a percentage.
     */
    public function getRetryProgressAttribute(): int
    {
        if ($this->max_retries === 0) {
            return 100;
        }

        return (int) round(($this->retry_count / $this->max_retries) * 100);
    }

    /**
     * Get human-readable retry status.
     */
    public function getRetryStatusAttribute(): string
    {
        if ($this->status === self::STATUS_COMPLETED) {
            return 'Completed';
        }

        if ($this->hasExceededMaxRetries()) {
            return 'Exhausted';
        }

        if ($this->next_retry_at && $this->next_retry_at->isFuture()) {
            return "Retry #{$this->retry_count} at ".$this->next_retry_at->format('H:i:s');
        }

        if ($this->retry_count > 0) {
            return "Failed after {$this->retry_count} retries";
        }

        return 'Pending';
    }
}
