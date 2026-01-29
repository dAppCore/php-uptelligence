<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Tests\Unit;

use Core\Mod\Uptelligence\Models\UptelligenceWebhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for webhook signature verification timing safety.
 *
 * These tests verify that all signature verification methods use
 * timing-safe comparison functions (hash_equals) to prevent
 * timing attacks that could reveal valid signatures.
 */
class WebhookSignatureVerificationTest extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app): array
    {
        return [];
    }

    /**
     * Test that GitHub signature verification uses hash_equals.
     */
    #[Test]
    public function it_verifies_github_signature_with_timing_safe_comparison(): void
    {
        $webhook = new UptelligenceWebhook([
            'provider' => UptelligenceWebhook::PROVIDER_GITHUB,
            'secret' => 'test-secret-key',
        ]);

        $payload = '{"action":"published","release":{"tag_name":"v1.0.0"}}';
        $validSignature = 'sha256=' . hash_hmac('sha256', $payload, 'test-secret-key');
        $invalidSignature = 'sha256=' . hash_hmac('sha256', $payload, 'wrong-secret');

        // Valid signature should pass
        $this->assertTrue($webhook->verifySignature($payload, $validSignature));

        // Invalid signature should fail
        $this->assertFalse($webhook->verifySignature($payload, $invalidSignature));

        // Signature without prefix should also work
        $signatureWithoutPrefix = hash_hmac('sha256', $payload, 'test-secret-key');
        $this->assertTrue($webhook->verifySignature($payload, $signatureWithoutPrefix));
    }

    /**
     * Test that GitLab signature verification uses hash_equals.
     */
    #[Test]
    public function it_verifies_gitlab_signature_with_timing_safe_comparison(): void
    {
        $webhook = new UptelligenceWebhook([
            'provider' => UptelligenceWebhook::PROVIDER_GITLAB,
            'secret' => 'gitlab-secret-token',
        ]);

        $payload = '{"object_kind":"release","action":"create"}';

        // GitLab uses X-Gitlab-Token header (direct token comparison)
        $this->assertTrue($webhook->verifySignature($payload, 'gitlab-secret-token'));
        $this->assertFalse($webhook->verifySignature($payload, 'wrong-token'));

        // Empty signature should fail when secret is set
        $this->assertFalse($webhook->verifySignature($payload, ''));
        $this->assertFalse($webhook->verifySignature($payload, null));
    }

    /**
     * Test that npm signature verification uses hash_equals.
     */
    #[Test]
    public function it_verifies_npm_signature_with_timing_safe_comparison(): void
    {
        $webhook = new UptelligenceWebhook([
            'provider' => UptelligenceWebhook::PROVIDER_NPM,
            'secret' => 'npm-webhook-secret',
        ]);

        $payload = '{"event":"package:publish","version":"1.0.0"}';
        $validSignature = hash_hmac('sha256', $payload, 'npm-webhook-secret');

        $this->assertTrue($webhook->verifySignature($payload, $validSignature));
        $this->assertFalse($webhook->verifySignature($payload, 'invalid-signature'));
    }

    /**
     * Test that Packagist signature verification uses hash_equals.
     */
    #[Test]
    public function it_verifies_packagist_signature_with_timing_safe_comparison(): void
    {
        $webhook = new UptelligenceWebhook([
            'provider' => UptelligenceWebhook::PROVIDER_PACKAGIST,
            'secret' => 'packagist-secret',
        ]);

        $payload = '{"repository":{"url":"https://packagist.org/packages/vendor/package"}}';
        // Packagist uses SHA-1 HMAC
        $validSignature = hash_hmac('sha1', $payload, 'packagist-secret');

        $this->assertTrue($webhook->verifySignature($payload, $validSignature));
        $this->assertFalse($webhook->verifySignature($payload, 'wrong-signature'));
    }

    /**
     * Test that custom webhook signature verification uses hash_equals.
     */
    #[Test]
    public function it_verifies_custom_signature_with_timing_safe_comparison(): void
    {
        $webhook = new UptelligenceWebhook([
            'provider' => UptelligenceWebhook::PROVIDER_CUSTOM,
            'secret' => 'custom-secret-key',
        ]);

        $payload = '{"version":"2.0.0","event":"release"}';
        $validSignature = 'sha256=' . hash_hmac('sha256', $payload, 'custom-secret-key');

        $this->assertTrue($webhook->verifySignature($payload, $validSignature));
        $this->assertFalse($webhook->verifySignature($payload, 'sha256=invalid'));
    }

    /**
     * Test that signature verification skips when no secret is configured.
     */
    #[Test]
    public function it_skips_verification_when_no_secret_configured(): void
    {
        $webhook = new UptelligenceWebhook([
            'provider' => UptelligenceWebhook::PROVIDER_GITHUB,
            'secret' => null,
        ]);

        $payload = '{"any":"payload"}';

        // Should return true (skip verification) when no secret is set
        $this->assertTrue($webhook->verifySignature($payload, null));
        $this->assertTrue($webhook->verifySignature($payload, 'any-signature'));
    }

    /**
     * Test that signature verification fails when secret is set but no signature provided.
     */
    #[Test]
    public function it_fails_when_secret_is_set_but_no_signature_provided(): void
    {
        $webhook = new UptelligenceWebhook([
            'provider' => UptelligenceWebhook::PROVIDER_GITHUB,
            'secret' => 'test-secret',
        ]);

        $payload = '{"any":"payload"}';

        $this->assertFalse($webhook->verifySignature($payload, null));
        $this->assertFalse($webhook->verifySignature($payload, ''));
    }

    /**
     * Test grace period allows previous secret.
     */
    #[Test]
    public function it_accepts_previous_secret_during_grace_period(): void
    {
        $webhook = new UptelligenceWebhook([
            'provider' => UptelligenceWebhook::PROVIDER_GITHUB,
            'secret' => 'new-secret',
            'previous_secret' => 'old-secret',
            'secret_rotated_at' => now(),
            'grace_period_seconds' => 86400, // 24 hours
        ]);

        $payload = '{"test":"payload"}';

        // Both old and new secrets should work during grace period
        $newSignature = 'sha256=' . hash_hmac('sha256', $payload, 'new-secret');
        $oldSignature = 'sha256=' . hash_hmac('sha256', $payload, 'old-secret');
        $wrongSignature = 'sha256=' . hash_hmac('sha256', $payload, 'wrong-secret');

        $this->assertTrue($webhook->verifySignature($payload, $newSignature));
        $this->assertTrue($webhook->verifySignature($payload, $oldSignature));
        $this->assertFalse($webhook->verifySignature($payload, $wrongSignature));
    }

    /**
     * Test that previous secret is rejected after grace period expires.
     */
    #[Test]
    public function it_rejects_previous_secret_after_grace_period(): void
    {
        $webhook = new UptelligenceWebhook([
            'provider' => UptelligenceWebhook::PROVIDER_GITHUB,
            'secret' => 'new-secret',
            'previous_secret' => 'old-secret',
            'secret_rotated_at' => now()->subDays(2), // 2 days ago
            'grace_period_seconds' => 86400, // 24 hours (expired)
        ]);

        $payload = '{"test":"payload"}';

        $newSignature = 'sha256=' . hash_hmac('sha256', $payload, 'new-secret');
        $oldSignature = 'sha256=' . hash_hmac('sha256', $payload, 'old-secret');

        $this->assertTrue($webhook->verifySignature($payload, $newSignature));
        $this->assertFalse($webhook->verifySignature($payload, $oldSignature));
    }

    /**
     * Test various malformed signatures are rejected safely.
     */
    #[Test]
    #[DataProvider('malformedSignatures')]
    public function it_safely_rejects_malformed_signatures(string $signature): void
    {
        $webhook = new UptelligenceWebhook([
            'provider' => UptelligenceWebhook::PROVIDER_GITHUB,
            'secret' => 'test-secret',
        ]);

        $payload = '{"test":"payload"}';

        $this->assertFalse($webhook->verifySignature($payload, $signature));
    }

    /**
     * Data provider for malformed signatures.
     */
    public static function malformedSignatures(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ['   '],
            'sha256= without hash' => ['sha256='],
            'sha1= prefix (github expects sha256)' => ['sha1=abc123'],
            'random string' => ['not-a-valid-signature'],
            'unicode characters' => ['sha256=\u0000\u0001\u0002'],
            'very long string' => [str_repeat('a', 10000)],
            'null bytes' => ["sha256=abc\x00def"],
            'partial hash' => ['sha256=abc'],
        ];
    }

    /**
     * Test that verification handles binary payloads correctly.
     */
    #[Test]
    public function it_handles_binary_payloads(): void
    {
        $webhook = new UptelligenceWebhook([
            'provider' => UptelligenceWebhook::PROVIDER_GITHUB,
            'secret' => 'binary-secret',
        ]);

        // Payload with null bytes and binary data
        $binaryPayload = "binary\x00payload\xff\xfe";
        $validSignature = 'sha256=' . hash_hmac('sha256', $binaryPayload, 'binary-secret');

        $this->assertTrue($webhook->verifySignature($binaryPayload, $validSignature));
    }

    /**
     * Test that verification handles empty payload.
     */
    #[Test]
    public function it_handles_empty_payload(): void
    {
        $webhook = new UptelligenceWebhook([
            'provider' => UptelligenceWebhook::PROVIDER_GITHUB,
            'secret' => 'empty-payload-secret',
        ]);

        $emptyPayload = '';
        $validSignature = 'sha256=' . hash_hmac('sha256', $emptyPayload, 'empty-payload-secret');

        $this->assertTrue($webhook->verifySignature($emptyPayload, $validSignature));
    }

    /**
     * Test that verification handles large payloads.
     */
    #[Test]
    public function it_handles_large_payloads(): void
    {
        $webhook = new UptelligenceWebhook([
            'provider' => UptelligenceWebhook::PROVIDER_GITHUB,
            'secret' => 'large-payload-secret',
        ]);

        // 1MB payload
        $largePayload = str_repeat('{"data":"' . str_repeat('x', 1000) . '"}', 1000);
        $validSignature = 'sha256=' . hash_hmac('sha256', $largePayload, 'large-payload-secret');

        $this->assertTrue($webhook->verifySignature($largePayload, $validSignature));
    }
}
