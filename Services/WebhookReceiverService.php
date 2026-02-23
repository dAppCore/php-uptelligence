<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Services;

use Core\Mod\Uptelligence\Models\UptelligenceWebhook;
use Core\Mod\Uptelligence\Models\UptelligenceWebhookDelivery;
use Core\Mod\Uptelligence\Models\Vendor;
use Core\Mod\Uptelligence\Models\VersionRelease;
use Illuminate\Support\Facades\Log;

/**
 * WebhookReceiverService - processes incoming vendor release webhooks.
 *
 * Handles webhook verification, payload parsing, and release record creation
 * for GitHub releases, GitLab releases, npm publish, and Packagist webhooks.
 */
class WebhookReceiverService
{
    // -------------------------------------------------------------------------
    // Signature Verification
    // -------------------------------------------------------------------------

    /**
     * Verify webhook signature.
     *
     * Returns signature status for logging.
     */
    public function verifySignature(UptelligenceWebhook $webhook, string $payload, ?string $signature): string
    {
        if (empty($webhook->secret)) {
            return UptelligenceWebhookDelivery::SIGNATURE_MISSING;
        }

        if (empty($signature)) {
            return UptelligenceWebhookDelivery::SIGNATURE_MISSING;
        }

        $isValid = $webhook->verifySignature($payload, $signature);

        return $isValid
            ? UptelligenceWebhookDelivery::SIGNATURE_VALID
            : UptelligenceWebhookDelivery::SIGNATURE_INVALID;
    }

    // -------------------------------------------------------------------------
    // Payload Parsing
    // -------------------------------------------------------------------------

    /**
     * Parse payload based on provider.
     *
     * Returns normalised release data or null if not a release event.
     *
     * @return array{
     *     event_type: string,
     *     version: string|null,
     *     tag_name: string|null,
     *     release_name: string|null,
     *     body: string|null,
     *     url: string|null,
     *     prerelease: bool,
     *     draft: bool,
     *     published_at: string|null,
     *     author: string|null,
     *     raw: array
     * }|null
     */
    public function parsePayload(string $provider, array $payload): ?array
    {
        return match ($provider) {
            UptelligenceWebhook::PROVIDER_GITHUB => $this->parseGitHubPayload($payload),
            UptelligenceWebhook::PROVIDER_GITLAB => $this->parseGitLabPayload($payload),
            UptelligenceWebhook::PROVIDER_NPM => $this->parseNpmPayload($payload),
            UptelligenceWebhook::PROVIDER_PACKAGIST => $this->parsePackagistPayload($payload),
            default => $this->parseCustomPayload($payload),
        };
    }

    /**
     * Parse GitHub release webhook payload.
     *
     * GitHub sends:
     * - action: published, created, edited, deleted, prereleased, released
     * - release: { tag_name, name, body, draft, prerelease, created_at, published_at, author }
     */
    protected function parseGitHubPayload(array $payload): ?array
    {
        // Only process release events
        $action = $payload['action'] ?? null;
        if (! in_array($action, ['published', 'released', 'created'])) {
            return null;
        }

        $release = $payload['release'] ?? [];
        if (empty($release)) {
            return null;
        }

        $tagName = $release['tag_name'] ?? null;
        $version = $this->normaliseVersion($tagName);

        return [
            'event_type' => "github.release.{$action}",
            'version' => $version,
            'tag_name' => $tagName,
            'release_name' => $release['name'] ?? $tagName,
            'body' => $release['body'] ?? null,
            'url' => $release['html_url'] ?? null,
            'prerelease' => (bool) ($release['prerelease'] ?? false),
            'draft' => (bool) ($release['draft'] ?? false),
            'published_at' => $release['published_at'] ?? $release['created_at'] ?? null,
            'author' => $release['author']['login'] ?? null,
            'raw' => $release,
        ];
    }

