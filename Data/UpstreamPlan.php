<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Data;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class UpstreamPlan implements Arrayable
{
    public function __construct(
        public string $assetKey,
        public string $assetName,
        public Collection $todos,
        public array $todosByPriority,
        public array $migrationChecklist,
        public int $estimatedEffortHours,
        public int $breakingCount,
        public string $strategy,
        public array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'asset_key' => $this->assetKey,
            'asset_name' => $this->assetName,
            'todos' => $this->todos->map(
                fn (UpstreamTodo $todo): array => $todo->toArray()
            )->values()->all(),
            'todos_by_priority' => collect($this->todosByPriority)
                ->map(fn (mixed $todos): array => collect($todos)
                    ->map(fn (UpstreamTodo $todo): array => $todo->toArray())
                    ->values()
                    ->all())
                ->all(),
            'migration_checklist' => $this->migrationChecklist,
            'estimated_effort_hours' => $this->estimatedEffortHours,
            'breaking_count' => $this->breakingCount,
            'strategy' => $this->strategy,
            'metadata' => $this->metadata,
        ];
    }
}
