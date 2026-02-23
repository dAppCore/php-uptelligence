<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Tests\Unit;

use Core\Mod\Uptelligence\Controllers\Api\WebhookController;
use Core\Mod\Uptelligence\Services\WebhookReceiverService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Tests for webhook payload validation.
 *
 * These tests verify that the WebhookController properly validates
 * incoming payloads for size, structure, and depth limits to prevent
 * denial of service attacks.
 */
class WebhookPayloadValidationTest extends \Orchestra\Testbench\TestCase
{
    protected WebhookController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $service = $this->createMock(WebhookReceiverService::class);
        $this->controller = new WebhookController($service);
    }

    protected function getPackageProviders($app): array
    {
        return [];
    }

    /**
     * Helper to invoke protected methods.
     */
    protected function invokeMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    // -------------------------------------------------------------------------
    // Payload Size Validation Tests
    // -------------------------------------------------------------------------

    /**
     * Test that normal-sized payloads are accepted.
     */
    #[Test]
    public function it_accepts_normal_sized_payloads(): void
    {
        $payload = json_encode(['event' => 'release', 'data' => str_repeat('x', 1000)]);

        $result = $this->invokeMethod($this->controller, 'validatePayloadSize', [$payload, 1]);

        $this->assertNull($result);
    }

    /**
     * Test that oversized payloads are rejected.
     */
    #[Test]
    public function it_rejects_oversized_payloads(): void
    {
        // Create a payload larger than 1MB
        $payload = str_repeat('x', 1048577);

        $result = $this->invokeMethod($this->controller, 'validatePayloadSize', [$payload, 1]);

        $this->assertNotNull($result);
        $this->assertEquals(413, $result->getStatusCode());
    }

    /**
     * Test that empty payloads are rejected.
     */
    #[Test]
    public function it_rejects_empty_payloads(): void
    {
        $result = $this->invokeMethod($this->controller, 'validatePayloadSize', ['', 1]);

        $this->assertNotNull($result);
        $this->assertEquals(400, $result->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // JSON Parsing Tests
    // -------------------------------------------------------------------------

    /**
     * Test that valid JSON is parsed correctly.
     */
    #[Test]
    public function it_parses_valid_json(): void
    {
        $payload = '{"event":"release","version":"1.0.0"}';

        $result = $this->invokeMethod($this->controller, 'parseAndValidateJson', [$payload, 1]);

        $this->assertIsArray($result);
        $this->assertEquals('release', $result['event']);
        $this->assertEquals('1.0.0', $result['version']);
    }

    /**
     * Test that invalid JSON is rejected.
     */
    #[Test]
    public function it_rejects_invalid_json(): void
    {
        $payload = '{invalid json}';

        $result = $this->invokeMethod($this->controller, 'parseAndValidateJson', [$payload, 1]);

        $this->assertNull($result);
    }

    /**
     * Test that deeply nested JSON is rejected.
     */
    #[Test]
    public function it_rejects_deeply_nested_json(): void
    {
        // Create JSON with 35 levels of nesting (exceeds max depth of 32)
        $nested = '"value"';
        for ($i = 0; $i < 35; $i++) {
            $nested = '{"level'.$i.'":'.$nested.'}';
        }

        $result = $this->invokeMethod($this->controller, 'parseAndValidateJson', [$nested, 1]);

        $this->assertNull($result);
    }

    /**
     * Test that scalar JSON values are rejected.
     */
    #[Test]
    #[DataProvider('scalarJsonValues')]
    public function it_rejects_scalar_json_values(string $json): void
    {
        $result = $this->invokeMethod($this->controller, 'parseAndValidateJson', [$json, 1]);

        $this->assertNull($result);
    }

    /**
     * Data provider for scalar JSON values.
     */
    public static function scalarJsonValues(): array
    {
        return [
            'string' => ['"just a string"'],
            'number' => ['12345'],
            'boolean true' => ['true'],
            'boolean false' => ['false'],
            'null' => ['null'],
        ];
    }

    // -------------------------------------------------------------------------
    // Payload Structure Validation Tests
    // -------------------------------------------------------------------------

    /**
     * Test that valid GitHub payloads are accepted.
     */
    #[Test]
    public function it_accepts_valid_github_payload(): void
    {
        $data = [
            'action' => 'published',
            'release' => [
                'tag_name' => 'v1.0.0',
                'name' => 'Version 1.0.0',
            ],
        ];

        $result = $this->invokeMethod($this->controller, 'validateGitHubPayload', [$data]);

        $this->assertTrue($result);
    }

    /**
     * Test that GitHub payloads with invalid release field are rejected.
     */
    #[Test]
    public function it_rejects_github_payload_with_invalid_release(): void
    {
        $data = [
            'action' => 'published',
            'release' => 'not an array',
        ];

        $result = $this->invokeMethod($this->controller, 'validateGitHubPayload', [$data]);

        $this->assertIsString($result);
        $this->assertStringContainsString('release must be an object', $result);
    }

    /**
     * Test that valid GitLab payloads are accepted.
     */
    #[Test]
    public function it_accepts_valid_gitlab_payload(): void
    {
        $data = [
            'object_kind' => 'release',
            'action' => 'create',
            'tag' => 'v1.0.0',
        ];

        $result = $this->invokeMethod($this->controller, 'validateGitLabPayload', [$data]);

        $this->assertTrue($result);
    }

    /**
     * Test that valid npm payloads are accepted.
     */
    #[Test]
    public function it_accepts_valid_npm_payload(): void
    {
        $data = [
            'event' => 'package:publish',
            'name' => 'my-package',
            'version' => '1.0.0',
        ];

        $result = $this->invokeMethod($this->controller, 'validateNpmPayload', [$data]);

        $this->assertTrue($result);
    }

    /**
     * Test that valid Packagist payloads are accepted.
     */
    #[Test]
    public function it_accepts_valid_packagist_payload(): void
    {
        $data = [
            'repository' => [
                'url' => 'https://packagist.org/packages/vendor/package',
            ],
            'versions' => [
                '1.0.0' => ['version' => '1.0.0'],
            ],
        ];

        $result = $this->invokeMethod($this->controller, 'validatePackagistPayload', [$data]);

        $this->assertTrue($result);
    }

    // -------------------------------------------------------------------------
    // Excessive Array Size Tests
    // -------------------------------------------------------------------------

    /**
     * Test that normal array sizes are accepted.
     */
    #[Test]
    public function it_accepts_normal_array_sizes(): void
    {
        $data = [
            'items' => array_fill(0, 100, 'item'),
        ];

        $result = $this->invokeMethod($this->controller, 'hasExcessiveArraySize', [$data]);

        $this->assertFalse($result);
    }

    /**
     * Test that excessive array sizes are detected.
     */
    #[Test]
    public function it_detects_excessive_array_sizes(): void
    {
        $data = [
            'items' => array_fill(0, 2000, 'item'),
        ];

        $result = $this->invokeMethod($this->controller, 'hasExcessiveArraySize', [$data]);

        $this->assertTrue($result);
    }

    /**
     * Test that deeply nested arrays with many elements are detected.
     */
    #[Test]
    public function it_detects_excessive_nested_array_sizes(): void
    {
        $data = [
            'level1' => [
                'level2' => [
                    'items' => array_fill(0, 1500, 'item'),
                ],
            ],
        ];

        $result = $this->invokeMethod($this->controller, 'hasExcessiveArraySize', [$data]);

        $this->assertTrue($result);
    }

    /**
     * Test that payloads with excessive arrays are rejected.
     */
    #[Test]
    public function it_rejects_payload_with_excessive_arrays(): void
    {
        $data = [
            'commits' => array_fill(0, 2000, ['id' => 'abc']),
        ];

        $result = $this->invokeMethod($this->controller, 'validateGitHubPayload', [$data]);

        $this->assertIsString($result);
        $this->assertStringContainsString('excessively large arrays', $result);
    }
}