    /**
     * Parse GitLab release webhook payload.
     *
     * GitLab sends:
     * - object_kind: release
     * - action: create, update, delete
     * - tag: tag name
     * - name, description, released_at
     */
    protected function parseGitLabPayload(array $payload): ?array
    {
        $objectKind = $payload['object_kind'] ?? null;
        $action = $payload['action'] ?? null;

        // Handle release events
        if ($objectKind === 'release' && in_array($action, ['create', 'update'])) {
            $tagName = $payload['tag'] ?? null;
            $version = $this->normaliseVersion($tagName);

            return [
                'event_type' => "gitlab.release.{$action}",
                'version' => $version,
                'tag_name' => $tagName,
                'release_name' => $payload['name'] ?? $tagName,
                'body' => $payload['description'] ?? null,
                'url' => $payload['url'] ?? null,
                'prerelease' => false,
                'draft' => false,
                'published_at' => $payload['released_at'] ?? $payload['created_at'] ?? null,
                'author' => null,
                'raw' => $payload,
            ];
        }

        // Handle tag push events (may indicate release)
        if ($objectKind === 'tag_push') {
            $ref = $payload['ref'] ?? '';
            $tagName = str_replace('refs/tags/', '', $ref);
            $version = $this->normaliseVersion($tagName);

            // Only process if it looks like a version tag
            if ($version && $this->isVersionTag($tagName)) {
                return [
                    'event_type' => 'gitlab.tag.push',
                    'version' => $version,
                    'tag_name' => $tagName,
                    'release_name' => $tagName,
                    'body' => null,
                    'url' => null,
                    'prerelease' => false,
                    'draft' => false,
                    'published_at' => null,
                    'author' => $payload['user_name'] ?? null,
                    'raw' => $payload,
                ];
            }
        }

        return null;
    }

    /**
     * Parse npm publish webhook payload.
     *
     * npm sends:
     * - event: package:publish
     * - name: package name
     * - version: version number
     * - dist-tags: { latest, next, etc. }
     */
    protected function parseNpmPayload(array $payload): ?array
    {
        $event = $payload['event'] ?? null;

        // Handle package publish events
        if ($event !== 'package:publish') {
            return null;
        }

        $version = $payload['version'] ?? null;
        if (empty($version)) {
            return null;
        }

        $distTags = $payload['dist-tags'] ?? [];
        $isLatest = ($distTags['latest'] ?? null) === $version;

        return [
            'event_type' => 'npm.package.publish',
            'version' => $version,
            'tag_name' => $version,
            'release_name' => ($payload['name'] ?? 'Package')." v{$version}",
            'body' => null,
            'url' => isset($payload['name']) ? "https://www.npmjs.com/package/{$payload['name']}/v/{$version}" : null,
            'prerelease' => ! $isLatest,
            'draft' => false,
            'published_at' => $payload['time'] ?? null,
            'author' => $payload['maintainers'][0]['name'] ?? null,
            'raw' => $payload,
        ];
    }

    /**
     * Parse Packagist webhook payload.
     *
     * Packagist sends:
     * - package: { name, url }
     * - versions: array of version objects
     */
    protected function parsePackagistPayload(array $payload): ?array
    {
        $package = $payload['package'] ?? $payload['repository'] ?? [];
        $versions = $payload['versions'] ?? [];

        // Find the latest version
        if (empty($versions)) {
            return null;
        }

        // Get the most recent version (first in array or highest semver)
        $latestVersion = null;
        $latestVersionData = null;

        foreach ($versions as $versionKey => $versionData) {
            // Skip dev versions
            if (str_contains($versionKey, 'dev-')) {
                continue;
            }

            $normalised = $this->normaliseVersion($versionKey);
            if ($normalised && (! $latestVersion || version_compare($normalised, $latestVersion, '>'))) {
                $latestVersion = $normalised;
                $latestVersionData = $versionData;
            }
        }

        if (! $latestVersion) {
            return null;
        }

        return [
            'event_type' => 'packagist.package.update',
            'version' => $latestVersion,
            'tag_name' => $latestVersionData['version'] ?? $latestVersion,
            'release_name' => ($package['name'] ?? 'Package')." {$latestVersion}",
            'body' => $latestVersionData['description'] ?? null,
            'url' => $package['url'] ?? null,
            'prerelease' => false,
            'draft' => false,
            'published_at' => $latestVersionData['time'] ?? null,
            'author' => $latestVersionData['authors'][0]['name'] ?? null,
            'raw' => $payload,
        ];
    }

