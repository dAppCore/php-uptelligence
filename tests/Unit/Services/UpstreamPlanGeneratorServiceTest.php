<?php

declare(strict_types=1);

use Core\Mod\Uptelligence\Data\UpstreamPlan;
use Core\Mod\Uptelligence\Data\UpstreamTodo;
use Core\Mod\Uptelligence\Models\Asset;
use Core\Mod\Uptelligence\Services\UpstreamPlanGeneratorService;
use Tests\TestCase;

uses(TestCase::class);

describe('_Good', function (): void {
    it('groups todos by priority and orders the migration checklist', function (): void {
        $asset = new Asset([
            'slug' => 'flux-pro',
            'name' => 'Flux Pro',
            'type' => Asset::TYPE_COMPOSER,
            'installed_version' => '2.0.0',
            'latest_version' => '3.0.0',
        ]);

        $todos = collect([
            new UpstreamTodo(
                assetKey: 'flux-pro',
                assetName: 'Flux Pro',
                kind: 'docs',
                priority: 'low',
                title: 'Update upgrade guide references',
                description: 'Docs need the new component names.',
                estimatedEffortHours: 1,
            ),
            new UpstreamTodo(
                assetKey: 'flux-pro',
                assetName: 'Flux Pro',
                kind: 'breaking',
                priority: 'high',
                title: 'Replace removed modal API',
                description: 'The old modal registration API was removed.',
                estimatedEffortHours: 4,
            ),
            new UpstreamTodo(
                assetKey: 'flux-pro',
                assetName: 'Flux Pro',
                kind: 'feature',
                priority: 'medium',
                title: 'Adopt new table density option',
                description: 'The release adds a table density control.',
                estimatedEffortHours: 2,
            ),
        ]);

        $plan = (new UpstreamPlanGeneratorService)->plan($asset, $todos);

        $replaceStep = collect($plan->migrationChecklist)->firstWhere('todo_title', 'Replace removed modal API');
        $adoptStep = collect($plan->migrationChecklist)->firstWhere('todo_title', 'Adopt new table density option');
        $docsStep = collect($plan->migrationChecklist)->firstWhere('todo_title', 'Update upgrade guide references');

        expect($plan)->toBeInstanceOf(UpstreamPlan::class)
            ->and($plan->todosByPriority['high'])->toHaveCount(1)
            ->and($plan->todosByPriority['medium'])->toHaveCount(1)
            ->and($plan->todosByPriority['low'])->toHaveCount(1)
            ->and($replaceStep['order'])->toBeLessThan($adoptStep['order'])
            ->and($adoptStep['order'])->toBeLessThan($docsStep['order'])
            ->and($plan->breakingCount)->toBe(1)
            ->and($plan->estimatedEffortHours)->toBe(7);
    });
});

describe('_Bad', function (): void {
    it('returns an empty-priority plan when there are no todos', function (): void {
        $asset = new Asset([
            'slug' => 'font-awesome',
            'name' => 'Font Awesome',
            'type' => Asset::TYPE_FONT,
        ]);

        $plan = (new UpstreamPlanGeneratorService)->plan($asset, []);

        expect($plan->todos)->toBeEmpty()
            ->and($plan->todosByPriority['high'])->toBeEmpty()
            ->and($plan->todosByPriority['medium'])->toBeEmpty()
            ->and($plan->todosByPriority['low'])->toBeEmpty()
            ->and($plan->breakingCount)->toBe(0)
            ->and($plan->strategy)->toContain('standard version bump');
    });
});

describe('_Ugly', function (): void {
    it('normalises mixed todo shapes and priority values', function (): void {
        $asset = new Asset([
            'slug' => 'mixpost-pro',
            'name' => 'Mixpost Pro',
            'type' => Asset::TYPE_MANUAL,
            'installed_version' => '1.4.0',
            'latest_version' => '2.0.0',
        ]);

        $plan = (new UpstreamPlanGeneratorService)->plan($asset, [
            [
                'kind' => 'feature',
                'priority' => 5,
                'title' => 'Wire new billing toggle',
                'description' => 'Feature-level migration work.',
                'estimated_effort_hours' => 2,
            ],
            [
                'kind' => 'breaking-change',
                'priority' => 9,
                'title' => 'Rename tenant billing contract',
                'description' => 'High-risk API migration.',
                'estimated_effort_hours' => 6,
            ],
            [
                'kind' => 'docs',
                'priority' => 'unknown',
                'title' => 'Refresh internal notes',
                'description' => 'Fallback priority should be low.',
            ],
            [
                'kind' => 'breaking',
                'priority' => 'high',
                'description' => 'Missing title should be ignored.',
            ],
        ]);

        expect($plan->todos)->toHaveCount(3)
            ->and($plan->todosByPriority['high'])->toHaveCount(1)
            ->and($plan->todosByPriority['medium'])->toHaveCount(1)
            ->and($plan->todosByPriority['low'])->toHaveCount(1)
            ->and($plan->todosByPriority['high']->first()->title)->toBe('Rename tenant billing contract')
            ->and($plan->migrationChecklist[3]['todo_title'])->toBe('Rename tenant billing contract');
    });
});
