<?php

declare(strict_types=1);

use Core\Mod\Uptelligence\Data\UpstreamTodo;
use Core\Mod\Uptelligence\Models\Asset;
use Core\Mod\Uptelligence\Services\IssueGeneratorService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

describe('_Good', function (): void {
    it('creates a Mantis issue for a breaking analysis finding', function (): void {
        config()->set('upstream.issue_platform', 'mantis');
        config()->set('upstream.mantis.url', 'https://mantis.test');
        config()->set('upstream.mantis.token', 'mantis-token');
        config()->set('upstream.mantis.project_id', 862);

        Http::fake([
            'https://mantis.test/api/rest/issues*' => Http::sequence()
                ->push(['issues' => []], 200)
                ->push(['issue' => ['id' => 862]], 201),
        ]);

        $asset = new Asset([
            'slug' => 'laravel-framework',
            'name' => 'Laravel Framework',
            'type' => Asset::TYPE_COMPOSER,
            'package_name' => 'laravel/framework',
            'installed_version' => '11.0.0',
            'latest_version' => '12.0.0',
        ]);

        $todos = (new IssueGeneratorService)->generate($asset, [
            'from_version' => '11.0.0',
            'to_version' => '12.0.0',
            'findings' => [
                [
                    'kind' => 'breaking',
                    'title' => 'Update middleware registration contract',
                    'description' => 'The bootstrap API now expects middleware aliases to be registered differently.',
                    'priority' => 'high',
                    'estimated_effort_hours' => 3,
                    'suggested_solution' => ['steps' => ['Move aliases into the new middleware configurator.']],
                ],
            ],
        ]);

        expect($todos)->toHaveCount(1)
            ->and($todos->first())->toBeInstanceOf(UpstreamTodo::class)
            ->and($todos->first()->issuePlatform)->toBe('mantis')
            ->and($todos->first()->issueStatus)->toBe('created')
            ->and($todos->first()->issueUrl)->toBe('https://mantis.test/view.php?id=862')
            ->and($todos->first()->priority)->toBe('high')
            ->and($todos->first()->dedupeKey)->toContain('asset:laravel-framework:kind:breaking');

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://mantis.test/api/rest/issues'
            && data_get($request->data(), 'summary') === 'Update middleware registration contract'
            && str_contains((string) data_get($request->data(), 'additional_information'), 'dedupe_key='));
    });
});

describe('_Bad', function (): void {
    it('does not create todos for non-breaking findings', function (): void {
        Http::fake();

        $asset = new Asset([
            'slug' => 'tailwindcss',
            'name' => 'Tailwind CSS',
            'type' => Asset::TYPE_NPM,
        ]);

        $todos = (new IssueGeneratorService)->generate($asset, [
            'findings' => [
                [
                    'kind' => 'feature',
                    'title' => 'Add new opacity utilities',
                    'description' => 'A non-breaking feature release.',
                    'priority' => 'medium',
                ],
            ],
        ]);

        expect($todos)->toBeEmpty();
        Http::assertNothingSent();
    });
});

describe('_Ugly', function (): void {
    it('deduplicates against an existing open issue by asset and kind key', function (): void {
        Http::fake();

        $asset = new Asset([
            'slug' => 'laravel-framework',
            'name' => 'Laravel Framework',
            'type' => Asset::TYPE_COMPOSER,
        ]);

        $todos = (new IssueGeneratorService)->generate($asset, [
            'existing_open_issues' => [
                [
                    'state' => 'open',
                    'title' => 'Existing Laravel breaking-change ticket',
                    'description' => 'asset:laravel-framework:kind:breaking',
                ],
            ],
            'findings' => [
                [
                    'breaking' => true,
                    'title' => 'Update middleware registration contract',
                    'description' => 'This should not produce a duplicate issue.',
                ],
            ],
        ]);

        expect($todos)->toBeEmpty();
        Http::assertNothingSent();
    });
});
