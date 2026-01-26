<?php

declare(strict_types=1);

namespace Core\Uptelligence\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Core\Uptelligence\Models\UptelligenceWebhookDelivery;
use Core\Uptelligence\Notifications\NewReleaseDetected;
use Core\Uptelligence\Services\WebhookReceiverService;

/**
 * ProcessUptelligenceWebhook - async processing of incoming vendor webhooks.
 *
 * Handles payload parsing, release creation, and notification dispatch.
 */
class ProcessUptelligenceWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 60;

    /**
     * Calculate the number of seconds to wait before retrying.
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * Create a new job instance.
     */
    public function __construct(
        public UptelligenceWebhookDelivery $delivery,
    ) {
        $this->onQueue('uptelligence-webhooks');
    }

    /**
     * Execute the job.
     */
    public function handle(WebhookReceiverService $service): void
    {
        $this->delivery->markProcessing();

        Log::info('Processing Uptelligence webhook', [
            'delivery_id' => $this->delivery->id,
            'webhook_id' => $this->delivery->webhook_id,
            'vendor_id' => $this->delivery->vendor_id,
            'event_type' => $this->delivery->event_type,
        ]);

        try {
            // Get webhook and vendor
            $webhook = $this->delivery->webhook;
            $vendor = $this->delivery->vendor;

            if (! $webhook || ! $vendor) {
                throw new \RuntimeException('Webhook or vendor not found');
            }

            // Parse the payload
            $parsedData = $service->parsePayload(
                $this->delivery->provider,
                $this->delivery->payload
            );

            if (! $parsedData) {
                $this->delivery->markSkipped('Not a release event or unable to parse');
                Log::info('Uptelligence webhook skipped (not a release event)', [
                    'delivery_id' => $this->delivery->id,
                ]);

                return;
            }

            // Process the release
            $result = $service->processRelease(
                $this->delivery,
                $vendor,
                $parsedData
            );

            // Update delivery record
            $this->delivery->update([
                'version' => $parsedData['version'] ?? null,
                'tag_name' => $parsedData['tag_name'] ?? null,
                'parsed_data' => $parsedData,
            ]);

            // Mark as completed
            $this->delivery->markCompleted($parsedData);

            // Reset failure count on webhook
            $webhook->resetFailureCount();

            // Send notification if new release was created
            if ($result['action'] === 'created') {
                $this->sendReleaseNotification($vendor, $parsedData, $result);
            }

            Log::info('Uptelligence webhook processed successfully', [
                'delivery_id' => $this->delivery->id,
                'action' => $result['action'],
                'version' => $result['version'] ?? null,
                'release_id' => $result['release_id'] ?? null,
            ]);
        } catch (\Exception $e) {
            $this->handleFailure($e);
            throw $e;
        }
    }

    /**
     * Send notification when a new release is detected.
     */
    protected function sendReleaseNotification(
        \Core\Uptelligence\Models\Vendor $vendor,
        array $parsedData,
        array $result
    ): void {
        try {
            // Get users subscribed to digest notifications for this vendor
            $digests = \Core\Uptelligence\Models\UptelligenceDigest::where('is_enabled', true)
                ->with('user')
                ->get();

            foreach ($digests as $digest) {
                // Check if this digest includes releases and this vendor
                if ($digest->user && $digest->includesReleases() && $digest->includesVendor($vendor->id)) {
                    $digest->user->notify(new NewReleaseDetected(
                        vendor: $vendor,
                        version: $parsedData['version'],
                        releaseData: $parsedData,
                    ));
                }
            }
        } catch (\Exception $e) {
            // Don't fail the webhook processing if notification fails
            Log::warning('Failed to send release notification', [
                'delivery_id' => $this->delivery->id,
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle job failure.
     */
    protected function handleFailure(\Exception $e): void
    {
        $this->delivery->markFailed($e->getMessage());

        // Increment failure count on webhook
        if ($webhook = $this->delivery->webhook) {
            $webhook->incrementFailureCount();
        }

        Log::error('Uptelligence webhook processing failed', [
            'delivery_id' => $this->delivery->id,
            'webhook_id' => $this->delivery->webhook_id,
            'error' => $e->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }

    /**
     * Handle a job failure (called by Laravel).
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Uptelligence webhook job failed permanently', [
            'delivery_id' => $this->delivery->id,
            'webhook_id' => $this->delivery->webhook_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        $this->delivery->markFailed(
            "Processing failed after {$this->attempts()} attempts: {$exception->getMessage()}"
        );
    }
}
