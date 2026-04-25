<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class UpstreamTodo implements Arrayable
{
    public function __construct(
        public string $assetKey,
        public string $assetName,
        public string $kind,
        public string $priority,
        public string $title,
        public string $description,
        public ?string $fromVersion = null,
        public ?string $toVersion = null,
        public ?string $issuePlatform = null,
        public ?string $issueUrl = null,
        public ?string $issueId = null,
        public string $issueStatus = 'pending',
        public string $dedupeKey = '',
        public string $titleHash = '',
        public int $estimatedEffortHours = 1,
        public array $suggestedSolution = [],
        public array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'asset_key' => $this->assetKey,
            'asset_name' => $this->assetName,
            'kind' => $this->kind,
            'priority' => $this->priority,
            'title' => $this->title,
            'description' => $this->description,
            'from_version' => $this->fromVersion,
            'to_version' => $this->toVersion,
            'issue_platform' => $this->issuePlatform,
            'issue_url' => $this->issueUrl,
            'issue_id' => $this->issueId,
            'issue_status' => $this->issueStatus,
            'dedupe_key' => $this->dedupeKey,
            'title_hash' => $this->titleHash,
            'estimated_effort_hours' => $this->estimatedEffortHours,
            'suggested_solution' => $this->suggestedSolution,
            'metadata' => $this->metadata,
        ];
    }
}
