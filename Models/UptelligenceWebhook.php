<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * UptelligenceWebhook - webhook endpoint for receiving vendor release notifications.
 *
 * Each vendor can have a webhook endpoint configured to receive release
 * notifications from GitHub, GitLab, npm, Packagist, or custom sources.
 *
 * @property int $id
 * @property string $uuid
 * @property int $vendor_id
 * @property string $provider
 * @property string|null $secret
 * @property string|null $previous_secret
 * @property Carbon|null $secret_rotated_at
 * @property int $grace_period_seconds
 * @property bool $is_active
 * @property int $failure_count
 * @property Carbon|null $last_received_at
 * @property array|null $settings
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
class UptelligenceWebhook extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'uptelligence_webhooks';

    // Supported providers
    public const PROVIDER_GITHUB = 'github';

    public const PROVIDER_GITLAB = 'gitlab';

    public const PROVIDER_NPM = 'npm';

    public const PROVIDER_PACKAGIST = 'packagist';

    public const PROVIDER_CUSTOM = 'custom';

    public const PROVIDER_FORGEJO = 'forgejo';

    public const PROVIDERS = [
        self::PROVIDER_GITHUB,
        self::PROVIDER_GITLAB,
        self::PROVIDER_NPM,
        self::PROVIDER_PACKAGIST,
        self::PROVIDER_CUSTOM,
        self::PROVIDER_FORGEJO,
    ];

    // Maximum consecutive failures before auto-disable
    public const MAX_FAILURES = 10;

    protected $fillable = [
        'uuid',
        'vendor_id',
        'provider',
        'secret',
        'previous_secret',
        'secret_rotated_at',
        'grace_period_seconds',
        'is_active',
        'failure_count',
        'last_received_at',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'failure_count' => 'integer',
        'grace_period_seconds' => 'integer',
        'last_received_at' => 'datetime',
        'secret_rotated_at' => 'datetime',
        'secret' => 'encrypted',
        'previous_secret' => 'encrypted',
        'settings' => 'array',
    ];

    protected $hidden = [
        'secret',
        'previous_secret',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (UptelligenceWebhook $webhook) {
            if (empty($webhook->uuid)) {
                $webhook->uuid = (string) Str::uuid();
            }

            // Generate a secret if not provided
            if (empty($webhook->secret)) {
                $webhook->secret = Str::random(64);
            }

            // Default grace period: 24 hours
            if (empty($webhook->grace_period_seconds)) {
                $webhook->grace_period_seconds = 86400;
            }
        });
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(UptelligenceWebhookDelivery::class, 'webhook_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForVendor($query, int $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    // -------------------------------------------------------------------------
    // State Checks
    // -------------------------------------------------------------------------

    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    public function isCircuitBroken(): bool
    {
        return $this->failure_count >= self::MAX_FAILURES;
    }

    public function isInGracePeriod(): bool
    {
        if (empty($this->secret_rotated_at)) {
            return false;
        }

        $rotatedAt = Carbon::parse($this->secret_rotated_at);
        $gracePeriodSeconds = $this->grace_period_seconds ?? 86400;
        $graceEndsAt = $rotatedAt->copy()->addSeconds($gracePeriodSeconds);

        return now()->isBefore($graceEndsAt);
    }

    // -------------------------------------------------------------------------
    // Signature Verification
    // -------------------------------------------------------------------------

    /**
     * Verify webhook signature based on provider.
     *
     * Supports:
     * - GitHub: X-Hub-Signature-256 (sha256=...)
     * - GitLab: X-Gitlab-Token (token comparison)
     * - npm: npm registry webhooks
     * - Packagist: Packagist webhooks
     * - Custom: HMAC-SHA256
     */
    public function verifySignature(string $payload, ?string $signature): bool
    {
        // If no secret configured, skip verification
        if (empty($this->secret)) {
            return true;
        }

        // Signature required when secret is set
        if (empty($signature)) {
            return false;
        }

        // Check against current secret
        if ($this->verifyAgainstSecret($payload, $signature, $this->secret)) {
            return true;
        }

        // Check against previous secret if in grace period
        if ($this->isInGracePeriod() && ! empty($this->previous_secret)) {
            if ($this->verifyAgainstSecret($payload, $signature, $this->previous_secret)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify signature against a specific secret.
     */
    protected function verifyAgainstSecret(string $payload, string $signature, string $secret): bool
    {
        return match ($this->provider) {
            self::PROVIDER_GITHUB,
            self::PROVIDER_FORGEJO => $this->verifyGitHubSignature($payload, $signature, $secret),
            self::PROVIDER_GITLAB => $this->verifyGitLabSignature($signature, $secret),
            self::PROVIDER_NPM => $this->verifyNpmSignature($payload, $signature, $secret),
            self::PROVIDER_PACKAGIST => $this->verifyPackagistSignature($payload, $signature, $secret),
            default => $this->verifyHmacSignature($payload, $signature, $secret),
        };
    }

    /**
     * Verify GitHub-style signature (sha256=...).
     */
    protected function verifyGitHubSignature(string $payload, string $signature, string $secret): bool
    {
        // Handle sha256= prefix
        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }

        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Verify GitLab-style signature (X-Gitlab-Token header).
     */
    protected function verifyGitLabSignature(string $signature, string $secret): bool
    {
        return hash_equals($secret, $signature);
    }

    /**
     * Verify npm webhook signature.
     */
    protected function verifyNpmSignature(string $payload, string $signature, string $secret): bool
    {
        // npm uses sha256 HMAC
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Verify Packagist webhook signature.
     */
    protected function verifyPackagistSignature(string $payload, string $signature, string $secret): bool
    {
        // Packagist uses sha1 HMAC
        $expectedSignature = hash_hmac('sha1', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Verify generic HMAC-SHA256 signature.
     */
    protected function verifyHmacSignature(string $payload, string $signature, string $secret): bool
    {
        // Handle sha256= prefix
        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }

        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    // -------------------------------------------------------------------------
    // Status Management
    // -------------------------------------------------------------------------

    public function incrementFailureCount(): void
    {
        $this->increment('failure_count');

        // Auto-disable after too many failures (circuit breaker)
        if ($this->failure_count >= self::MAX_FAILURES) {
            $this->update(['is_active' => false]);
        }
    }

    public function resetFailureCount(): void
    {
        $this->update([
            'failure_count' => 0,
        ]);
    }

    public function markReceived(): void
    {
        $this->update(['last_received_at' => now()]);
    }

    // -------------------------------------------------------------------------
    // Secret Management
    // -------------------------------------------------------------------------

    /**
     * Rotate the secret and keep the previous one for grace period.
     */
    public function rotateSecret(): string
    {
        $newSecret = Str::random(64);

        $this->update([
            'previous_secret' => $this->secret,
            'secret' => $newSecret,
            'secret_rotated_at' => now(),
        ]);

        return $newSecret;
    }

    /**
     * Regenerate the secret without keeping the previous one.
     */
    public function regenerateSecret(): string
    {
        $newSecret = Str::random(64);

        $this->update([
            'secret' => $newSecret,
            'previous_secret' => null,
            'secret_rotated_at' => null,
        ]);

        return $newSecret;
    }

    // -------------------------------------------------------------------------
    // URL Generation
    // -------------------------------------------------------------------------

    /**
     * Get the webhook endpoint URL.
     */
    public function getEndpointUrl(): string
    {
        return route('api.uptelligence.webhooks.receive', ['webhook' => $this->uuid]);
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Get provider label.
     */
    public function getProviderLabel(): string
    {
        return match ($this->provider) {
            self::PROVIDER_GITHUB => 'GitHub',
            self::PROVIDER_GITLAB => 'GitLab',
            self::PROVIDER_NPM => 'npm',
            self::PROVIDER_PACKAGIST => 'Packagist',
            self::PROVIDER_CUSTOM => 'Custom',
            self::PROVIDER_FORGEJO => 'Forgejo',
            default => ucfirst($this->provider),
        };
    }

    /**
     * Get provider icon name.
     */
    public function getProviderIcon(): string
    {
        return match ($this->provider) {
            self::PROVIDER_GITHUB => 'code-bracket',
            self::PROVIDER_GITLAB => 'code-bracket-square',
            self::PROVIDER_NPM => 'cube',
            self::PROVIDER_PACKAGIST => 'archive-box',
            self::PROVIDER_CUSTOM => 'cog-6-tooth',
            self::PROVIDER_FORGEJO => 'code-bracket',
            default => 'globe-alt',
        };
    }

    /**
     * Get Flux badge colour for status.
     */
    public function getStatusColorAttribute(): string
    {
        if (! $this->is_active) {
            return 'zinc';
        }

        if ($this->isCircuitBroken()) {
            return 'red';
        }

        if ($this->failure_count > 0) {
            return 'yellow';
        }

        return 'green';
    }

    /**
     * Get status label.
     */
    public function getStatusLabelAttribute(): string
    {
        if (! $this->is_active) {
            return 'Disabled';
        }

        if ($this->isCircuitBroken()) {
            return 'Circuit Open';
        }

        if ($this->failure_count > 0) {
            return "Active ({$this->failure_count} failures)";
        }

        return 'Active';
    }

    /**
     * Get time remaining in grace period.
     */
    public function getGraceTimeRemainingAttribute(): ?int
    {
        if (! $this->isInGracePeriod()) {
            return null;
        }

        $rotatedAt = Carbon::parse($this->secret_rotated_at);
        $gracePeriodSeconds = $this->grace_period_seconds ?? 86400;
        $graceEndsAt = $rotatedAt->copy()->addSeconds($gracePeriodSeconds);

        return (int) now()->diffInSeconds($graceEndsAt, false);
    }

    /**
     * Get when the grace period ends.
     */
    public function getGraceEndsAtAttribute(): ?Carbon
    {
        if (empty($this->secret_rotated_at)) {
            return null;
        }

        $rotatedAt = Carbon::parse($this->secret_rotated_at);
        $gracePeriodSeconds = $this->grace_period_seconds ?? 86400;

        return $rotatedAt->copy()->addSeconds($gracePeriodSeconds);
    }
}
