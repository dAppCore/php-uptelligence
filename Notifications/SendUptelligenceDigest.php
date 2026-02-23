<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Notifications;

use Core\Mod\Uptelligence\Models\UptelligenceDigest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * SendUptelligenceDigest - email notification for vendor update summaries.
 *
 * Sends a periodic digest of new releases, pending todos, and security
 * updates from tracked upstream vendors.
 */
class SendUptelligenceDigest extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public UptelligenceDigest $digest,
        public Collection $releases,
        public array $todosByPriority,
        public int $securityCount,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->getSubject())
            ->greeting($this->getGreeting());

        // Add security alert if there are security updates
        if ($this->securityCount > 0) {
            $message->line($this->formatSecurityAlert());
        }

        // Summary overview
        $message->line($this->formatSummary());

        // New releases section
        if ($this->releases->isNotEmpty()) {
            $message->line('---');
            $message->line('**New Releases**');

            foreach ($this->releases->take(5) as $release) {
                $message->line($this->formatRelease($release));
            }

            if ($this->releases->count() > 5) {
                $remaining = $this->releases->count() - 5;
                $message->line("*...and {$remaining} more release(s)*");
            }
        }

        // Todos summary section
        if (($this->todosByPriority['total'] ?? 0) > 0) {
            $message->line('---');
            $message->line('**Pending Work**');
            $message->line($this->formatTodosBreakdown());
        }

        // Call to action
        $message->action('View Dashboard', route('hub.admin.uptelligence.dashboard'));

        // Footer
        $message->line('---');
        $message->line($this->formatFrequencyNote());
        $message->salutation('Host UK');

        return $message;
    }

    /**
     * Get the subject line based on content.
     */
    protected function getSubject(): string
    {
        $parts = [];

        if ($this->securityCount > 0) {
            $parts[] = "{$this->securityCount} security";
        }

        if ($this->releases->isNotEmpty()) {
            $count = $this->releases->count();
            $parts[] = "{$count} release".($count !== 1 ? 's' : '');
        }

        $totalTodos = $this->todosByPriority['total'] ?? 0;
        if ($totalTodos > 0) {
            $parts[] = "{$totalTodos} pending";
        }

        if (empty($parts)) {
            return 'Uptelligence digest - no new updates';
        }

        $summary = implode(', ', $parts);

        return "Uptelligence digest - {$summary}";
    }

    /**
     * Get the greeting based on time of day.
     */
    protected function getGreeting(): string
    {
        $hour = now()->hour;

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    /**
     * Format the security alert message.
     */
    protected function formatSecurityAlert(): string
    {
        $plural = $this->securityCount !== 1 ? 's' : '';

        return "**Security Alert:** {$this->securityCount} security-related update{$plural} "
            .'require attention. Review these items as a priority.';
    }

    /**
     * Format the summary overview.
     */
    protected function formatSummary(): string
    {
        $frequency = $this->digest->getFrequencyLabel();
        $period = match ($this->digest->frequency) {
            UptelligenceDigest::FREQUENCY_DAILY => 'the past day',
            UptelligenceDigest::FREQUENCY_WEEKLY => 'the past week',
            UptelligenceDigest::FREQUENCY_MONTHLY => 'the past month',
            default => 'recently',
        };

        $parts = [];

        if ($this->releases->isNotEmpty()) {
            $count = $this->releases->count();
            $parts[] = "{$count} new release".($count !== 1 ? 's' : '');
        }

        $totalTodos = $this->todosByPriority['total'] ?? 0;
        if ($totalTodos > 0) {
            $parts[] = "{$totalTodos} pending task".($totalTodos !== 1 ? 's' : '');
        }

        if (empty($parts)) {
            return "Your {$frequency} summary for {$period}: No significant updates.";
        }

        $summary = implode(' and ', $parts);

        return "Your {$frequency} summary for {$period}: {$summary}.";
    }

    /**
     * Format a single release for the email.
     */
    protected function formatRelease(array $release): string
    {
        $version = $release['version'];
        $vendor = $release['vendor_name'];
        $impact = ucfirst($release['impact_level']);
        $changes = $release['files_changed'];

        $previousVersion = $release['previous_version'];
        $versionText = $previousVersion
            ? "{$previousVersion} to {$version}"
            : $version;

        return "- **{$vendor}** updated to {$versionText} ({$changes} files, {$impact} impact)";
    }

    /**
     * Format the todos breakdown.
     */
    protected function formatTodosBreakdown(): string
    {
        $parts = [];

        $critical = $this->todosByPriority['critical'] ?? 0;
        $high = $this->todosByPriority['high'] ?? 0;
        $medium = $this->todosByPriority['medium'] ?? 0;
        $low = $this->todosByPriority['low'] ?? 0;

        if ($critical > 0) {
            $parts[] = "{$critical} critical";
        }
        if ($high > 0) {
            $parts[] = "{$high} high priority";
        }
        if ($medium > 0) {
            $parts[] = "{$medium} medium";
        }
        if ($low > 0) {
            $parts[] = "{$low} low";
        }

        if (empty($parts)) {
            return 'No pending tasks at this time.';
        }

        return implode(', ', $parts).' items awaiting review.';
    }

    /**
     * Format the frequency note.
     */
    protected function formatFrequencyNote(): string
    {
        $frequency = strtolower($this->digest->getFrequencyLabel());

        return "You receive this digest {$frequency}. "
            .'Update your preferences in the Uptelligence settings.';
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'digest_id' => $this->digest->id,
            'workspace_id' => $this->digest->workspace_id,
            'releases_count' => $this->releases->count(),
            'todos_total' => $this->todosByPriority['total'] ?? 0,
            'security_count' => $this->securityCount,
            'frequency' => $this->digest->frequency,
        ];
    }
}
