<?php

declare(strict_types=1);

namespace Core\Uptelligence\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Upstream Todo - tracks porting tasks from upstream vendors.
 *
 * Includes AI analysis, priority, effort, and GitHub issue tracking.
 */
class UpstreamTodo extends Model
{
    use HasFactory;
    use SoftDeletes;

    // Types
    public const TYPE_FEATURE = 'feature';

    public const TYPE_BUGFIX = 'bugfix';

    public const TYPE_SECURITY = 'security';

    public const TYPE_UI = 'ui';

    public const TYPE_BLOCK = 'block';

    public const TYPE_API = 'api';

    public const TYPE_REFACTOR = 'refactor';

    public const TYPE_DEPENDENCY = 'dependency';

    // Statuses
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_PORTED = 'ported';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_WONT_PORT = 'wont_port';

    // Effort levels
    public const EFFORT_LOW = 'low';

    public const EFFORT_MEDIUM = 'medium';

    public const EFFORT_HIGH = 'high';

    protected $fillable = [
        'vendor_id',
        'from_version',
        'to_version',
        'type',
        'status',
        'title',
        'description',
        'port_notes',
        'priority',
        'effort',
        'has_conflicts',
        'conflict_reason',
        'files',
        'dependencies',
        'tags',
        'github_issue_number',
        'branch_name',
        'assigned_to',
        'ai_analysis',
        'ai_confidence',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'files' => 'array',
        'dependencies' => 'array',
        'tags' => 'array',
        'ai_analysis' => 'array',
        'ai_confidence' => 'decimal:2',
        'has_conflicts' => 'boolean',
        'priority' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Relationships
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    public function scopeCompleted($query)
    {
        return $query->whereIn('status', [self::STATUS_PORTED, self::STATUS_SKIPPED, self::STATUS_WONT_PORT]);
    }

    public function scopeQuickWins($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('effort', self::EFFORT_LOW)
            ->where('priority', '>=', 5)
            ->orderByDesc('priority');
    }

    public function scopeHighPriority($query)
    {
        return $query->where('priority', '>=', 7);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeSecurityRelated($query)
    {
        return $query->where('type', self::TYPE_SECURITY);
    }

    // Helpers
    public function isQuickWin(): bool
    {
        return $this->effort === self::EFFORT_LOW && $this->priority >= 5;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, [self::STATUS_PORTED, self::STATUS_SKIPPED, self::STATUS_WONT_PORT]);
    }

    public function markInProgress(): void
    {
        $this->update([
            'status' => self::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);
    }

    public function markPorted(?string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_PORTED,
            'completed_at' => now(),
            'port_notes' => $notes ?? $this->port_notes,
        ]);
    }

    public function markSkipped(?string $reason = null): void
    {
        $this->update([
            'status' => self::STATUS_SKIPPED,
            'completed_at' => now(),
            'port_notes' => $reason ?? $this->port_notes,
        ]);
    }

    public function markWontPort(?string $reason = null): void
    {
        $this->update([
            'status' => self::STATUS_WONT_PORT,
            'completed_at' => now(),
            'port_notes' => $reason ?? $this->port_notes,
        ]);
    }

    public function getFilesCount(): int
    {
        return count($this->files ?? []);
    }

    public function getPriorityLabel(): string
    {
        return match (true) {
            $this->priority >= 8 => 'Critical',
            $this->priority >= 6 => 'High',
            $this->priority >= 4 => 'Medium',
            default => 'Low',
        };
    }

    public function getEffortLabel(): string
    {
        return match ($this->effort) {
            self::EFFORT_LOW => '< 1 hour',
            self::EFFORT_MEDIUM => '1-4 hours',
            self::EFFORT_HIGH => '4+ hours',
            default => 'Unknown',
        };
    }

    public function getTypeIcon(): string
    {
        return match ($this->type) {
            self::TYPE_FEATURE => '✨',
            self::TYPE_BUGFIX => '🐛',
            self::TYPE_SECURITY => '🔒',
            self::TYPE_UI => '🎨',
            self::TYPE_BLOCK => '🧱',
            self::TYPE_API => '🔌',
            self::TYPE_REFACTOR => '♻️',
            self::TYPE_DEPENDENCY => '📦',
            default => '📝',
        };
    }

    public function getStatusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'bg-yellow-100 text-yellow-800',
            self::STATUS_IN_PROGRESS => 'bg-blue-100 text-blue-800',
            self::STATUS_PORTED => 'bg-green-100 text-green-800',
            self::STATUS_SKIPPED => 'bg-gray-100 text-gray-800',
            self::STATUS_WONT_PORT => 'bg-red-100 text-red-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }
}
