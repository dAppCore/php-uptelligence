<?php

declare(strict_types=1);

namespace Core\Uptelligence\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Analysis Log - audit trail for upstream analysis operations.
 *
 * Tracks version detection, analysis runs, todos created, and porting progress.
 */
class AnalysisLog extends Model
{
    use HasFactory;

    // Actions
    public const ACTION_VERSION_DETECTED = 'version_detected';

    public const ACTION_ANALYSIS_STARTED = 'analysis_started';

    public const ACTION_ANALYSIS_COMPLETED = 'analysis_completed';

    public const ACTION_ANALYSIS_FAILED = 'analysis_failed';

    public const ACTION_TODO_CREATED = 'todo_created';

    public const ACTION_TODO_UPDATED = 'todo_updated';

    public const ACTION_ISSUE_CREATED = 'issue_created';

    public const ACTION_PORT_STARTED = 'port_started';

    public const ACTION_PORT_COMPLETED = 'port_completed';

    protected $fillable = [
        'vendor_id',
        'version_release_id',
        'action',
        'context',
        'error_message',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    // Relationships
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function versionRelease(): BelongsTo
    {
        return $this->belongsTo(VersionRelease::class);
    }

    // Scopes
    public function scopeErrors($query)
    {
        return $query->whereNotNull('error_message');
    }

    public function scopeRecent($query, int $limit = 50)
    {
        return $query->latest()->limit($limit);
    }

    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    // Factory methods
    public static function logVersionDetected(Vendor $vendor, string $version, ?string $previousVersion = null): self
    {
        return self::create([
            'vendor_id' => $vendor->id,
            'action' => self::ACTION_VERSION_DETECTED,
            'context' => [
                'version' => $version,
                'previous_version' => $previousVersion,
            ],
        ]);
    }

    public static function logAnalysisStarted(VersionRelease $release): self
    {
        return self::create([
            'vendor_id' => $release->vendor_id,
            'version_release_id' => $release->id,
            'action' => self::ACTION_ANALYSIS_STARTED,
            'context' => [
                'version' => $release->version,
            ],
        ]);
    }

    public static function logAnalysisCompleted(VersionRelease $release, array $stats): self
    {
        return self::create([
            'vendor_id' => $release->vendor_id,
            'version_release_id' => $release->id,
            'action' => self::ACTION_ANALYSIS_COMPLETED,
            'context' => $stats,
        ]);
    }

    public static function logAnalysisFailed(VersionRelease $release, string $error): self
    {
        return self::create([
            'vendor_id' => $release->vendor_id,
            'version_release_id' => $release->id,
            'action' => self::ACTION_ANALYSIS_FAILED,
            'error_message' => $error,
        ]);
    }

    public static function logTodoCreated(UpstreamTodo $todo): self
    {
        return self::create([
            'vendor_id' => $todo->vendor_id,
            'action' => self::ACTION_TODO_CREATED,
            'context' => [
                'todo_id' => $todo->id,
                'title' => $todo->title,
                'type' => $todo->type,
                'priority' => $todo->priority,
            ],
        ]);
    }

    public static function logIssueCreated(UpstreamTodo $todo, string $issueUrl): self
    {
        return self::create([
            'vendor_id' => $todo->vendor_id,
            'action' => self::ACTION_ISSUE_CREATED,
            'context' => [
                'todo_id' => $todo->id,
                'issue_url' => $issueUrl,
                'issue_number' => $todo->github_issue_number,
            ],
        ]);
    }

    // Helpers
    public function isError(): bool
    {
        return $this->error_message !== null;
    }

    public function getActionIcon(): string
    {
        return match ($this->action) {
            self::ACTION_VERSION_DETECTED => '📦',
            self::ACTION_ANALYSIS_STARTED => '🔍',
            self::ACTION_ANALYSIS_COMPLETED => '✅',
            self::ACTION_ANALYSIS_FAILED => '❌',
            self::ACTION_TODO_CREATED => '📝',
            self::ACTION_TODO_UPDATED => '✏️',
            self::ACTION_ISSUE_CREATED => '🎫',
            self::ACTION_PORT_STARTED => '🚀',
            self::ACTION_PORT_COMPLETED => '🎉',
            default => '📌',
        };
    }

    public function getActionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_VERSION_DETECTED => 'New Version Detected',
            self::ACTION_ANALYSIS_STARTED => 'Analysis Started',
            self::ACTION_ANALYSIS_COMPLETED => 'Analysis Completed',
            self::ACTION_ANALYSIS_FAILED => 'Analysis Failed',
            self::ACTION_TODO_CREATED => 'Todo Created',
            self::ACTION_TODO_UPDATED => 'Todo Updated',
            self::ACTION_ISSUE_CREATED => 'Issue Created',
            self::ACTION_PORT_STARTED => 'Port Started',
            self::ACTION_PORT_COMPLETED => 'Port Completed',
            default => ucfirst(str_replace('_', ' ', $this->action)),
        };
    }
}
