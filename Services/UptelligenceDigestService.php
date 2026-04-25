<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Services;

use Core\Mod\Uptelligence\Jobs\SendUptelligenceDigestJob;
use Core\Mod\Uptelligence\Models\UpstreamTodo;
use Core\Mod\Uptelligence\Models\UptelligenceDigest;
use Core\Mod\Uptelligence\Models\Vendor;
use Core\Mod\Uptelligence\Models\VersionRelease;
use Core\Mod\Uptelligence\Notifications\SendUptelligenceDigest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * UptelligenceDigestService - generates and sends digest email notifications.
 *
 * Collects new releases, pending todos, and security updates since the last
 * digest and sends summarised email notifications to subscribed users.
 */
class UptelligenceDigestService
{
    public function sendDigests(): void
    {
        UptelligenceDigest::enabled()
            ->get()
            ->filter(fn (UptelligenceDigest $digest): bool => $digest->isDue())
            ->each(fn (UptelligenceDigest $digest): mixed => SendUptelligenceDigestJob::dispatch($digest));
    }

    /**
     * Generate digest content for a specific user's preferences.
     *
     * @return array{releases: Collection, todos: array, security_count: int, has_content: bool}
     */
    public function generateDigestContent(UptelligenceDigest $digest): array
    {
        $sinceDate = $digest->last_sent_at ?? now()->subMonth();
        $vendorIds = $digest->getVendorIds();
        $minPriority = $digest->getMinPriority();

        // Build base vendor query
        $vendorQuery = Vendor::active();
        if ($vendorIds !== null) {
            $vendorQuery->whereIn('id', $vendorIds);
        }
        $trackedVendorIds = $vendorQuery->pluck('id');

        // Gather new releases
        $releases = collect();
        if ($digest->includesReleases()) {
            $releases = $this->getNewReleases($trackedVendorIds, $sinceDate);
        }

        // Gather pending todos grouped by priority
        $todosByPriority = [];
        if ($digest->includesTodos()) {
            $todosByPriority = $this->getTodosByPriority($trackedVendorIds, $minPriority);
        }

        // Count security-related updates
        $securityCount = 0;
        if ($digest->includesSecurity()) {
            $securityCount = $this->getSecurityUpdatesCount($trackedVendorIds, $sinceDate);
        }

        $hasContent = $releases->isNotEmpty()
            || ! empty(array_filter($todosByPriority))
            || $securityCount > 0;

        return [
            'releases' => $releases,
            'todos' => $todosByPriority,
            'security_count' => $securityCount,
            'has_content' => $hasContent,
        ];
    }

    /**
     * Get new releases since the given date.
     */
    protected function getNewReleases(Collection $vendorIds, \DateTimeInterface $since): Collection
    {
        return VersionRelease::whereIn('vendor_id', $vendorIds)
            ->where('created_at', '>=', $since)
            ->analyzed()
            ->with('vendor:id,name,slug')
            ->orderByDesc('created_at')
            ->take(20)
            ->get()
            ->map(fn (VersionRelease $release) => [
                'vendor_name' => $release->vendor->name,
                'vendor_slug' => $release->vendor->slug,
                'version' => $release->version,
                'previous_version' => $release->previous_version,
                'files_changed' => $release->getTotalChanges(),
                'impact_level' => $release->getImpactLevel(),
                'todos_created' => $release->todos_created ?? 0,
                'analyzed_at' => $release->analyzed_at,
            ]);
    }

    /**
     * Get pending todos grouped by priority level.
     *
     * @return array{critical: int, high: int, medium: int, low: int, total: int}
     */
    protected function getTodosByPriority(Collection $vendorIds, ?int $minPriority): array
    {
        $query = UpstreamTodo::whereIn('vendor_id', $vendorIds)
            ->pending();

        if ($minPriority !== null) {
            $query->where('priority', '>=', $minPriority);
        }

        $todos = $query->get(['priority']);

        return [
            'critical' => $todos->where('priority', '>=', 8)->count(),
            'high' => $todos->whereBetween('priority', [6, 7])->count(),
            'medium' => $todos->whereBetween('priority', [4, 5])->count(),
            'low' => $todos->where('priority', '<', 4)->count(),
            'total' => $todos->count(),
        ];
    }

    /**
     * Get count of security-related updates since the given date.
     */
    protected function getSecurityUpdatesCount(Collection $vendorIds, \DateTimeInterface $since): int
    {
        return UpstreamTodo::whereIn('vendor_id', $vendorIds)
            ->securityRelated()
            ->pending()
            ->where('created_at', '>=', $since)
            ->count();
    }

