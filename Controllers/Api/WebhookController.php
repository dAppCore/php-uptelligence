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
    /**
     * Maximum allowed payload size in bytes (1 MB).
     */
    protected const MAX_PAYLOAD_SIZE = 1048576;

    /**
     * Maximum allowed JSON nesting depth.
     */
    protected const MAX_JSON_DEPTH = 32;

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

        // Validate payload size (DoS protection)
        $payloadValidation = $this->validatePayloadSize($payload, $webhook->id);
        if ($payloadValidation !== null) {
            return $payloadValidation;
        }

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

        // Parse and validate JSON payload
        $data = $this->parseAndValidateJson($payload, $webhook->id);
        if ($data === null) {
            return response('Invalid JSON payload', 400);
        }

        // Validate payload structure
        $structureValidation = $this->validatePayloadStructure($data, $webhook);
        if ($structureValidation !== null) {
            return $structureValidation;
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

    // -------------------------------------------------------------------------
    // Payload Validation Methods
    // -------------------------------------------------------------------------

    /**
     * Validate payload size to prevent DoS attacks.
     */
    protected function validatePayloadSize(string $payload, int $webhookId): ?Response
    {
        $payloadSize = strlen($payload);

        if ($payloadSize > self::MAX_PAYLOAD_SIZE) {
            Log::warning('Uptelligence webhook payload too large', [
                'webhook_id' => $webhookId,
                'payload_size' => $payloadSize,
                'max_size' => self::MAX_PAYLOAD_SIZE,
            ]);

            return response('Payload too large', 413);
        }

        if ($payloadSize === 0) {
            Log::warning('Uptelligence webhook empty payload', [
                'webhook_id' => $webhookId,
            ]);

            return response('Empty payload', 400);
        }

        return null;
    }

    /**
     * Parse and validate JSON payload with depth limit.
     *
     * Returns the parsed data or null on failure.
     */
    protected function parseAndValidateJson(string $payload, int $webhookId): ?array
    {
        // Parse with depth limit to prevent deeply nested JSON attacks
        $data = json_decode($payload, true, self::MAX_JSON_DEPTH);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMessage = json_last_error_msg();

            // Check for depth-related errors
            if (json_last_error() === JSON_ERROR_DEPTH) {
                Log::warning('Uptelligence webhook JSON too deeply nested', [
                    'webhook_id' => $webhookId,
                    'max_depth' => self::MAX_JSON_DEPTH,
                ]);
            } else {
                Log::warning('Uptelligence webhook invalid JSON payload', [
                    'webhook_id' => $webhookId,
                    'error' => $errorMessage,
                ]);
            }

            return null;
        }

        // Ensure payload is an object/array (not a scalar)
        if (! is_array($data)) {
            Log::warning('Uptelligence webhook payload must be an object', [
                'webhook_id' => $webhookId,
                'type' => gettype($data),
            ]);

            return null;
        }

        return $data;
    }

    /**
     * Validate payload structure based on provider.
     *
     * Performs basic schema validation to ensure expected fields exist.
     */
    protected function validatePayloadStructure(array $data, UptelligenceWebhook $webhook): ?Response
    {
        $provider = $webhook->provider;
        $webhookId = $webhook->id;

        // Validate based on provider
        $validation = match ($provider) {
            UptelligenceWebhook::PROVIDER_GITHUB => $this->validateGitHubPayload($data),
            UptelligenceWebhook::PROVIDER_GITLAB => $this->validateGitLabPayload($data),
            UptelligenceWebhook::PROVIDER_NPM => $this->validateNpmPayload($data),
            UptelligenceWebhook::PROVIDER_PACKAGIST => $this->validatePackagistPayload($data),
            default => $this->validateCustomPayload($data),
        };

        if ($validation !== true) {
            Log::warning('Uptelligence webhook payload validation failed', [
                'webhook_id' => $webhookId,
                'provider' => $provider,
                'error' => $validation,
            ]);

            return response('Invalid payload structure: ' . $validation, 400);
        }

        return null;
    }

    /**
     * Validate GitHub webhook payload structure.
     */
    protected function validateGitHubPayload(array $data): string|bool
    {
        // GitHub webhooks should have an action field for most events
        // Release events have release object
        if (isset($data['release'])) {
            if (! is_array($data['release'])) {
                return 'release must be an object';
            }
        }

        // Check for suspiciously large arrays (potential DoS)
        if ($this->hasExcessiveArraySize($data)) {
            return 'payload contains excessively large arrays';
        }

        return true;
    }

    /**
     * Validate GitLab webhook payload structure.
     */
    protected function validateGitLabPayload(array $data): string|bool
    {
        // GitLab webhooks typically have object_kind
        if (isset($data['object_kind']) && ! is_string($data['object_kind'])) {
            return 'object_kind must be a string';
        }

        if ($this->hasExcessiveArraySize($data)) {
            return 'payload contains excessively large arrays';
        }

        return true;
    }

    /**
     * Validate npm webhook payload structure.
     */
    protected function validateNpmPayload(array $data): string|bool
    {
        // npm webhooks should have event field
        if (isset($data['event']) && ! is_string($data['event'])) {
            return 'event must be a string';
        }

        if ($this->hasExcessiveArraySize($data)) {
            return 'payload contains excessively large arrays';
        }

        return true;
    }

    /**
     * Validate Packagist webhook payload structure.
     */
    protected function validatePackagistPayload(array $data): string|bool
    {
        // Packagist should have package or repository info
        if (isset($data['versions']) && ! is_array($data['versions'])) {
            return 'versions must be an array';
        }

        if ($this->hasExcessiveArraySize($data)) {
            return 'payload contains excessively large arrays';
        }

        return true;
    }

    /**
     * Validate custom webhook payload structure.
     */
    protected function validateCustomPayload(array $data): string|bool
    {
        // Minimal validation for custom webhooks
        if ($this->hasExcessiveArraySize($data)) {
            return 'payload contains excessively large arrays';
        }

        return true;
    }

    /**
     * Check if payload contains excessively large arrays (DoS protection).
     *
     * Recursively checks array sizes to prevent memory exhaustion
     * from payloads with many elements at any nesting level.
     */
    protected function hasExcessiveArraySize(array $data, int $maxElements = 1000, int $depth = 0): bool
    {
        // Prevent infinite recursion
        if ($depth > self::MAX_JSON_DEPTH) {
            return true;
        }

        $totalElements = 0;

        foreach ($data as $value) {
            $totalElements++;

            if ($totalElements > $maxElements) {
                return true;
            }

            if (is_array($value)) {
                if ($this->hasExcessiveArraySize($value, $maxElements - $totalElements, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }
}
