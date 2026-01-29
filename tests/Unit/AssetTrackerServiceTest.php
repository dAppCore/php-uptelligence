<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Tests\Unit;

use Core\Mod\Uptelligence\Models\Asset;
use Core\Mod\Uptelligence\Services\AssetTrackerService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for AssetTrackerService shell injection prevention.
 *
 * These tests verify that malicious package names are rejected
 * and do not result in shell command execution.
 */
class AssetTrackerServiceTest extends \Orchestra\Testbench\TestCase
{
    protected AssetTrackerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AssetTrackerService;
    }

    protected function getPackageProviders($app): array
    {
        return [];
    }

    /**
     * Test that valid Composer package names are accepted.
     */
    #[Test]
    #[DataProvider('validComposerPackageNames')]
    public function it_accepts_valid_composer_package_names(string $packageName): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validatePackageName');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $packageName, Asset::TYPE_COMPOSER);

        $this->assertEquals($packageName, $result);
    }

    /**
     * Test that valid NPM package names are accepted.
     */
    #[Test]
    #[DataProvider('validNpmPackageNames')]
    public function it_accepts_valid_npm_package_names(string $packageName): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validatePackageName');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $packageName, Asset::TYPE_NPM);

        $this->assertEquals($packageName, $result);
    }

    /**
     * Test that shell injection attempts in Composer package names are rejected.
     */
    #[Test]
    #[DataProvider('shellInjectionAttempts')]
    public function it_rejects_shell_injection_in_composer_package_names(string $maliciousInput): void
    {
        Log::shouldReceive('warning')->once();

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validatePackageName');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid package name format');

        $method->invoke($this->service, $maliciousInput, Asset::TYPE_COMPOSER);
    }

    /**
     * Test that shell injection attempts in NPM package names are rejected.
     */
    #[Test]
    #[DataProvider('shellInjectionAttempts')]
    public function it_rejects_shell_injection_in_npm_package_names(string $maliciousInput): void
    {
        Log::shouldReceive('warning')->once();

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validatePackageName');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid package name format');

        $method->invoke($this->service, $maliciousInput, Asset::TYPE_NPM);
    }

    /**
     * Test that updateComposerPackage returns error for invalid package name.
     */
    #[Test]
    public function it_returns_error_for_invalid_composer_package_in_update(): void
    {
        Log::shouldReceive('warning')->once();

        $asset = new Asset([
            'package_name' => 'vendor/package; rm -rf /',
            'type' => Asset::TYPE_COMPOSER,
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('updateComposerPackage');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $asset);

        $this->assertEquals('error', $result['status']);
        $this->assertEquals('Invalid package name format', $result['message']);
    }

    /**
     * Test that updateNpmPackage returns error for invalid package name.
     */
    #[Test]
    public function it_returns_error_for_invalid_npm_package_in_update(): void
    {
        Log::shouldReceive('warning')->once();

        $asset = new Asset([
            'package_name' => 'package`whoami`',
            'type' => Asset::TYPE_NPM,
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('updateNpmPackage');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $asset);

        $this->assertEquals('error', $result['status']);
        $this->assertEquals('Invalid package name format', $result['message']);
    }

    /**
     * Test that checkCustomComposerRegistry returns error for invalid package name.
     */
    #[Test]
    public function it_returns_error_for_invalid_package_in_custom_registry_check(): void
    {
        Log::shouldReceive('warning')->once();

        $asset = new Asset([
            'package_name' => '$(cat /etc/passwd)',
            'type' => Asset::TYPE_COMPOSER,
            'registry_url' => 'https://custom.registry.com',
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('checkCustomComposerRegistry');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $asset);

        $this->assertEquals('error', $result['status']);
        $this->assertEquals('Invalid package name format', $result['message']);
    }

    /**
     * Test that Process::run is called with array syntax for valid packages.
     */
    #[Test]
    public function it_uses_array_syntax_for_process_run(): void
    {
        Process::fake([
            '*' => Process::result(output: '{"versions":["1.0.0"]}'),
        ]);

        $asset = new Asset([
            'package_name' => 'vendor/package',
            'type' => Asset::TYPE_COMPOSER,
            'registry_url' => 'https://custom.registry.com',
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('checkCustomComposerRegistry');
        $method->setAccessible(true);

        $method->invoke($this->service, $asset);

        // Verify array syntax was used (not string interpolation)
        Process::assertRan(function ($process) {
            // The command should be an array, not a string with interpolation
            return is_array($process->command) &&
                $process->command === ['composer', 'show', 'vendor/package', '--format=json'];
        });
    }

    /**
     * Data provider for valid Composer package names.
     */
    public static function validComposerPackageNames(): array
    {
        return [
            'simple package' => ['vendor/package'],
            'with hyphen' => ['my-vendor/my-package'],
            'with underscore' => ['my_vendor/my_package'],
            'with dots' => ['vendor.name/package.name'],
            'with numbers' => ['vendor123/package456'],
            'laravel package' => ['laravel/framework'],
            'symfony component' => ['symfony/console'],
            'complex name' => ['my-vendor123/complex_package.name'],
            'livewire flux' => ['livewire/flux-pro'],
        ];
    }

    /**
     * Data provider for valid NPM package names.
     */
    public static function validNpmPackageNames(): array
    {
        return [
            'simple package' => ['lodash'],
            'with hyphen' => ['my-package'],
            'with underscore' => ['my_package'],
            'with dot' => ['package.js'],
            'scoped package' => ['@scope/package'],
            'scoped with hyphen' => ['@my-scope/my-package'],
            'scoped complex' => ['@angular/core'],
            'alpinejs' => ['alpinejs'],
            'tailwindcss' => ['tailwindcss'],
            'vue' => ['vue'],
        ];
    }

    /**
     * Data provider for shell injection attempts.
     */
    public static function shellInjectionAttempts(): array
    {
        return [
            'command substitution with backticks' => ['package`whoami`'],
            'command substitution with $()' => ['$(cat /etc/passwd)'],
            'semicolon injection' => ['vendor/package; rm -rf /'],
            'pipe injection' => ['vendor/package | cat /etc/passwd'],
            'ampersand injection' => ['vendor/package && rm -rf /'],
            'or injection' => ['vendor/package || rm -rf /'],
            'newline injection' => ["vendor/package\nrm -rf /"],
            'redirect injection' => ['vendor/package > /tmp/pwned'],
            'redirect input injection' => ['vendor/package < /etc/passwd'],
            'single quote escape' => ["vendor/package'; rm -rf /'"],
            'double quote escape' => ['vendor/package"; rm -rf /"'],
            'space injection' => ['vendor/package rm -rf /'],
            'glob injection' => ['vendor/*'],
            'question mark glob' => ['vendor/pack?ge'],
            'bracket glob' => ['vendor/pack[a]ge'],
            'curly brace expansion' => ['vendor/{a,b}'],
            'tilde expansion' => ['~/../../etc/passwd'],
            'null byte injection' => ["vendor/package\x00rm"],
            'env variable injection' => ['$HOME/package'],
            'backtick in scoped npm' => ['@scope`whoami`/package'],
        ];
    }
}
