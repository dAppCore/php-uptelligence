<?php

declare(strict_types=1);

use Core\Mod\Uptelligence\Data\AIAnalysis;
use Core\Mod\Uptelligence\Data\DiffResult;
use Core\Mod\Uptelligence\Models\DiffCache;
use Core\Mod\Uptelligence\Models\Vendor;
use Core\Mod\Uptelligence\Models\VersionRelease;
use Core\Mod\Uptelligence\Services\AIAnalyzerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function makeAnalysisDiff(DiffCache $cache, string $cacheKey = 'analysis-key'): DiffResult
{
    return new DiffResult(
        changedFiles: ['app/Auth/LoginController.php'],
        breakingChanges: ['Review app/Auth/LoginController.php for security compatibility changes'],
        migrationSteps: ['Prioritise security-related changes and add regression coverage around authentication and permissions.'],
        filesChanged: 1,
        additions: 4,
        deletions: 2,
        byFile: [
            [
                'cache_id' => $cache->id,
                'file_path' => 'app/Auth/LoginController.php',
                'change_type' => DiffCache::CHANGE_MODIFIED,
                'category' => DiffCache::CATEGORY_SECURITY,
                'diff_content' => "-        return true;\n+        return \$this->guard->validate();\n",
                'lines_added' => 1,
                'lines_removed' => 1,
            ],
        ],
        metadata: [
            'cache_key' => $cacheKey,
            'version_release_id' => $cache->version_release_id,
            'diff_cache_ids' => [$cache->id],
        ],
    );
}

function makeDiffCache(array $metadata = []): DiffCache
{
    $vendor = Vendor::create([
        'slug' => 'test-vendor',
        'name' => 'Test Vendor',
        'source_type' => Vendor::SOURCE_OSS,
        'is_active' => true,
    ]);

    $release = VersionRelease::create([
        'vendor_id' => $vendor->id,
        'version' => '2.0.0',
        'previous_version' => '1.0.0',
    ]);

    return DiffCache::create([
        'version_release_id' => $release->id,
        'file_path' => 'app/Auth/LoginController.php',
        'change_type' => DiffCache::CHANGE_MODIFIED,
        'category' => DiffCache::CATEGORY_SECURITY,
        'diff_content' => "-        return true;\n+        return \$this->guard->validate();\n",
        'lines_added' => 1,
        'lines_removed' => 1,
        'metadata' => $metadata,
    ]);
}

describe('_Good', function (): void {
    it('calls lthn.ai, parses the analysis DTO, and stores it on DiffCache', function (): void {
        config()->set('upstream.ai.provider', 'lthn.ai');
        config()->set('services.lthn_ai.api_key', 'test-token');

        Http::fake([
            'https://lthn.ai/api/llm/analyse' => Http::response([
                'analysis' => [
                    'severity' => 'critical',
                    'summary' => 'Authentication validation changed and should be ported immediately.',
                    'actionItems' => ['Port the guard validation change.', 'Add login regression coverage.'],
                    'riskLevel' => 'critical',
                    'categories' => ['security', 'breaking'],
                    'findings' => [
                        [
                            'kind' => 'security',
                            'title' => 'Login validation tightened',
                            'description' => 'The upstream release now validates via the configured guard.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $cache = makeDiffCache();
        $analysis = (new AIAnalyzerService)->analyze(makeAnalysisDiff($cache, 'good-key'));

        expect($analysis)->toBeInstanceOf(AIAnalysis::class)
            ->and($analysis->severity)->toBe('critical')
            ->and($analysis->riskLevel)->toBe('critical')
            ->and($analysis->summary)->toContain('Authentication validation')
            ->and($analysis->actionItems)->toHaveCount(2);

        $cache->refresh();
        expect(data_get($cache->metadata, 'analysis_cache_key'))->toBe('good-key')
            ->and(data_get($cache->metadata, 'ai_analysis.summary'))->toContain('Authentication validation');

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://lthn.ai/api/llm/analyse'
            && data_get($request->data(), 'response_format.type') === 'json_object');
    });
});

describe('_Bad', function (): void {
    it('returns cached analysis without sending an HTTP request', function (): void {
        Http::fake();

        $cache = makeDiffCache([
            'analysis_cache_key' => 'cached-key',
            'ai_analysis' => [
                'severity' => 'medium',
                'summary' => 'Cached summary.',
                'action_items' => ['Use cached result.'],
                'risk_level' => 'medium',
                'categories' => ['feature'],
                'findings' => [],
            ],
        ]);

        $analysis = (new AIAnalyzerService)->analyze(makeAnalysisDiff($cache, 'cached-key'));

        expect($analysis->cached)->toBeTrue()
            ->and($analysis->summary)->toBe('Cached summary.');

        Http::assertNothingSent();
    });
});

describe('_Ugly', function (): void {
    it('falls back to deterministic analysis when the AI response is malformed', function (): void {
        config()->set('upstream.ai.provider', 'lthn.ai');
        config()->set('services.lthn_ai.api_key', 'test-token');

        Http::fake([
            'https://lthn.ai/api/llm/analyse' => Http::response('not-json', 200),
        ]);

        $cache = makeDiffCache();
        $analysis = (new AIAnalyzerService)->analyze(makeAnalysisDiff($cache, 'ugly-key'));

        expect($analysis->severity)->toBe('critical')
            ->and($analysis->metadata['provider'])->toBe('heuristic')
            ->and($analysis->metadata['fallback_reason'])->toBe('AI response was not valid JSON')
            ->and($analysis->actionItems)->not->toBeEmpty();

        $cache->refresh();
        expect(data_get($cache->metadata, 'ai_analysis.metadata.provider'))->toBe('heuristic');
    });
});
