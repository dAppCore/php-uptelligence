<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Services;

use Core\Mod\Uptelligence\Data\UpstreamTodo as UpstreamTodoData;
use Core\Mod\Uptelligence\Models\AnalysisLog;
use Core\Mod\Uptelligence\Models\Asset;
use Core\Mod\Uptelligence\Models\UpstreamTodo as UpstreamTodoModel;
use Core\Mod\Uptelligence\Models\Vendor;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Issue Generator Service - creates GitHub/Gitea issues from upstream todos.
 *
 * Generates individual issues and weekly digests for tracking porting work.
 */
class IssueGeneratorService
{
    private const OPEN_ISSUE_STATES = ['open', 'new', 'pending', 'assigned', 'acknowledged', 'confirmed'];

    protected string $githubToken;

    protected string $giteaUrl;

    protected string $giteaToken;

    protected array $defaultLabels;

    protected array $assignees;

    public function __construct()
    {
        $this->githubToken = (string) (config('upstream.github.token') ?? '');
        $this->giteaUrl = (string) (config('upstream.gitea.url') ?? '');
        $this->giteaToken = (string) (config('upstream.gitea.token') ?? '');
        $this->defaultLabels = (array) (config('upstream.github.default_labels') ?: ['upstream']);
        $this->assignees = array_filter((array) (config('upstream.github.assignees') ?? []));
    }

    /**
     * Generate issue-backed todos for breaking changes found in an asset analysis.
     *
     * @return Collection<int, UpstreamTodoData>
     */
    public function generate(Asset $asset, mixed $analysis): Collection
    {
        $payload = $this->normaliseAnalysisPayload($analysis);
        $platform = $this->resolveIssuePlatform($payload);
        $findings = $this->extractBreakingFindings($payload);

        if ($findings->isEmpty()) {
            return collect();
        }

        $existingIssues = $this->existingOpenIssues($asset, $payload, $platform);
        $generatedKeys = [];
        $todos = collect();

        foreach ($findings as $finding) {
            $draft = $this->buildGeneratedTodo($asset, $finding, $payload, $platform);

            if (in_array($draft->dedupeKey, $generatedKeys, true)) {
                continue;
            }

            if ($this->hasExistingOpenIssue($existingIssues, $draft)) {
                continue;
            }

            $issue = $this->createGeneratedIssue($asset, $draft, $payload, $platform);
            $todo = $this->buildGeneratedTodo($asset, $finding, $payload, $platform, $issue);

            $todos->push($todo);
            $generatedKeys[] = $todo->dedupeKey;
            $existingIssues->push([
                'title' => $todo->title,
                'state' => 'open',
                'dedupe_key' => $todo->dedupeKey,
                'title_hash' => $todo->titleHash,
                'asset_kind_key' => $todo->metadata['asset_kind_key'] ?? null,
            ]);
        }

        return $todos;
    }

