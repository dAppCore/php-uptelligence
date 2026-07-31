<?php

// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Services;

use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Reading the current version of something out of a package registry.
 *
 * The checker could reach GitHub and Gitea, so anything published to Packagist,
 * npm or the Go proxy — which is most of what an application actually depends
 * on — fell through to "unsupported source type" and was never checked at all.
 * A vendor whose only version signal is their own download page could not be
 * tracked either, and that is exactly the sort of dependency nobody notices has
 * gone stale.
 *
 * Each resolver returns a bare version string or null. Deciding whether that
 * is newer, and what to do about it, stays with the checker — this only knows
 * how to ask.
 */
class RegistryVersionResolver
{
    private const TIMEOUT = 20;

    /**
     * The latest stable release of a Composer package.
     *
     * Skips branch aliases and anything with a stability suffix: dev-main is
     * not a release, and reporting it as one would show every consumer as
     * permanently out of date.
     */
    public function packagist(string $package): ?string
    {
        $response = Http::timeout(self::TIMEOUT)
            ->acceptJson()
            ->get("https://repo.packagist.org/p2/{$package}.json");

        if (! $response->successful()) {
            return null;
        }

        $versions = (array) $response->json("packages.{$package}", []);
        $stable = [];

        foreach ($versions as $release) {
            $version = (string) ($release['version'] ?? '');

            if ($version === '' || str_starts_with($version, 'dev-')) {
                continue;
            }

            if (preg_match('/-(alpha|beta|rc|dev)/i', $version) === 1) {
                continue;
            }

            $stable[] = $version;
        }

        return $this->highest($stable);
    }

    /**
     * The version npm serves as `latest`.
     *
     * The dist-tag rather than the highest number, because a package that
     * publishes a patch to an old major line does not make that the current
     * version, and npm already knows which one it means.
     */
    public function npm(string $package): ?string
    {
        $response = Http::timeout(self::TIMEOUT)
            ->acceptJson()
            ->get('https://registry.npmjs.org/'.rawurlencode($package));

        if (! $response->successful()) {
            return null;
        }

        $latest = $response->json('dist-tags.latest');

        return is_string($latest) && $latest !== '' ? $latest : null;
    }

    /**
     * The latest version of a Go module, from the module proxy.
     *
     * The proxy requires the module path be case-encoded — an uppercase letter
     * becomes !lowercase — because module paths are case-sensitive and the
     * filesystems it is served from are not.
     */
    public function goModule(string $module): ?string
    {
        $response = Http::timeout(self::TIMEOUT)
            ->acceptJson()
            ->get('https://proxy.golang.org/'.$this->encodeGoModulePath($module).'/@latest');

        if (! $response->successful()) {
            return null;
        }

        $version = $response->json('Version');

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * A version from any JSON endpoint, at a dot path.
     *
     * The escape hatch for a vendor with an API nobody else has: point it at
     * the URL and say where in the response the version lives.
     *
     * @param  array<string, string>  $headers
     */
    public function jsonApi(string $url, string $path = 'version', array $headers = []): ?string
    {
        $response = Http::timeout(self::TIMEOUT)->acceptJson()->withHeaders($headers)->get($url);

        if (! $response->successful()) {
            return null;
        }

        $value = $response->json($path);

        return is_scalar($value) && (string) $value !== '' ? $this->normalise((string) $value) : null;
    }

    /**
     * A version read off a web page.
     *
     * The last resort, and the reason it exists: plenty of software announces
     * releases only on its own download page. A selector may be CSS or a
     * regular expression — a regex is recognised by its delimiters, since a CSS
     * selector never starts and ends with the same punctuation.
     *
     * @param  array<string, string>  $headers
     */
    public function scrape(string $url, string $selector, array $headers = []): ?string
    {
        $response = Http::timeout(self::TIMEOUT)->withHeaders($headers)->get($url);

        if (! $response->successful()) {
            return null;
        }

        $html = $response->body();

        return $this->looksLikeRegex($selector)
            ? $this->fromRegex($html, $selector)
            : $this->fromCss($html, $selector);
    }

    /**
     * @param  list<string>  $versions
     */
    private function highest(array $versions): ?string
    {
        if ($versions === []) {
            return null;
        }

        usort($versions, static fn (string $a, string $b): int => version_compare(
            ltrim($a, 'vV'),
            ltrim($b, 'vV'),
        ));

        return end($versions) ?: null;
    }

    private function encodeGoModulePath(string $module): string
    {
        return (string) preg_replace_callback(
            '/[A-Z]/',
            static fn (array $m): string => '!'.strtolower($m[0]),
            trim($module, '/'),
        );
    }

    private function looksLikeRegex(string $selector): bool
    {
        if (strlen($selector) < 3) {
            return false;
        }

        $delimiter = $selector[0];

        return in_array($delimiter, ['/', '#', '~', '%'], true)
            && str_contains(substr($selector, 1), $delimiter);
    }

    private function fromRegex(string $html, string $pattern): ?string
    {
        if (@preg_match($pattern, $html, $matches) !== 1) {
            return null;
        }

        // The first capture group if there is one, otherwise the whole match:
        // a pattern written to find a version usually captures it.
        return $this->normalise((string) ($matches[1] ?? $matches[0]));
    }

    private function fromCss(string $html, string $selector): ?string
    {
        try {
            $node = (new Crawler($html))->filter($selector);
        } catch (\Throwable) {
            return null;
        }

        return $node->count() === 0 ? null : $this->normalise($node->first()->text(''));
    }

    /**
     * Pull a version out of whatever text it was wrapped in.
     *
     * A download page says "Version 4.2.1 — released today", not "4.2.1".
     */
    private function normalise(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/v?\d+(?:\.\d+)+(?:[-+][0-9A-Za-z.\-]+)?/', $value, $matches) === 1) {
            return $matches[0];
        }

        return $value !== '' ? $value : null;
    }
}