    /**
     * Send a digest notification to a user.
     */
    public function sendDigest(UptelligenceDigest $digest): bool
    {
        $content = $this->generateDigestContent($digest);

        // Skip if there's nothing to report
        if (! $content['has_content']) {
            Log::debug('Uptelligence: Skipping empty digest', [
                'user_id' => $digest->user_id,
                'workspace_id' => $digest->workspace_id,
            ]);

            // Still mark as sent to prevent re-checking
            $digest->markAsSent();

            return false;
        }

        try {
            $digest->user->notify(new SendUptelligenceDigest(
                digest: $digest,
                releases: $content['releases'],
                todosByPriority: $content['todos'],
                securityCount: $content['security_count'],
            ));

            $digest->markAsSent();

            Log::info('Uptelligence: Digest sent successfully', [
                'user_id' => $digest->user_id,
                'workspace_id' => $digest->workspace_id,
                'releases_count' => $content['releases']->count(),
                'todos_count' => $content['todos']['total'] ?? 0,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Uptelligence: Failed to send digest', [
                'user_id' => $digest->user_id,
                'workspace_id' => $digest->workspace_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function composeDigestEmail(UptelligenceDigest $digest, iterable $todos): array
    {
        $todoCollection = collect($todos);
        $security = $todoCollection->where('type', UpstreamTodo::TYPE_SECURITY)->count();
        $breaking = $todoCollection->filter(fn (UpstreamTodo $todo): bool => in_array($todo->type, [UpstreamTodo::TYPE_API], true))->count();
        $frequency = $digest->getFrequencyLabel();

        return [
            'subject' => "Uptelligence {$frequency}: {$security} security updates, {$breaking} breaking changes",
            'html' => '<h1>Uptelligence '.$frequency.'</h1>'
                .'<p>'.$todoCollection->count().' upstream todo(s) need review.</p>'
                .'<ul>'.$todoCollection->map(fn (UpstreamTodo $todo): string => '<li><strong>'
                    .e($todo->getPriorityLabel()).'</strong> '.e($todo->title).'</li>')->implode('').'</ul>',
        ];
    }

    /**
     * Process all digests due for a specific frequency.
     *
     * @return array{sent: int, skipped: int, failed: int}
     */
    public function processDigests(string $frequency): array
    {
        $stats = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        $digests = UptelligenceDigest::dueForDigest($frequency)
            ->with(['user', 'workspace'])
            ->get();

        foreach ($digests as $digest) {
            // Skip if user or workspace no longer exists
            if (! $digest->user || ! $digest->workspace) {
                $digest->delete();
                $stats['skipped']++;

                continue;
            }

            $result = $this->sendDigest($digest);

            if ($result) {
                $stats['sent']++;
            } else {
                // Check if it was skipped (empty) or failed
                if (! $this->generateDigestContent($digest)['has_content']) {
                    $stats['skipped']++;
                } else {
                    $stats['failed']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Get a preview of what would be included in a digest.
     *
     * Useful for showing users what they'll receive before enabling.
     */
    public function getDigestPreview(UptelligenceDigest $digest): array
    {
        $content = $this->generateDigestContent($digest);

        // Get top vendors with pending work
        $vendorIds = $digest->getVendorIds();
        $vendorQuery = Vendor::active()
            ->withCount(['todos as pending_count' => fn ($q) => $q->pending()]);

        if ($vendorIds !== null) {
            $vendorQuery->whereIn('id', $vendorIds);
        }

        $topVendors = $vendorQuery
            ->having('pending_count', '>', 0)
            ->orderByDesc('pending_count')
            ->take(5)
            ->get(['id', 'name', 'slug', 'current_version']);

        return [
            'releases' => $content['releases']->take(5),
            'todos' => $content['todos'],
            'security_count' => $content['security_count'],
            'top_vendors' => $topVendors,
            'has_content' => $content['has_content'],
            'frequency_label' => $digest->getFrequencyLabel(),
            'next_send' => $digest->getNextSendDate()?->format('j F Y'),
        ];
    }

    /**
     * Get or create a digest preference for a user in a workspace.
     */
    public function getOrCreateDigest(int $userId, int $workspaceId): UptelligenceDigest
    {
        return UptelligenceDigest::firstOrCreate(
            [
                'user_id' => $userId,
                'workspace_id' => $workspaceId,
            ],
            [
                'frequency' => UptelligenceDigest::FREQUENCY_WEEKLY,
                'is_enabled' => false, // Start disabled, user must opt-in
            ]
        );
    }
}