    protected function normaliseAnalysisPayload(mixed $analysis): array
    {
        if ($analysis instanceof AnalysisLog) {
            return $analysis->context ?? [];
        }

        if ($analysis instanceof Arrayable) {
            return $analysis->toArray();
        }

        if (is_array($analysis)) {
            return $analysis;
        }

        if (is_object($analysis)) {
            return get_object_vars($analysis);
        }

        return [];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function extractBreakingFindings(array $payload): Collection
    {
        $findings = $this->wrapList(data_get($payload, 'findings', []));

        if (empty($findings)) {
            $findings = $this->wrapList(data_get($payload, 'breaking_changes', []));
        }

        if (empty($findings)) {
            $findings = $this->wrapList(data_get($payload, 'summary.breaking_changes', []));
        }

        if (empty($findings) && $this->payloadHasBreakingCategory($payload)) {
            $findings = [[
                'title' => data_get($payload, 'title', 'Review breaking upstream change'),
                'description' => data_get($payload, 'summary', 'The analysis flagged a breaking upstream change.'),
                'kind' => 'breaking',
            ]];
        }

        return collect($findings)
            ->map(fn (mixed $finding): array => $this->normaliseFinding($finding))
            ->filter(fn (array $finding): bool => $this->isBreakingFinding($finding, $payload))
            ->values();
    }

    protected function normaliseFinding(mixed $finding): array
    {
        if ($finding instanceof Arrayable) {
            return $finding->toArray();
        }

        if (is_array($finding)) {
            return $finding;
        }

        if (is_object($finding)) {
            return get_object_vars($finding);
        }

        return [
            'title' => (string) $finding,
            'description' => (string) $finding,
            'kind' => 'breaking',
        ];
    }

    protected function isBreakingFinding(array $finding, array $payload): bool
    {
        if ((bool) data_get($finding, 'breaking', false) || (bool) data_get($finding, 'is_breaking', false)) {
            return true;
        }

        $kind = $this->normaliseKind((string) (
            data_get($finding, 'kind')
            ?? data_get($finding, 'category')
            ?? data_get($finding, 'type')
            ?? ''
        ));

        if (in_array($kind, ['breaking', 'breaking_change', 'incompatible', 'major'], true)) {
            return true;
        }

        $categories = collect([
            ...$this->stringList(data_get($payload, 'categories', [])),
            ...$this->stringList(data_get($finding, 'categories', [])),
            ...$this->stringList(data_get($finding, 'tags', [])),
        ])->map(fn (string $category): string => $this->normaliseKind($category));

        return $categories->contains(fn (string $category): bool => str_contains($category, 'breaking'));
    }

    protected function payloadHasBreakingCategory(array $payload): bool
    {
        return collect($this->stringList(data_get($payload, 'categories', [])))
            ->map(fn (string $category): string => $this->normaliseKind($category))
            ->contains(fn (string $category): bool => str_contains($category, 'breaking'));
    }

    protected function buildGeneratedTodo(
        Asset $asset,
        array $finding,
        array $payload,
        string $platform,
        array $issue = []
    ): UpstreamTodoData {
        $assetKey = $this->assetKey($asset);
        $assetName = $this->assetName($asset);
        $title = $this->findingTitle($asset, $finding);
        $kind = $this->normaliseKind((string) (
            data_get($finding, 'kind')
            ?? data_get($finding, 'category')
            ?? data_get($finding, 'type')
            ?? 'breaking'
        ));
        $kind = $kind === '' ? 'breaking' : $kind;
        $titleHash = hash('sha256', Str::lower(trim($title)));
        $assetKindKey = "asset:{$assetKey}:kind:{$kind}";
        $dedupeKey = "{$assetKindKey}:title:".substr($titleHash, 0, 16);
        $suggestedSolution = $this->normaliseSuggestedSolution(
            data_get($finding, 'suggested_solution')
            ?? data_get($finding, 'solution')
            ?? data_get($finding, 'steps')
            ?? []
        );

        return new UpstreamTodoData(
            assetKey: $assetKey,
            assetName: $assetName,
            kind: $kind,
            priority: $this->normalisePriority($finding),
            title: $title,
            description: $this->findingDescription($asset, $finding, $payload),
            fromVersion: $this->fromVersion($asset, $payload),
            toVersion: $this->toVersion($asset, $payload),
            issuePlatform: $platform,
            issueUrl: $issue['issue_url'] ?? null,
            issueId: isset($issue['issue_id']) ? (string) $issue['issue_id'] : null,
            issueStatus: $issue['status'] ?? 'pending',
            dedupeKey: $dedupeKey,
            titleHash: $titleHash,
            estimatedEffortHours: $this->estimatedEffortHours($finding),
            suggestedSolution: $suggestedSolution,
            metadata: [
                'asset_kind_key' => $assetKindKey,
                'files' => $this->wrapList(data_get($finding, 'files', [])),
                'labels' => $this->generatedLabels($kind, $this->normalisePriority($finding), $assetKey),
                'source_finding' => $finding,
            ],
        );
    }

    protected function findingTitle(Asset $asset, array $finding): string
    {
        $title = trim((string) (data_get($finding, 'title') ?? data_get($finding, 'summary') ?? ''));

        if ($title !== '') {
            return $title;
        }

        return "Review breaking change in {$this->assetName($asset)}";
    }

    protected function findingDescription(Asset $asset, array $finding, array $payload): string
    {
        $description = trim((string) (
            data_get($finding, 'description')
            ?? data_get($finding, 'details')
            ?? data_get($finding, 'summary')
            ?? ''
        ));

        if ($description === '') {
            $description = "A breaking upstream change was detected for {$this->assetName($asset)}.";
        }

        $from = $this->fromVersion($asset, $payload);
        $to = $this->toVersion($asset, $payload);

        if ($from || $to) {
            $description .= "\n\nVersion: ".($from ?? 'unknown').' to '.($to ?? 'unknown').'.';
        }

        return $description;
    }

    protected function normaliseKind(string $kind): string
    {
        return Str::of($kind)
            ->lower()
            ->replace([' ', '-'], '_')
            ->trim()
            ->toString();
    }

    protected function normalisePriority(array $finding): string
    {
        $priority = data_get($finding, 'priority') ?? data_get($finding, 'severity') ?? data_get($finding, 'impact');

        if (is_numeric($priority)) {
            return match (true) {
                (int) $priority >= 7 => 'high',
                (int) $priority >= 4 => 'medium',
                default => 'low',
            };
        }

        $priority = Str::lower((string) $priority);

        return match (true) {
            str_contains($priority, 'critical'),
            str_contains($priority, 'high') => 'high',
            str_contains($priority, 'medium') => 'medium',
            str_contains($priority, 'low') => 'low',
            default => 'high',
        };
    }

    protected function estimatedEffortHours(array $finding): int
    {
        $hours = data_get($finding, 'estimated_effort_hours') ?? data_get($finding, 'effort_hours');

        if (is_numeric($hours)) {
            return max(1, (int) $hours);
        }

        return match (Str::lower((string) data_get($finding, 'effort', 'medium'))) {
            'low', 'small' => 1,
            'high', 'large' => 8,
            default => 4,
        };
    }

    protected function normaliseSuggestedSolution(mixed $solution): array
    {
        if ($solution instanceof Arrayable) {
            return $solution->toArray();
        }

        if (is_array($solution)) {
            return $solution;
        }

        $solution = trim((string) $solution);

        return $solution === '' ? [] : ['steps' => [$solution]];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function existingOpenIssues(Asset $asset, array $payload, string $platform): Collection
    {
        $inlineIssues = collect([
            ...$this->wrapList(data_get($payload, 'existing_open_issues', [])),
            ...$this->wrapList(data_get($payload, 'open_issues', [])),
        ])->map(fn (mixed $issue): array => $this->normaliseFinding($issue));

        return $inlineIssues
            ->merge($this->fetchOpenIssues($asset, $payload, $platform))
            ->filter(fn (array $issue): bool => $this->isOpenIssue($issue))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function fetchOpenIssues(Asset $asset, array $payload, string $platform): Collection
    {
        return match ($platform) {
            'mantis' => $this->fetchMantisOpenIssues($payload),
            'forge', 'gitea' => $this->fetchForgeOpenIssues($asset, $payload),
            default => collect(),
        };
    }

    protected function fetchMantisOpenIssues(array $payload): Collection
    {
        $baseUrl = rtrim((string) config('upstream.mantis.url', ''), '/');
        $token = (string) config('upstream.mantis.token', '');

        if ($baseUrl === '' || $token === '') {
            return collect();
        }

        $projectId = data_get($payload, 'mantis.project_id') ?? config('upstream.mantis.project_id');
        $filterId = data_get($payload, 'mantis.filter_id') ?? config('upstream.mantis.open_filter_id');

        $response = Http::withHeaders(['Authorization' => $token])
            ->timeout(30)
            ->get("{$baseUrl}/api/rest/issues", array_filter([
                'project_id' => $projectId,
                'filter_id' => $filterId,
            ]));

        if (! $response->successful()) {
            return collect();
        }

        return collect($response->json('issues') ?? []);
    }

    protected function fetchForgeOpenIssues(Asset $asset, array $payload): Collection
    {
        $baseUrl = rtrim((string) config('upstream.forge.url', config('upstream.gitea.url', '')), '/');
        $token = (string) config('upstream.forge.token', config('upstream.gitea.token', ''));
        $targetRepo = $this->resolveTargetRepo($asset, $payload);

        if ($baseUrl === '' || $token === '' || ! $this->validateTargetRepo($targetRepo)) {
            return collect();
        }

        [$owner, $repo] = explode('/', $targetRepo);

        $response = Http::withHeaders([
            'Authorization' => 'token '.$token,
            'Accept' => 'application/json',
        ])
            ->timeout(30)
            ->get("{$baseUrl}/api/v1/repos/{$owner}/{$repo}/issues", [
                'state' => 'open',
            ]);

        if (! $response->successful()) {
            return collect();
        }

        return collect($response->json() ?? []);
    }

    protected function isOpenIssue(array $issue): bool
    {
        $state = data_get($issue, 'state')
            ?? data_get($issue, 'status.name')
            ?? data_get($issue, 'status')
            ?? 'open';

        if (is_array($state)) {
            $state = data_get($state, 'name', 'open');
        }

        return in_array(Str::lower((string) $state), self::OPEN_ISSUE_STATES, true);
    }

    protected function hasExistingOpenIssue(Collection $issues, UpstreamTodoData $todo): bool
    {
        return $issues->contains(function (array $issue) use ($todo): bool {
            if (! $this->isOpenIssue($issue)) {
                return false;
            }

            $assetKindKey = $todo->metadata['asset_kind_key'] ?? '';
            $issueText = Str::lower(implode(' ', array_filter([
                data_get($issue, 'title'),
                data_get($issue, 'summary'),
                data_get($issue, 'body'),
                data_get($issue, 'description'),
                data_get($issue, 'additional_information'),
                data_get($issue, 'dedupe_key'),
                data_get($issue, 'title_hash'),
                data_get($issue, 'asset_kind_key'),
            ])));

            if (Str::contains($issueText, [
                Str::lower($todo->dedupeKey),
                Str::lower($todo->titleHash),
                Str::lower($assetKindKey),
            ])) {
                return true;
            }

            return $this->normalisedTitle((string) data_get($issue, 'title', data_get($issue, 'summary', '')))
                === $this->normalisedTitle($todo->title);
        });
    }

    protected function createGeneratedIssue(
        Asset $asset,
        UpstreamTodoData $todo,
        array $payload,
        string $platform
    ): array {
        try {
            return match ($platform) {
                'mantis' => $this->createMantisIssue($asset, $todo, $payload),
                'forge', 'gitea' => $this->createForgeIssue($asset, $todo, $payload),
                default => [
                    'status' => 'skipped',
                    'issue_platform' => $platform,
                ],
            };
        } catch (\Throwable $e) {
            Log::error('Uptelligence: Generated issue creation failed', [
                'asset' => $todo->assetKey,
                'title' => $todo->title,
                'platform' => $platform,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            return [
                'status' => 'failed',
                'issue_platform' => $platform,
            ];
        }
    }

    protected function createMantisIssue(Asset $asset, UpstreamTodoData $todo, array $payload): array
    {
        $baseUrl = rtrim((string) config('upstream.mantis.url', ''), '/');
        $token = (string) config('upstream.mantis.token', '');
        $project = data_get($payload, 'mantis.project')
            ?? data_get($payload, 'mantis.project_id')
            ?? config('upstream.mantis.project_id')
            ?? config('upstream.mantis.project_name');

        if ($baseUrl === '' || $token === '' || empty($project)) {
            return [
                'status' => 'skipped',
                'issue_platform' => 'mantis',
            ];
        }

        $response = Http::withHeaders(['Authorization' => $token])
            ->timeout(30)
            ->post("{$baseUrl}/api/rest/issues", [
                'summary' => $todo->title,
                'description' => $this->buildGeneratedIssueBody($asset, $todo),
                'project' => is_numeric($project)
                    ? ['id' => (int) $project]
                    : ['name' => (string) $project],
                'category' => ['name' => config('upstream.mantis.category', 'Uptelligence')],
                'tags' => collect($todo->metadata['labels'] ?? [])
                    ->map(fn (string $label): array => ['name' => $label])
                    ->values()
                    ->all(),
                'additional_information' => "dedupe_key={$todo->dedupeKey}\ntitle_hash={$todo->titleHash}",
            ]);

        if (! $response->successful()) {
            Log::error('Uptelligence: Mantis issue creation failed', [
                'asset' => $todo->assetKey,
                'status' => $response->status(),
                'body' => $this->redactSensitiveData(substr($response->body(), 0, 500)),
            ]);

            return [
                'status' => 'failed',
                'issue_platform' => 'mantis',
            ];
        }

        $issue = $response->json('issue') ?? $response->json();
        $issueId = data_get($issue, 'id');

        return [
            'status' => 'created',
            'issue_platform' => 'mantis',
            'issue_id' => $issueId,
            'issue_url' => data_get($issue, 'url') ?? ($issueId ? "{$baseUrl}/view.php?id={$issueId}" : null),
        ];
    }

    protected function createForgeIssue(Asset $asset, UpstreamTodoData $todo, array $payload): array
    {
        $baseUrl = rtrim((string) config('upstream.forge.url', config('upstream.gitea.url', '')), '/');
        $token = (string) config('upstream.forge.token', config('upstream.gitea.token', ''));
        $targetRepo = $this->resolveTargetRepo($asset, $payload);

        if ($baseUrl === '' || $token === '' || ! $this->validateTargetRepo($targetRepo)) {
            return [
                'status' => 'skipped',
                'issue_platform' => 'forge',
            ];
        }

        [$owner, $repo] = explode('/', $targetRepo);

        $response = Http::withHeaders([
            'Authorization' => 'token '.$token,
            'Accept' => 'application/json',
        ])
            ->timeout(30)
            ->retry(3, 250)
            ->post("{$baseUrl}/api/v1/repos/{$owner}/{$repo}/issues", [
                'title' => $todo->title,
                'body' => $this->buildGeneratedIssueBody($asset, $todo),
                'labels' => $todo->metadata['labels'] ?? [],
            ]);

        if (! $response->successful()) {
            Log::error('Uptelligence: Forge issue creation failed', [
                'asset' => $todo->assetKey,
                'target_repo' => $targetRepo,
                'status' => $response->status(),
                'body' => $this->redactSensitiveData(substr($response->body(), 0, 500)),
            ]);

            return [
                'status' => 'failed',
                'issue_platform' => 'forge',
            ];
        }

        $issue = $response->json();

        return [
            'status' => 'created',
            'issue_platform' => 'forge',
            'issue_id' => data_get($issue, 'number') ?? data_get($issue, 'id'),
            'issue_url' => data_get($issue, 'html_url') ?? data_get($issue, 'url'),
        ];
    }

    protected function buildGeneratedIssueBody(Asset $asset, UpstreamTodoData $todo): string
    {
        $body = "## Breaking upstream change\n\n";
        $body .= "**Asset:** {$todo->assetName} (`{$todo->assetKey}`)\n";
        $body .= '**Version:** '.($todo->fromVersion ?? 'unknown').' to '.($todo->toVersion ?? 'unknown')."\n";
        $body .= "**Priority:** {$todo->priority}\n";
        $body .= "**Estimated effort:** {$todo->estimatedEffortHours} hour(s)\n";
        $body .= "**Dedupe key:** `{$todo->dedupeKey}`\n";
        $body .= "**Title hash:** `{$todo->titleHash}`\n\n";
        $body .= "## Description\n\n{$todo->description}\n\n";

        if (! empty($todo->suggestedSolution)) {
            $body .= "## Suggested solution\n\n";
            foreach ($todo->suggestedSolution['steps'] ?? $todo->suggestedSolution as $step) {
                if (is_array($step)) {
                    $step = implode(': ', array_filter($step));
                }

                $body .= '- '.trim((string) $step)."\n";
            }
            $body .= "\n";
        }

        $files = $todo->metadata['files'] ?? [];
        if (! empty($files)) {
            $body .= "## Files\n\n";
            foreach ($files as $file) {
                $body .= "- `{$file}`\n";
            }
            $body .= "\n";
        }

        $body .= "---\n";
        $body .= "_Auto-generated by Uptelligence._\n";

        return $body;
    }

    protected function resolveIssuePlatform(array $payload): string
    {
        $platform = data_get($payload, 'issue_platform')
            ?? config('upstream.issue_platform')
            ?? config('uptelligence.issue_platform')
            ?? 'forge';

        $platform = Str::lower((string) $platform);

        return match ($platform) {
            'mantis', 'mantisbt' => 'mantis',
            'gitea', 'forgejo', 'forge' => 'forge',
            default => $platform,
        };
    }

    protected function resolveTargetRepo(Asset $asset, array $payload): ?string
    {
        return data_get($payload, 'target_repo')
            ?? data_get($payload, 'forge.target_repo')
            ?? data_get($asset, 'target_repo')
            ?? config('upstream.forge.target_repo')
            ?? config('upstream.gitea.target_repo')
            ?? (str_contains((string) $asset->package_name, '/') ? $asset->package_name : null);
    }

    protected function generatedLabels(string $kind, string $priority, string $assetKey): array
    {
        return array_values(array_unique([
            'upgrade',
            $priority,
            $kind,
            "asset:{$assetKey}",
        ]));
    }

    protected function assetKey(Asset $asset): string
    {
        $key = $asset->slug
            ?? $asset->package_name
            ?? ($asset->id ? (string) $asset->id : null)
            ?? $asset->name
            ?? 'asset';

        return Str::slug((string) $key) ?: 'asset';
    }

    protected function assetName(Asset $asset): string
    {
        return (string) ($asset->name ?? $asset->package_name ?? $this->assetKey($asset));
    }

    protected function fromVersion(Asset $asset, array $payload): ?string
    {
        return data_get($payload, 'from_version')
            ?? data_get($payload, 'versions.from')
            ?? $asset->installed_version;
    }

    protected function toVersion(Asset $asset, array $payload): ?string
    {
        return data_get($payload, 'to_version')
            ?? data_get($payload, 'versions.to')
            ?? $asset->latest_version;
    }

    protected function normalisedTitle(string $title): string
    {
        return Str::of($title)
            ->lower()
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }

    protected function wrapList(mixed $value): array
    {
        if ($value instanceof Collection) {
            return $value->all();
        }

        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        if ($value === null || $value === '') {
            return [];
        }

        if (! is_array($value)) {
            return [$value];
        }

        if (array_is_list($value)) {
            return $value;
        }

        return [$value];
    }

    protected function stringList(mixed $value): array
    {
        return collect($this->wrapList($value))
            ->flatten()
            ->filter(fn (mixed $item): bool => is_scalar($item))
            ->map(fn (mixed $item): string => (string) $item)
            ->values()
            ->all();
    }

    /**
     * Validate target_repo format (should be 'owner/repo').
     *
     * @throws InvalidArgumentException if format is invalid
     */
    protected function validateTargetRepo(?string $targetRepo): bool
    {
        if (empty($targetRepo)) {
            return false;
        }

        // Must be in format 'owner/repo' with no extra slashes
        if (! preg_match('#^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$#', $targetRepo)) {
            Log::warning('Uptelligence: Invalid target_repo format', [
                'target_repo' => $targetRepo,
                'expected_format' => 'owner/repo',
            ]);

            return false;
        }

        return true;
    }

    public function createIssue(UpstreamTodoModel $todo): array
    {
        $platform = $this->resolveTodoIssuePlatform($todo);

        $issue = match ($platform) {
            'github' => $this->createGitHubIssue($todo),
            'forge' => $this->createGiteaIssue($todo),
            default => null,
        };

        if (! is_array($issue)) {
            return [
                'status' => 'skipped',
                'issue_platform' => $platform,
            ];
        }

        return [
            'status' => 'created',
            'issue_platform' => $platform,
            'issue_id' => data_get($issue, 'number') ?? data_get($issue, 'id'),
            'issue_url' => data_get($issue, 'html_url') ?? data_get($issue, 'url') ?? $todo->issue_url,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function createIssuesFromAnalysis(AnalysisLog $analysis): array
    {
        return UpstreamTodoModel::query()
            ->where('analysis_log_id', $analysis->getKey())
            ->whereNull('issue_url')
            ->get()
            ->map(fn (UpstreamTodoModel $todo): array => $this->createIssue($todo))
            ->values()
            ->all();
    }

    protected function resolveTodoIssuePlatform(UpstreamTodoModel $todo): string
    {
        $workspace = null;
        if ($todo->workspace_id) {
            $workspace = $todo->relationLoaded('workspace') ? $todo->workspace : $todo->workspace()->first();
        }

        $platform = data_get($workspace, 'issue_platform')
            ?? data_get($workspace, 'settings.issue_platform')
            ?? $todo->issue_platform
            ?? config('uptelligence.issue_platform')
            ?? config('upstream.issue_platform')
            ?? 'forge';

        return match (Str::lower((string) $platform)) {
            'github' => 'github',
            'forge', 'gitea', 'forgejo' => 'forge',
            default => 'forge',
        };
    }

    /**
     * Create GitHub issues for all pending todos.
     */
    public function createIssuesForVendor(Vendor $vendor, bool $useGitea = false): Collection
    {
        // Validate target_repo format before processing
        if (! $this->validateTargetRepo($vendor->target_repo)) {
            Log::error('Uptelligence: Cannot create issues - invalid target_repo', [
                'vendor' => $vendor->slug,
                'target_repo' => $vendor->target_repo,
            ]);

            return collect();
        }

        $todos = $vendor->todos()
            ->where('status', UpstreamTodoModel::STATUS_PENDING)
            ->whereNull('github_issue_number')
            ->orderByDesc('priority')
            ->get();

        $issues = collect();

        foreach ($todos as $todo) {
            // Check rate limit before creating issue
            if (RateLimiter::tooManyAttempts('upstream-issues', 10)) {
                $seconds = RateLimiter::availableIn('upstream-issues');
                Log::warning('Uptelligence: Issue creation rate limit exceeded', [
                    'vendor' => $vendor->slug,
                    'retry_after_seconds' => $seconds,
                ]);
                break;
            }

            try {
                if ($useGitea) {
                    $issue = $this->createGiteaIssue($todo);
                } else {
                    $issue = $this->createIssue($todo);
                }

                if ($issue) {
                    $issues->push($issue);
                    RateLimiter::hit('upstream-issues');
                }
            } catch (\Exception $e) {
                Log::error('Uptelligence: Failed to create issue', [
                    'vendor' => $vendor->slug,
                    'todo_id' => $todo->id,
                    'todo_title' => $todo->title,
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                ]);
                report($e);
            }
        }

        return $issues;
    }

    /**
     * Create a GitHub issue for a todo with retry logic.
     */
    public function createGitHubIssue(UpstreamTodoModel $todo): ?array
    {
        if (! $this->githubToken || ! $this->validateTargetRepo($todo->vendor->target_repo)) {
            return null;
        }

        $body = $this->buildIssueBody($todo);
        $labels = $this->buildLabels($todo);

        [$owner, $repo] = explode('/', $todo->vendor->target_repo);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->githubToken,
            'Accept' => 'application/vnd.github.v3+json',
        ])
            ->timeout(30)
            ->retry(3, function (int $attempt, \Exception $exception) {
                // Exponential backoff: 1s, 2s, 4s
                $delay = (int) pow(2, $attempt - 1) * 1000;

                Log::warning('Uptelligence: GitHub API retry', [
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                    'error' => $exception->getMessage(),
                ]);

                return $delay;
            }, function (\Exception $exception) {
                // Only retry on connection/timeout errors or 5xx/429 responses
                if ($exception instanceof ConnectionException) {
                    return true;
                }
                if ($exception instanceof RequestException) {
                    $status = $exception->response?->status();

                    return $status >= 500 || $status === 429;
                }

                return false;
            })
            ->post("https://api.github.com/repos/{$owner}/{$repo}/issues", [
                'title' => $this->buildIssueTitle($todo),
                'body' => $body,
                'labels' => $labels,
                'assignees' => $this->assignees,
            ]);

        if ($response->successful()) {
            $issue = $response->json();
            $issueUrl = $issue['html_url'] ?? $issue['url'] ?? null;

            $todo->update([
                'github_issue_number' => $issue['number'],
                'issue_url' => $issueUrl,
                'issue_platform' => 'github',
            ]);

            AnalysisLog::logIssueCreated($todo, (string) $issueUrl);

            return $issue;
        }

        Log::error('Uptelligence: GitHub issue creation failed', [
            'todo_id' => $todo->id,
            'status' => $response->status(),
            'body' => $this->redactSensitiveData(substr($response->body(), 0, 500)),
        ]);

        return null;
    }

    /**
     * Create a Gitea issue for a todo with retry logic.
     */
    public function createGiteaIssue(UpstreamTodoModel $todo): ?array
    {
        if (! $this->giteaToken || ! $this->giteaUrl || ! $this->validateTargetRepo($todo->vendor->target_repo)) {
            return null;
        }

        $body = $this->buildIssueBody($todo);

        [$owner, $repo] = explode('/', $todo->vendor->target_repo);

        $response = Http::withHeaders([
            'Authorization' => 'token '.$this->giteaToken,
            'Accept' => 'application/json',
        ])
            ->timeout(30)
            ->retry(3, function (int $attempt, \Exception $exception) {
                // Exponential backoff: 1s, 2s, 4s
                $delay = (int) pow(2, $attempt - 1) * 1000;

                Log::warning('Uptelligence: Gitea API retry', [
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                    'error' => $exception->getMessage(),
                ]);

                return $delay;
            }, function (\Exception $exception) {
                // Only retry on connection/timeout errors or 5xx/429 responses
                if ($exception instanceof ConnectionException) {
                    return true;
                }
                if ($exception instanceof RequestException) {
                    $status = $exception->response?->status();

                    return $status >= 500 || $status === 429;
                }

                return false;
            })
            ->post("{$this->giteaUrl}/api/v1/repos/{$owner}/{$repo}/issues", [
                'title' => $this->buildIssueTitle($todo),
                'body' => $body,
                'labels' => [], // Gitea handles labels differently
            ]);

        if ($response->successful()) {
            $issue = $response->json();

            $todo->update([
                'github_issue_number' => (string) $issue['number'],
            ]);

            $issueUrl = "{$this->giteaUrl}/{$owner}/{$repo}/issues/{$issue['number']}";

            AnalysisLog::logIssueCreated($todo, $issueUrl);

            $todo->update([
                'issue_url' => $issueUrl,
                'issue_platform' => 'forge',
            ]);

            return $issue;
        }

        Log::error('Uptelligence: Gitea issue creation failed', [
            'todo_id' => $todo->id,
            'status' => $response->status(),
            'body' => $this->redactSensitiveData(substr($response->body(), 0, 500)),
        ]);

        return null;
    }

    /**
     * Redact sensitive data from log messages.
     *
     * Removes or masks API tokens and credentials that might
     * appear in error responses.
     */
    protected function redactSensitiveData(string $content): string
    {
        $patterns = [
            // GitHub tokens (ghp_..., gho_..., github_pat_...)
            '/ghp_[a-zA-Z0-9]+/' => '[REDACTED_GITHUB_TOKEN]',
            '/gho_[a-zA-Z0-9]+/' => '[REDACTED_GITHUB_TOKEN]',
            '/github_pat_[a-zA-Z0-9_]+/' => '[REDACTED_GITHUB_TOKEN]',
            // Generic bearer tokens
            '/Bearer\s+[a-zA-Z0-9._-]+/' => 'Bearer [REDACTED]',
            // Gitea tokens
            '/token\s+[a-zA-Z0-9]{20,}/' => 'token [REDACTED]',
            // Authorization header values
            '/["\']?[Aa]uthorization["\']?\s*:\s*["\']?[^"\'}\s]+/' => '"authorization": "[REDACTED]"',
            // Generic token patterns in JSON
            '/["\']?token["\']?\s*:\s*["\']?[a-zA-Z0-9._-]{20,}["\']?/' => '"token": "[REDACTED]"',
        ];

        $redacted = $content;
        foreach ($patterns as $pattern => $replacement) {
            $redacted = preg_replace($pattern, $replacement, $redacted);
        }

        return $redacted;
    }

    /**
     * Build issue title.
     */
    protected function buildIssueTitle(UpstreamTodoModel $todo): string
    {
        $icon = $todo->getTypeIcon();
        $prefix = '[Upstream] ';

        return $prefix.$icon.' '.$todo->title;
    }

    /**
     * Build issue body with all relevant info.
     */
    protected function buildIssueBody(UpstreamTodoModel $todo): string
    {
        $body = "## Upstream Change\n\n";
        $body .= "**Vendor:** {$todo->vendor->name} ({$todo->vendor->vendor_name})\n";
        $body .= "**Version:** {$todo->from_version} → {$todo->to_version}\n";
        $body .= "**Type:** {$todo->type}\n";
        $body .= "**Priority:** {$todo->priority}/10 ({$todo->getPriorityLabel()})\n";
        $body .= "**Effort:** {$todo->getEffortLabel()}\n\n";

        if ($todo->description) {
            $body .= "## Description\n\n{$todo->description}\n\n";
        }

        if ($todo->port_notes) {
            $body .= "## Porting Notes\n\n{$todo->port_notes}\n\n";
        }

        if (! empty($todo->suggested_solution)) {
            $body .= "## Suggested Solution\n\n";
            foreach ($todo->suggested_solution['steps'] ?? $todo->suggested_solution as $step) {
                if (is_array($step)) {
                    $step = implode(': ', array_filter($step));
                }

                $body .= '- '.trim((string) $step)."\n";
            }
            $body .= "\n";
        }

        if ($todo->has_conflicts) {
            $body .= "## ⚠️ Potential Conflicts\n\n{$todo->conflict_reason}\n\n";
        }

        if (! empty($todo->files)) {
            $body .= "## Files Changed\n\n";
            foreach ($todo->files as $file) {
                $mapped = $todo->vendor->mapToHostHub($file);
                if ($mapped) {
                    $body .= "- `{$file}` → `{$mapped}`\n";
                } else {
                    $body .= "- `{$file}`\n";
                }
            }
            $body .= "\n";
        }

        if (! empty($todo->dependencies)) {
            $body .= "## Dependencies\n\n";
            foreach ($todo->dependencies as $dep) {
                $body .= "- {$dep}\n";
            }
            $body .= "\n";
        }

        if (! empty($todo->tags)) {
            $body .= "## Tags\n\n";
            $body .= implode(', ', array_map(fn ($t) => "`{$t}`", $todo->tags))."\n\n";
        }

        $body .= "---\n";
        $body .= "_Auto-generated by Upstream Intelligence Pipeline_\n";
        $body .= '_AI Confidence: '.round(($todo->ai_confidence ?? 0.85) * 100)."%_\n";

        return $body;
    }

    /**
     * Build labels for the issue.
     */
    protected function buildLabels(UpstreamTodoModel $todo): array
    {
        $labels = $this->defaultLabels;

        // Add type label
        $labels[] = 'type:'.$todo->type;

        // Add priority label
        if ($todo->priority >= 8) {
            $labels[] = 'priority:high';
        } elseif ($todo->priority >= 5) {
            $labels[] = 'priority:medium';
        } else {
            $labels[] = 'priority:low';
        }

        // Add effort label
        $labels[] = 'effort:'.$todo->effort;

        // Add quick-win label
        if ($todo->isQuickWin()) {
            $labels[] = 'quick-win';
        }

        // Add vendor label
        $labels[] = 'vendor:'.$todo->vendor->slug;

        return $labels;
    }

    /**
     * Create a weekly digest issue.
     */
    public function createWeeklyDigest(Vendor $vendor): ?array
    {
        $todos = $vendor->todos()
            ->where('status', UpstreamTodoModel::STATUS_PENDING)
            ->whereNull('github_issue_number')
            ->where('created_at', '>=', now()->subWeek())
            ->orderByDesc('priority')
            ->get();

        if ($todos->isEmpty()) {
            return null;
        }

        $title = "[Weekly Digest] {$vendor->name} - ".now()->format('M d, Y');
        $body = $this->buildDigestBody($vendor, $todos);

        if (! $this->githubToken || ! $vendor->target_repo) {
            return null;
        }

        [$owner, $repo] = explode('/', $vendor->target_repo);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->githubToken,
            'Accept' => 'application/vnd.github.v3+json',
        ])->post("https://api.github.com/repos/{$owner}/{$repo}/issues", [
            'title' => $title,
            'body' => $body,
            'labels' => ['upstream', 'digest'],
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }

    /**
     * Build weekly digest body.
     */
    protected function buildDigestBody(Vendor $vendor, Collection $todos): string
    {
        $body = "# Weekly Upstream Digest\n\n";
        $body .= "**Vendor:** {$vendor->name}\n";
        $body .= '**Week of:** '.now()->subWeek()->format('M d').' - '.now()->format('M d, Y')."\n";
        $body .= "**Total Changes:** {$todos->count()}\n\n";

        // Quick wins
        $quickWins = $todos->filter->isQuickWin();
        if ($quickWins->isNotEmpty()) {
            $body .= "## 🚀 Quick Wins ({$quickWins->count()})\n\n";
            foreach ($quickWins as $todo) {
                $body .= "- {$todo->getTypeIcon()} {$todo->title}\n";
            }
            $body .= "\n";
        }

        // Security
        $security = $todos->where('type', 'security');
        if ($security->isNotEmpty()) {
            $body .= "## 🔒 Security Updates ({$security->count()})\n\n";
            foreach ($security as $todo) {
                $body .= "- {$todo->title}\n";
            }
            $body .= "\n";
        }

        // Features
        $features = $todos->where('type', 'feature');
        if ($features->isNotEmpty()) {
            $body .= "## ✨ New Features ({$features->count()})\n\n";
            foreach ($features as $todo) {
                $body .= "- {$todo->title} (Priority: {$todo->priority}/10)\n";
            }
            $body .= "\n";
        }

        // Bug fixes
        $bugfixes = $todos->where('type', 'bugfix');
        if ($bugfixes->isNotEmpty()) {
            $body .= "## 🐛 Bug Fixes ({$bugfixes->count()})\n\n";
            foreach ($bugfixes as $todo) {
                $body .= "- {$todo->title}\n";
            }
            $body .= "\n";
        }

        // Other
        $other = $todos->whereNotIn('type', ['feature', 'bugfix', 'security'])->where(fn ($t) => ! $t->isQuickWin());
        if ($other->isNotEmpty()) {
            $body .= "## 📝 Other Changes ({$other->count()})\n\n";
            foreach ($other as $todo) {
                $body .= "- {$todo->getTypeIcon()} {$todo->title}\n";
            }
            $body .= "\n";
        }

        $body .= "---\n";
        $body .= "_Auto-generated by Upstream Intelligence Pipeline_\n";

        return $body;
    }
}
