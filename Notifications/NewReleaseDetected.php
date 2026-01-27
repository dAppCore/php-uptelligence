<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Core\Mod\Uptelligence\Models\Vendor;

/**
 * NewReleaseDetected - notification when a vendor releases a new version.
 *
 * Sent via webhook detection for immediate awareness of new releases.
 */
class NewReleaseDetected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Vendor $vendor,
        public string $version,
        public array $releaseData = [],
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
            ->greeting('New Release Detected');

        // Main announcement
        $message->line("**{$this->vendor->name}** has released version **{$this->version}**.");

        // Previous version context
        if ($this->vendor->previous_version) {
            $message->line("Previous version: {$this->vendor->previous_version}");
        }

        // Release details
        if (! empty($this->releaseData['release_name'])) {
            $message->line('---');
            $message->line("**Release:** {$this->releaseData['release_name']}");
        }

        // Release notes excerpt
        if (! empty($this->releaseData['body'])) {
            $excerpt = $this->getBodyExcerpt($this->releaseData['body'], 200);
            $message->line('**Notes:**');
            $message->line($excerpt);
        }

        // Prerelease warning
        if ($this->releaseData['prerelease'] ?? false) {
            $message->line('---');
            $message->line('This is a **pre-release** version.');
        }

        // Call to action
        $message->action('View in Dashboard', route('hub.admin.uptelligence.vendors'));

        // Release URL
        if (! empty($this->releaseData['url'])) {
            $message->line('---');
            $message->line("[View release on {$this->getProviderName()}]({$this->releaseData['url']})");
        }

        $message->salutation('Host UK - Uptelligence');

        return $message;
    }

    /**
     * Get the subject line.
     */
    protected function getSubject(): string
    {
        $prerelease = ($this->releaseData['prerelease'] ?? false) ? ' (pre-release)' : '';

        return "New release: {$this->vendor->name} {$this->version}{$prerelease}";
    }

    /**
     * Get the provider name for display.
     */
    protected function getProviderName(): string
    {
        $eventType = $this->releaseData['event_type'] ?? '';

        return match (true) {
            str_starts_with($eventType, 'github.') => 'GitHub',
            str_starts_with($eventType, 'gitlab.') => 'GitLab',
            str_starts_with($eventType, 'npm.') => 'npm',
            str_starts_with($eventType, 'packagist.') => 'Packagist',
            default => 'source',
        };
    }

    /**
     * Get a truncated excerpt of the body text.
     */
    protected function getBodyExcerpt(string $body, int $maxLength): string
    {
        // Remove markdown links for cleaner display
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $body);

        // Remove excessive newlines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Truncate
        if (strlen($text) > $maxLength) {
            $text = substr($text, 0, $maxLength);
            // Try to end at a sentence or word boundary
            if (preg_match('/^(.+[.!?])\s/', $text, $matches)) {
                $text = $matches[1];
            } else {
                $text = preg_replace('/\s+\S*$/', '', $text).'...';
            }
        }

        return $text;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'vendor_id' => $this->vendor->id,
            'vendor_name' => $this->vendor->name,
            'version' => $this->version,
            'previous_version' => $this->vendor->previous_version,
            'prerelease' => $this->releaseData['prerelease'] ?? false,
            'release_url' => $this->releaseData['url'] ?? null,
        ];
    }
}
