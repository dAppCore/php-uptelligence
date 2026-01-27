<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Core\Mod\Uptelligence\Jobs\ProcessUptelligenceWebhook;
use Core\Mod\Uptelligence\Models\UptelligenceWebhook;
use Core\Mod\Uptelligence\Models\UptelligenceWebhookDelivery;
use Core\Mod\Uptelligence\Services\WebhookReceiverService;

/**
 * WebhookController - receives incoming vendor release webhooks.
 *
 * Handles webhooks from GitHub, GitLab, npm, Packagist, and custom sources.
 * Webhooks are validated, logged, and dispatched to a job for async processing.
 */
class WebhookController extends Controller
{
    public function __construct(
        protected WebhookReceiverService $service,
    ) {}

    /**
     * Receive a webhook for a vendor.
     *
     * POST /api/uptelligence/webhook/{webhook}
     */
    public function receive(Request $request, UptelligenceWebhook $webhook): Response
    {
        // Check if webhook is enabled
        if (! $webhook->isActive()) {
            Log::warning('Uptelligence webhook received for disabled endpoint', [
                'webhook_id' => $webhook->id,
                'vendor_id' => $webhook->vendor_id,
            ]);

            return response('Webhook disabled', 403);
        }

        // Check circuit breaker
        if ($webhook->isCircuitBroken()) {
            Log::warning('Uptelligence webhook endpoint circuit breaker open', [
                'webhook_id' => $webhook->id,
                'failure_count' => $webhook->failure_count,
            ]);

            return response('Service unavailable', 503);
        }

        // Get raw payload
        $payload = $request->getContent();

        // Verify signature
        $signature = $this->extractSignature($request, $webhook->provider);
        $signatureStatus = $this->service->verifySignature($webhook, $payload, $signature);

        if ($signatureStatus === UptelligenceWebhookDelivery::SIGNATURE_INVALID) {
            Log::warning('Uptelligence webhook signature verification failed', [
                'webhook_id' => $webhook->id,
                'vendor_id' => $webhook->vendor_id,
                'source_ip' => $request->ip(),
            ]);

            return response('Invalid signature', 401);
        }

        // Parse JSON payload
        $data = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('Uptelligence webhook invalid JSON payload', [
                'webhook_id' => $webhook->id,
                'error' => json_last_error_msg(),
            ]);

            return response('Invalid JSON payload', 400);
        }

        // Determine event type
        $eventType = $this->determineEventType($request, $data, $webhook->provider);

        // Create delivery log
        $delivery = UptelligenceWebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'vendor_id' => $webhook->vendor_id,
            'event_type' => $eventType,
            'provider' => $webhook->provider,
            'payload' => $data,
            'status' => UptelligenceWebhookDelivery::STATUS_PENDING,
            'source_ip' => $request->ip(),
            'signature_status' => $signatureStatus,
        ]);

        Log::info('Uptelligence webhook received', [
            'delivery_id' => $delivery->id,
            'webhook_id' => $webhook->id,
            'vendor_id' => $webhook->vendor_id,
            'event_type' => $eventType,
        ]);

        // Update webhook last received timestamp
        $webhook->markReceived();

        // Dispatch job for async processing
        ProcessUptelligenceWebhook::dispatch($delivery);

        return response('Accepted', 202);
    }

    /**
     * Extract signature from request headers based on provider.
     */
    protected function extractSignature(Request $request, string $provider): ?string
    {
        return match ($provider) {
            UptelligenceWebhook::PROVIDER_GITHUB => $this->extractGitHubSignature($request),
            UptelligenceWebhook::PROVIDER_GITLAB => $request->header('X-Gitlab-Token'),
            UptelligenceWebhook::PROVIDER_NPM => $request->header('X-Npm-Signature'),
            UptelligenceWebhook::PROVIDER_PACKAGIST => $request->header('X-Hub-Signature'),
            default => $this->extractGenericSignature($request),
        };
    }

    /**
     * Extract GitHub signature (prefers SHA-256).
     */
    protected function extractGitHubSignature(Request $request): ?string
    {
        // Prefer SHA-256
        $signature = $request->header('X-Hub-Signature-256');
        if ($signature) {
            return $signature;
        }

        // Fall back to SHA-1 (legacy)
        return $request->header('X-Hub-Signature');
    }

    /**
     * Extract signature from generic headers.
     */
    protected function extractGenericSignature(Request $request): ?string
    {
        $signatureHeaders = [
            'X-Signature',
            'X-Hub-Signature-256',
            'X-Hub-Signature',
            'X-Webhook-Signature',
            'Signature',
        ];

        foreach ($signatureHeaders as $header) {
            $value = $request->header($header);
            if ($value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Determine the event type from request and payload.
     */
    protected function determineEventType(Request $request, array $data, string $provider): string
    {
        return match ($provider) {
            UptelligenceWebhook::PROVIDER_GITHUB => $this->determineGitHubEventType($request, $data),
            UptelligenceWebhook::PROVIDER_GITLAB => $this->determineGitLabEventType($request, $data),
            UptelligenceWebhook::PROVIDER_NPM => $this->determineNpmEventType($data),
            UptelligenceWebhook::PROVIDER_PACKAGIST => $this->determinePackagistEventType($data),
            default => $this->determineGenericEventType($request, $data),
        };
    }

    /**
     * Determine GitHub event type.
     */
    protected function determineGitHubEventType(Request $request, array $data): string
    {
        $event = $request->header('X-GitHub-Event', 'unknown');
        $action = $data['action'] ?? 'unknown';

        return "github.{$event}.{$action}";
    }

    /**
     * Determine GitLab event type.
     */
    protected function determineGitLabEventType(Request $request, array $data): string
    {
        $objectKind = $data['object_kind'] ?? 'unknown';
        $action = $data['action'] ?? 'unknown';

        return "gitlab.{$objectKind}.{$action}";
    }

    /**
     * Determine npm event type.
     */
    protected function determineNpmEventType(array $data): string
    {
        $event = $data['event'] ?? 'package:unknown';
        $normalised = str_replace(':', '.', $event);

        return "npm.{$normalised}";
    }

    /**
     * Determine Packagist event type.
     */
    protected function determinePackagistEventType(array $data): string
    {
        // Packagist webhooks typically indicate an update
        return 'packagist.package.update';
    }

    /**
     * Determine generic event type.
     */
    protected function determineGenericEventType(Request $request, array $data): string
    {
        // Check headers
        $eventType = $request->header('X-Event-Type')
            ?? $request->header('X-Webhook-Event');

        if ($eventType) {
            return "custom.{$eventType}";
        }

        // Check payload
        $event = $data['event']
            ?? $data['event_type']
            ?? $data['action']
            ?? 'unknown';

        return "custom.{$event}";
    }

    /**
     * Test endpoint to verify webhook configuration.
     *
     * POST /api/uptelligence/webhook/{webhook}/test
     */
    public function test(Request $request, UptelligenceWebhook $webhook): Response
    {
        // This endpoint is for testing - requires the webhook to exist
        // and optionally verifies signature

        $payload = $request->getContent();
        $signature = $this->extractSignature($request, $webhook->provider);
        $signatureStatus = $this->service->verifySignature($webhook, $payload, $signature);

        return response()->json([
            'status' => 'ok',
            'webhook_id' => $webhook->uuid,
            'vendor_id' => $webhook->vendor_id,
            'provider' => $webhook->provider,
            'is_active' => $webhook->is_active,
            'signature_status' => $signatureStatus,
            'has_secret' => ! empty($webhook->secret),
        ]);
    }
}
