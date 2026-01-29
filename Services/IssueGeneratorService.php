<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Core\Mod\Uptelligence\Models\AnalysisLog;
use Core\Mod\Uptelligence\Models\UpstreamTodo;
use Core\Mod\Uptelligence\Models\Vendor;

/**
 * Issue Generator Service - creates GitHub/Gitea issues from upstream todos.
 *
 * Generates individual issues and weekly digests for tracking porting work.
 */
class IssueGeneratorService
{
    protected string $githubToken;

    protected string $giteaUrl;

    protected string $giteaToken;

    protected array $defaultLabels;

    protected array $assignees;

    public function __construct()
    {
        $this->githubToken = config('upstream.github.token', '');
        $this->giteaUrl = config('upstream.gitea.url', '');
        $this->giteaToken = config('upstream.gitea.token', '');
        $this->defaultLabels = config('upstream.github.default_labels', ['upstream']);
        $this->assignees = array_filter(config('upstream.github.assignees', []));
    }

    /**
     * Validate target_repo format (should be 'owner/repo').
     *
     * @throws InvalidArgumentException if format is invalid
     */
    protected function validateTargetRepo(?string $targetRepo): bool
    {
        if (empty($targetRepo)) {
            return false;
        }

        // Must be in format 'owner/repo' with no extra slashes
        if (! preg_match('#^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$#', $targetRepo)) {
            Log::warning('Uptelligence: Invalid target_repo format', [
                'target_repo' => $targetRepo,
                'expected_format' => 'owner/repo',
            ]);

            return false;
        }

        return true;
    }

    /**
     * Create GitHub issues for all pending todos.
     */
    public function createIssuesForVendor(Vendor $vendor, bool $useGitea = false): Collection
    {
        // Validate target_repo format before processing
        if (! $this->validateTargetRepo($vendor->target_repo)) {
            Log::error('Uptelligence: Cannot create issues - invalid target_repo', [
                'vendor' => $vendor->slug,
                'target_repo' => $vendor->target_repo,
            ]);

            return collect();
        }

        $todos = $vendor->todos()
            ->where('status', UpstreamTodo::STATUS_PENDING)
            ->whereNull('github_issue_number')
            ->orderByDesc('priority')
            ->get();

        $issues = collect();

        foreach ($todos as $todo) {
            // Check rate limit before creating issue
            if (RateLimiter::tooManyAttempts('upstream-issues', 10)) {
                $seconds = RateLimiter::availableIn('upstream-issues');
                Log::warning('Uptelligence: Issue creation rate limit exceeded', [
                    'vendor' => $vendor->slug,
                    'retry_after_seconds' => $seconds,
                ]);
                break;
            }

            try {
                if ($useGitea) {
                    $issue = $this->createGiteaIssue($todo);
                } else {
                    $issue = $this->createGitHubIssue($todo);
                }

                if ($issue) {
                    $issues->push($issue);
                    RateLimiter::hit('upstream-issues');
                }
            } catch (\Exception $e) {
                Log::error('Uptelligence: Failed to create issue', [
                    'vendor' => $vendor->slug,
                    'todo_id' => $todo->id,
                    'todo_title' => $todo->title,
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                ]);
                report($e);
            }
        }

        return $issues;
    }

