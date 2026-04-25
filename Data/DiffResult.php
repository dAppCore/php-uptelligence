<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class DiffResult implements Arrayable
{
    public function __construct(
        public array $changedFiles,
        public array $breakingChanges,
        public array $migrationSteps,
        public int $filesChanged = 0,
        public int $additions = 0,
        public int $deletions = 0,
        public array $byFile = [],
        public array $metadata = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            changedFiles: $data['changed_files'] ?? $data['changedFiles'] ?? [],
            breakingChanges: $data['breaking_changes'] ?? $data['breakingChanges'] ?? [],
            migrationSteps: $data['migration_steps'] ?? $data['migrationSteps'] ?? [],
            filesChanged: (int) ($data['files_changed'] ?? $data['filesChanged'] ?? count($data['changed_files'] ?? [])),
            additions: (int) ($data['additions'] ?? 0),
            deletions: (int) ($data['deletions'] ?? 0),
            byFile: $data['by_file'] ?? $data['byFile'] ?? [],
            metadata: $data['metadata'] ?? [],
        );
    }

    public function cacheKey(): string
    {
        if (is_string($this->metadata['cache_key'] ?? null)) {
            return $this->metadata['cache_key'];
        }

        return hash('sha256', json_encode([
            'changed_files' => $this->changedFiles,
            'breaking_changes' => $this->breakingChanges,
            'migration_steps' => $this->migrationSteps,
            'files_changed' => $this->filesChanged,
            'additions' => $this->additions,
            'deletions' => $this->deletions,
            'by_file' => $this->byFile,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function isEmpty(): bool
    {
        return $this->filesChanged === 0 && $this->changedFiles === [];
    }

    public function toArray(): array
    {
        return [
            'changed_files' => $this->changedFiles,
            'breaking_changes' => $this->breakingChanges,
            'migration_steps' => $this->migrationSteps,
            'files_changed' => $this->filesChanged,
            'additions' => $this->additions,
            'deletions' => $this->deletions,
            'by_file' => $this->byFile,
            'metadata' => $this->metadata,
            'cache_key' => $this->cacheKey(),
        ];
    }
}
