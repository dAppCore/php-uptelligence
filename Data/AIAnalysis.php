<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class AIAnalysis implements Arrayable
{
    public function __construct(
        public string $severity,
        public string $summary,
        public array $actionItems,
        public string $riskLevel,
        public array $categories = [],
        public array $findings = [],
        public bool $cached = false,
        public array $metadata = [],
    ) {}

    public static function fromArray(array $data, bool $cached = false): self
    {
        return new self(
            severity: (string) ($data['severity'] ?? 'medium'),
            summary: (string) ($data['summary'] ?? ''),
            actionItems: $data['action_items'] ?? $data['actionItems'] ?? [],
            riskLevel: (string) ($data['risk_level'] ?? $data['riskLevel'] ?? $data['severity'] ?? 'medium'),
            categories: $data['categories'] ?? [],
            findings: $data['findings'] ?? [],
            cached: $cached || (bool) ($data['cached'] ?? false),
            metadata: $data['metadata'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'summary' => $this->summary,
            'action_items' => $this->actionItems,
            'risk_level' => $this->riskLevel,
            'categories' => $this->categories,
            'findings' => $this->findings,
            'cached' => $this->cached,
            'metadata' => $this->metadata,
        ];
    }
}
