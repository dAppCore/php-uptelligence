<?php

declare(strict_types=1);

namespace Core\Uptelligence\Console;

use Illuminate\Console\Command;
use Core\Uptelligence\Models\UptelligenceDigest;
use Core\Uptelligence\Services\UptelligenceDigestService;

/**
 * Send Uptelligence digest emails to subscribed users.
 *
 * Processes all digest subscriptions based on their configured frequency
 * and sends email summaries of vendor updates, new releases, and pending todos.
 */
class SendDigestsCommand extends Command
{
    protected $signature = 'uptelligence:send-digests
                            {--frequency= : Process only a specific frequency (daily, weekly, monthly)}
                            {--dry-run : Show what would happen without sending}';

    protected $description = 'Send Uptelligence digest emails to subscribed users';

    public function __construct(
        protected UptelligenceDigestService $digestService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $frequency = $this->option('frequency');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No emails will be sent');
            $this->newLine();
        }

        $frequencies = $frequency
            ? [$frequency]
            : [
                UptelligenceDigest::FREQUENCY_DAILY,
                UptelligenceDigest::FREQUENCY_WEEKLY,
                UptelligenceDigest::FREQUENCY_MONTHLY,
            ];

        $totalStats = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($frequencies as $freq) {
            $this->processFrequency($freq, $dryRun, $totalStats);
        }

        $this->newLine();
        $this->info('Digest processing complete.');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Sent', $totalStats['sent']],
                ['Skipped (no content)', $totalStats['skipped']],
                ['Failed', $totalStats['failed']],
            ]
        );

        return $totalStats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Process digests for a specific frequency.
     */
    protected function processFrequency(string $frequency, bool $dryRun, array &$totalStats): void
    {
        $this->info("Processing {$frequency} digests...");

        $digests = UptelligenceDigest::dueForDigest($frequency)
            ->with(['user', 'workspace'])
            ->get();

        if ($digests->isEmpty()) {
            $this->line("  No {$frequency} digests due.");

            return;
        }

        $this->line("  Found {$digests->count()} digest(s) to process.");

        foreach ($digests as $digest) {
            $this->processDigest($digest, $dryRun, $totalStats);
        }
    }

    /**
     * Process a single digest.
     */
    protected function processDigest(UptelligenceDigest $digest, bool $dryRun, array &$totalStats): void
    {
        $email = $digest->user?->email ?? 'unknown';
        $workspaceName = $digest->workspace?->name ?? 'unknown';

        // Skip if user or workspace deleted
        if (! $digest->user || ! $digest->workspace) {
            $this->warn("  Skipping digest {$digest->id} - user or workspace deleted");
            $digest->delete();
            $totalStats['skipped']++;

            return;
        }

        // Generate content preview
        $content = $this->digestService->generateDigestContent($digest);

        if (! $content['has_content']) {
            $this->line("  Skipping {$email} ({$workspaceName}) - no content to report");

            if (! $dryRun) {
                $digest->markAsSent();
            }

            $totalStats['skipped']++;

            return;
        }

        $releasesCount = $content['releases']->count();
        $todosCount = $content['todos']['total'] ?? 0;
        $securityCount = $content['security_count'];

        $this->line("  Sending to {$email} ({$workspaceName}): {$releasesCount} releases, {$todosCount} todos, {$securityCount} security");

        if ($dryRun) {
            $totalStats['sent']++;

            return;
        }

        try {
            $this->digestService->sendDigest($digest);
            $this->info('    Sent successfully');
            $totalStats['sent']++;
        } catch (\Exception $e) {
            $this->error("    Failed: {$e->getMessage()}");
            $totalStats['failed']++;
        }
    }
}