    /**
     * Parse custom webhook payload.
     *
     * Accepts a flexible format for custom integrations.
     */
    protected function parseCustomPayload(array $payload): ?array
    {
        // Try common field names for version
        $version = $payload['version']
            ?? $payload['tag']
            ?? $payload['tag_name']
            ?? $payload['release']['version']
            ?? $payload['release']['tag_name']
            ?? null;

        if (empty($version)) {
            return null;
        }

        $normalised = $this->normaliseVersion($version);

        return [
            'event_type' => $payload['event'] ?? $payload['event_type'] ?? 'custom.release',
            'version' => $normalised ?? $version,
            'tag_name' => $version,
            'release_name' => $payload['name'] ?? $payload['release_name'] ?? $version,
            'body' => $payload['body'] ?? $payload['description'] ?? $payload['changelog'] ?? null,
            'url' => $payload['url'] ?? $payload['release_url'] ?? null,
            'prerelease' => (bool) ($payload['prerelease'] ?? false),
            'draft' => (bool) ($payload['draft'] ?? false),
            'published_at' => $payload['published_at'] ?? $payload['released_at'] ?? $payload['timestamp'] ?? null,
            'author' => $payload['author'] ?? null,
            'raw' => $payload,
        ];
    }

    // -------------------------------------------------------------------------
    // Release Processing
    // -------------------------------------------------------------------------

    /**
     * Process a parsed release and create/update vendor version record.
     *
     * @return array{action: string, release_id: int|null, version: string|null}
     */
    public function processRelease(
        UptelligenceWebhookDelivery $delivery,
        Vendor $vendor,
        array $parsedData
    ): array {
        $version = $parsedData['version'] ?? null;

        if (empty($version)) {
            return [
                'action' => 'skipped',
                'release_id' => null,
                'version' => null,
                'reason' => 'No version found in payload',
            ];
        }

        // Skip draft releases
        if ($parsedData['draft'] ?? false) {
            return [
                'action' => 'skipped',
                'release_id' => null,
                'version' => $version,
                'reason' => 'Draft release',
            ];
        }

        // Check if this version already exists
        $existingRelease = VersionRelease::where('vendor_id', $vendor->id)
            ->where('version', $version)
            ->first();

        if ($existingRelease) {
            Log::info('Uptelligence webhook: Version already exists', [
                'vendor_id' => $vendor->id,
                'version' => $version,
                'release_id' => $existingRelease->id,
            ]);

            return [
                'action' => 'exists',
                'release_id' => $existingRelease->id,
                'version' => $version,
            ];
        }

        // Create new version release record
        $release = VersionRelease::create([
            'vendor_id' => $vendor->id,
            'version' => $version,
            'previous_version' => $vendor->current_version,
            'metadata_json' => [
                'release_name' => $parsedData['release_name'] ?? null,
                'body' => $parsedData['body'] ?? null,
                'url' => $parsedData['url'] ?? null,
                'prerelease' => $parsedData['prerelease'] ?? false,
                'published_at' => $parsedData['published_at'] ?? null,
                'author' => $parsedData['author'] ?? null,
                'webhook_delivery_id' => $delivery->id,
                'event_type' => $parsedData['event_type'] ?? null,
            ],
        ]);

        // Update vendor's current version
        $vendor->update([
            'previous_version' => $vendor->current_version,
            'current_version' => $version,
            'last_checked_at' => now(),
        ]);

        Log::info('Uptelligence webhook: New release recorded', [
            'vendor_id' => $vendor->id,
            'vendor_name' => $vendor->name,
            'version' => $version,
            'release_id' => $release->id,
        ]);

        return [
            'action' => 'created',
            'release_id' => $release->id,
            'version' => $version,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Normalise a version string by removing common prefixes.
     */
    protected function normaliseVersion(?string $version): ?string
    {
        if (empty($version)) {
            return null;
        }

        // Remove common prefixes
        $normalised = preg_replace('/^v(?:ersion)?[.\-]?/i', '', $version);

        // Validate it looks like a version number
        if (preg_match('/^\d+\.\d+/', $normalised)) {
            return $normalised;
        }

        // If it doesn't look like a version, return as-is
        return $version;
    }

    /**
     * Check if a tag name looks like a version tag.
     */
    protected function isVersionTag(string $tagName): bool
    {
        // Common version patterns
        return (bool) preg_match('/^v?\d+\.\d+(\.\d+)?/', $tagName);
    }
}
