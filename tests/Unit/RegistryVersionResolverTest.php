<?php

// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

use Core\Mod\Uptelligence\Services\RegistryVersionResolver;
use Illuminate\Support\Facades\Http;

function resolver(): RegistryVersionResolver
{
    return new RegistryVersionResolver();
}

// ─── Packagist ───────────────────────────────────────────────────────────

it('reads the highest stable version from Packagist', function () {
    Http::fake(['repo.packagist.org/*' => Http::response([
        'packages' => ['dappcore/php' => [
            ['version' => 'v0.5.0'],
            ['version' => 'v0.4.0'],
            ['version' => 'v0.10.0'],
        ]],
    ])]);

    // Sorted by version, not by the order the registry happened to return:
    // 0.10.0 is newer than 0.5.0 and a string sort would disagree.
    expect(resolver()->packagist('dappcore/php'))->toBe('v0.10.0');
});

it('ignores branch aliases and pre-releases on Packagist', function () {
    Http::fake(['repo.packagist.org/*' => Http::response([
        'packages' => ['acme/thing' => [
            ['version' => 'dev-main'],
            ['version' => '2.0.0-beta1'],
            ['version' => '1.9.0'],
        ]],
    ])]);

    // dev-main is not a release, and calling it one would show every consumer
    // as permanently out of date.
    expect(resolver()->packagist('acme/thing'))->toBe('1.9.0');
});

it('returns nothing when Packagist has no stable release', function () {
    Http::fake(['repo.packagist.org/*' => Http::response([
        'packages' => ['acme/thing' => [['version' => 'dev-main']]],
    ])]);

    expect(resolver()->packagist('acme/thing'))->toBeNull();
});

// ─── npm ─────────────────────────────────────────────────────────────────

it('takes npm\'s own latest tag rather than the highest number', function () {
    Http::fake(['registry.npmjs.org/*' => Http::response([
        'dist-tags' => ['latest' => '3.1.0', 'next' => '4.0.0-rc1'],
    ])]);

    // A patch published to an old major line does not become the current
    // version, and npm already knows which one it means.
    expect(resolver()->npm('lodash'))->toBe('3.1.0');
});

it('handles a scoped npm package name', function () {
    Http::fake(['registry.npmjs.org/*' => Http::response(['dist-tags' => ['latest' => '1.0.0']])]);

    expect(resolver()->npm('@scope/package'))->toBe('1.0.0');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '%40scope%2Fpackage'));
});

// ─── Go ──────────────────────────────────────────────────────────────────

it('reads a Go module version from the proxy', function () {
    Http::fake(['proxy.golang.org/*' => Http::response(['Version' => 'v0.13.1'])]);

    expect(resolver()->goModule('dappco.re/go/log'))->toBe('v0.13.1');
});

it('case-encodes an uppercase module path for the Go proxy', function () {
    Http::fake(['proxy.golang.org/*' => Http::response(['Version' => 'v1.0.0'])]);

    resolver()->goModule('github.com/dAppCore/agent');

    // Module paths are case-sensitive and the filesystems the proxy serves
    // from are not, so an uppercase letter has to become !lowercase.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'd!app!core'));
});

// ─── A JSON API ──────────────────────────────────────────────────────────

it('reads a version from any JSON endpoint at a dot path', function () {
    Http::fake(['vendor.example/*' => Http::response(['data' => ['release' => ['tag' => '7.4.2']]])]);

    expect(resolver()->jsonApi('https://vendor.example/api', 'data.release.tag'))->toBe('7.4.2');
});

it('returns nothing when the path is not in the response', function () {
    Http::fake(['vendor.example/*' => Http::response(['data' => []])]);

    expect(resolver()->jsonApi('https://vendor.example/api', 'data.release.tag'))->toBeNull();
});

// ─── Scraping ────────────────────────────────────────────────────────────

it('reads a version off a page with a CSS selector', function () {
    Http::fake(['vendor.example/*' => Http::response(
        '<html><body><span class="version">Version 4.2.1 — released today</span></body></html>'
    )]);

    // A download page says "Version 4.2.1 — released today", not "4.2.1".
    expect(resolver()->scrape('https://vendor.example/download', '.version'))->toBe('4.2.1');
});

it('reads a version off a page with a regular expression', function () {
    Http::fake(['vendor.example/*' => Http::response('<p>Latest build: 10.3.9-rc2</p>')]);

    expect(resolver()->scrape('https://vendor.example/download', '/Latest build: ([\d.]+-rc\d+)/'))
        ->toBe('10.3.9-rc2');
});

it('tells a regular expression from a CSS selector', function () {
    Http::fake(['vendor.example/*' => Http::response('<div id="ver">2.0.0</div>')]);

    // A CSS selector never starts and ends with the same punctuation, which is
    // what makes the two distinguishable without asking.
    expect(resolver()->scrape('https://vendor.example/', '#ver'))->toBe('2.0.0');
});

it('returns nothing when the selector matches nothing', function () {
    Http::fake(['vendor.example/*' => Http::response('<html><body>nothing here</body></html>')]);

    expect(resolver()->scrape('https://vendor.example/', '.version'))->toBeNull();
});

it('returns nothing when the page cannot be fetched', function () {
    Http::fake(['vendor.example/*' => Http::response('', 503)]);

    expect(resolver()->scrape('https://vendor.example/', '.version'))->toBeNull()
        ->and(resolver()->packagist('acme/thing'))->toBeNull();
});