    /**
     * Create a GitHub issue for a todo with retry logic.
     */
    public function createGitHubIssue(UpstreamTodo $todo): ?array
    {
        if (! $this->githubToken || ! $this->validateTargetRepo($todo->vendor->target_repo)) {
            return null;
        }

        $body = $this->buildIssueBody($todo);
        $labels = $this->buildLabels($todo);

        [$owner, $repo] = explode('/', $todo->vendor->target_repo);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->githubToken,
            'Accept' => 'application/vnd.github.v3+json',
        ])
            ->timeout(30)
            ->retry(3, function (int $attempt, \Exception $exception) {
                // Exponential backoff: 1s, 2s, 4s
                $delay = (int) pow(2, $attempt - 1) * 1000;

                Log::warning('Uptelligence: GitHub API retry', [
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                    'error' => $exception->getMessage(),
                ]);

                return $delay;
            }, function (\Exception $exception) {
                // Only retry on connection/timeout errors or 5xx/429 responses
                if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
                    return true;
                }
                if ($exception instanceof \Illuminate\Http\Client\RequestException) {
                    $status = $exception->response?->status();

                    return $status >= 500 || $status === 429;
                }

                return false;
            })
            ->post("https://api.github.com/repos/{$owner}/{$repo}/issues", [
                'title' => $this->buildIssueTitle($todo),
                'body' => $body,
                'labels' => $labels,
                'assignees' => $this->assignees,
            ]);

        if ($response->successful()) {
            $issue = $response->json();

            $todo->update([
                'github_issue_number' => $issue['number'],
            ]);

            AnalysisLog::logIssueCreated($todo, $issue['html_url']);

            return $issue;
        }

        Log::error('Uptelligence: GitHub issue creation failed', [
            'todo_id' => $todo->id,
            'status' => $response->status(),
            'body' => $this->redactSensitiveData(substr($response->body(), 0, 500)),
        ]);

        return null;
    }

    /**
     * Create a Gitea issue for a todo with retry logic.
     */
    public function createGiteaIssue(UpstreamTodo $todo): ?array
    {
        if (! $this->giteaToken || ! $this->giteaUrl || ! $this->validateTargetRepo($todo->vendor->target_repo)) {
            return null;
        }

        $body = $this->buildIssueBody($todo);

        [$owner, $repo] = explode('/', $todo->vendor->target_repo);

        $response = Http::withHeaders([
            'Authorization' => 'token '.$this->giteaToken,
            'Accept' => 'application/json',
        ])
            ->timeout(30)
            ->retry(3, function (int $attempt, \Exception $exception) {
                // Exponential backoff: 1s, 2s, 4s
                $delay = (int) pow(2, $attempt - 1) * 1000;

                Log::warning('Uptelligence: Gitea API retry', [
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                    'error' => $exception->getMessage(),
                ]);

                return $delay;
            }, function (\Exception $exception) {
                // Only retry on connection/timeout errors or 5xx/429 responses
                if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
                    return true;
                }
                if ($exception instanceof \Illuminate\Http\Client\RequestException) {
                    $status = $exception->response?->status();

                    return $status >= 500 || $status === 429;
                }

                return false;
            })
            ->post("{$this->giteaUrl}/api/v1/repos/{$owner}/{$repo}/issues", [
                'title' => $this->buildIssueTitle($todo),
                'body' => $body,
                'labels' => [], // Gitea handles labels differently
            ]);

        if ($response->successful()) {
            $issue = $response->json();

            $todo->update([
                'github_issue_number' => (string) $issue['number'],
            ]);

            $issueUrl = "{$this->giteaUrl}/{$owner}/{$repo}/issues/{$issue['number']}";
            AnalysisLog::logIssueCreated($todo, $issueUrl);

            return $issue;
        }

        Log::error('Uptelligence: Gitea issue creation failed', [
            'todo_id' => $todo->id,
            'status' => $response->status(),
            'body' => $this->redactSensitiveData(substr($response->body(), 0, 500)),
        ]);

        return null;
    }

    /**
     * Redact sensitive data from log messages.
     *
     * Removes or masks API tokens and credentials that might
     * appear in error responses.
     */
    protected function redactSensitiveData(string $content): string
    {
        $patterns = [
            // GitHub tokens (ghp_..., gho_..., github_pat_...)
            '/ghp_[a-zA-Z0-9]+/' => '[REDACTED_GITHUB_TOKEN]',
            '/gho_[a-zA-Z0-9]+/' => '[REDACTED_GITHUB_TOKEN]',
            '/github_pat_[a-zA-Z0-9_]+/' => '[REDACTED_GITHUB_TOKEN]',
            // Generic bearer tokens
            '/Bearer\s+[a-zA-Z0-9._-]+/' => 'Bearer [REDACTED]',
            // Gitea tokens
            '/token\s+[a-zA-Z0-9]{20,}/' => 'token [REDACTED]',
            // Authorization header values
            '/["\']?[Aa]uthorization["\']?\s*:\s*["\']?[^"\'}\s]+/' => '"authorization": "[REDACTED]"',
            // Generic token patterns in JSON
            '/["\']?token["\']?\s*:\s*["\']?[a-zA-Z0-9._-]{20,}["\']?/' => '"token": "[REDACTED]"',
        ];

        $redacted = $content;
        foreach ($patterns as $pattern => $replacement) {
            $redacted = preg_replace($pattern, $replacement, $redacted);
        }

        return $redacted;
    }

    /**
     * Build issue title.
     */
    protected function buildIssueTitle(UpstreamTodo $todo): string
    {
        $icon = $todo->getTypeIcon();
        $prefix = '[Upstream] ';

        return $prefix.$icon.' '.$todo->title;
    }

    /**
     * Build issue body with all relevant info.
     */
    protected function buildIssueBody(UpstreamTodo $todo): string
    {
        $body = "## Upstream Change\n\n";
        $body .= "**Vendor:** {$todo->vendor->name} ({$todo->vendor->vendor_name})\n";
        $body .= "**Version:** {$todo->from_version} → {$todo->to_version}\n";
        $body .= "**Type:** {$todo->type}\n";
        $body .= "**Priority:** {$todo->priority}/10 ({$todo->getPriorityLabel()})\n";
        $body .= "**Effort:** {$todo->getEffortLabel()}\n\n";

        if ($todo->description) {
            $body .= "## Description\n\n{$todo->description}\n\n";
        }

        if ($todo->port_notes) {
            $body .= "## Porting Notes\n\n{$todo->port_notes}\n\n";
        }

        if ($todo->has_conflicts) {
            $body .= "## ⚠️ Potential Conflicts\n\n{$todo->conflict_reason}\n\n";
        }

        if (! empty($todo->files)) {
            $body .= "## Files Changed\n\n";
            foreach ($todo->files as $file) {
                $mapped = $todo->vendor->mapToHostHub($file);
                if ($mapped) {
                    $body .= "- `{$file}` → `{$mapped}`\n";
                } else {
                    $body .= "- `{$file}`\n";
                }
            }
            $body .= "\n";
        }

        if (! empty($todo->dependencies)) {
            $body .= "## Dependencies\n\n";
            foreach ($todo->dependencies as $dep) {
                $body .= "- {$dep}\n";
            }
            $body .= "\n";
        }

        if (! empty($todo->tags)) {
            $body .= "## Tags\n\n";
            $body .= implode(', ', array_map(fn ($t) => "`{$t}`", $todo->tags))."\n\n";
        }

        $body .= "---\n";
        $body .= "_Auto-generated by Upstream Intelligence Pipeline_\n";
        $body .= '_AI Confidence: '.round(($todo->ai_confidence ?? 0.85) * 100)."%_\n";

        return $body;
    }

    /**
     * Build labels for the issue.
     */
    protected function buildLabels(UpstreamTodo $todo): array
    {
        $labels = $this->defaultLabels;

        // Add type label
        $labels[] = 'type:'.$todo->type;

        // Add priority label
        if ($todo->priority >= 8) {
            $labels[] = 'priority:high';
        } elseif ($todo->priority >= 5) {
            $labels[] = 'priority:medium';
        } else {
            $labels[] = 'priority:low';
        }

        // Add effort label
        $labels[] = 'effort:'.$todo->effort;

        // Add quick-win label
        if ($todo->isQuickWin()) {
            $labels[] = 'quick-win';
        }

        // Add vendor label
        $labels[] = 'vendor:'.$todo->vendor->slug;

        return $labels;
    }

    /**
     * Create a weekly digest issue.
     */
    public function createWeeklyDigest(Vendor $vendor): ?array
    {
        $todos = $vendor->todos()
            ->where('status', UpstreamTodo::STATUS_PENDING)
            ->whereNull('github_issue_number')
            ->where('created_at', '>=', now()->subWeek())
            ->orderByDesc('priority')
            ->get();

        if ($todos->isEmpty()) {
            return null;
        }

        $title = "[Weekly Digest] {$vendor->name} - ".now()->format('M d, Y');
        $body = $this->buildDigestBody($vendor, $todos);

        if (! $this->githubToken || ! $vendor->target_repo) {
            return null;
        }

        [$owner, $repo] = explode('/', $vendor->target_repo);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->githubToken,
            'Accept' => 'application/vnd.github.v3+json',
        ])->post("https://api.github.com/repos/{$owner}/{$repo}/issues", [
            'title' => $title,
            'body' => $body,
            'labels' => ['upstream', 'digest'],
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }

    /**
     * Build weekly digest body.
     */
    protected function buildDigestBody(Vendor $vendor, Collection $todos): string
    {
        $body = "# Weekly Upstream Digest\n\n";
        $body .= "**Vendor:** {$vendor->name}\n";
        $body .= '**Week of:** '.now()->subWeek()->format('M d').' - '.now()->format('M d, Y')."\n";
        $body .= "**Total Changes:** {$todos->count()}\n\n";

        // Quick wins
        $quickWins = $todos->filter->isQuickWin();
        if ($quickWins->isNotEmpty()) {
            $body .= "## 🚀 Quick Wins ({$quickWins->count()})\n\n";
            foreach ($quickWins as $todo) {
                $body .= "- {$todo->getTypeIcon()} {$todo->title}\n";
            }
            $body .= "\n";
        }

        // Security
        $security = $todos->where('type', 'security');
        if ($security->isNotEmpty()) {
            $body .= "## 🔒 Security Updates ({$security->count()})\n\n";
            foreach ($security as $todo) {
                $body .= "- {$todo->title}\n";
            }
            $body .= "\n";
        }

        // Features
        $features = $todos->where('type', 'feature');
        if ($features->isNotEmpty()) {
            $body .= "## ✨ New Features ({$features->count()})\n\n";
            foreach ($features as $todo) {
                $body .= "- {$todo->title} (Priority: {$todo->priority}/10)\n";
            }
            $body .= "\n";
        }

        // Bug fixes
        $bugfixes = $todos->where('type', 'bugfix');
        if ($bugfixes->isNotEmpty()) {
            $body .= "## 🐛 Bug Fixes ({$bugfixes->count()})\n\n";
            foreach ($bugfixes as $todo) {
                $body .= "- {$todo->title}\n";
            }
            $body .= "\n";
        }

        // Other
        $other = $todos->whereNotIn('type', ['feature', 'bugfix', 'security'])->where(fn ($t) => ! $t->isQuickWin());
        if ($other->isNotEmpty()) {
            $body .= "## 📝 Other Changes ({$other->count()})\n\n";
            foreach ($other as $todo) {
                $body .= "- {$todo->getTypeIcon()} {$todo->title}\n";
            }
            $body .= "\n";
        }

        $body .= "---\n";
        $body .= "_Auto-generated by Upstream Intelligence Pipeline_\n";

        return $body;
    }
}
