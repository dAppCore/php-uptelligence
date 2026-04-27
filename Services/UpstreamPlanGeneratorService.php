<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Services;

use Core\Mod\Uptelligence\Data\UpstreamPlan;
use Core\Mod\Uptelligence\Data\UpstreamTodo as UpstreamTodoData;
use Core\Mod\Uptelligence\Models\Asset;
use Core\Mod\Uptelligence\Models\UpstreamTodo as UpstreamTodoModel;
use Core\Mod\Uptelligence\Models\Vendor;
use Core\Mod\Uptelligence\Models\VersionRelease;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Mod\Agentic\Models\AgentPhase;
use Mod\Agentic\Models\AgentPlan;

/**
 * Upstream Plan Generator Service - creates agent plans from version release analysis.
 *
 * Generates structured plans with phases grouped by change type for systematic porting.
 *
 * Note: This service has an optional dependency on the Agentic module. If the module
 * is not installed, plan generation methods will return null and log a warning.
 */
class UpstreamPlanGeneratorService
{
    private const PRIORITY_ORDER = [
        'high' => 0,
        'medium' => 1,
        'low' => 2,
    ];

    /**
     * Generate a structured migration plan for one tracked asset.
     *
     * @param  Collection<int, mixed>|array<int, mixed>  $todos
     */
    public function plan(Asset $asset, Collection|array $todos): UpstreamPlan
    {
        $normalisedTodos = collect($todos)
            ->map(fn (mixed $todo): ?UpstreamTodoData => $this->normaliseTodo($asset, $todo))
            ->filter()
            ->sortBy([
                fn (UpstreamTodoData $todo): int => self::PRIORITY_ORDER[$todo->priority] ?? self::PRIORITY_ORDER['low'],
                fn (UpstreamTodoData $todo): int => $this->kindOrder($todo->kind),
                fn (UpstreamTodoData $todo): string => $todo->title,
            ])
            ->values();

        $todosByPriority = [
            'high' => $normalisedTodos->where('priority', 'high')->values(),
            'medium' => $normalisedTodos->where('priority', 'medium')->values(),
            'low' => $normalisedTodos->where('priority', 'low')->values(),
        ];

        $breakingCount = $normalisedTodos
            ->filter(fn (UpstreamTodoData $todo): bool => $this->isBreakingTodo($todo))
            ->count();
        $estimatedEffortHours = (int) $normalisedTodos->sum('estimatedEffortHours');

        return new UpstreamPlan(
            assetKey: $this->assetKey($asset),
            assetName: $this->assetName($asset),
            todos: $normalisedTodos,
            todosByPriority: $todosByPriority,
            migrationChecklist: $this->buildMigrationChecklist($asset, $normalisedTodos),
            estimatedEffortHours: $estimatedEffortHours,
            breakingCount: $breakingCount,
            strategy: $this->buildStrategy($asset, $normalisedTodos, $breakingCount, $estimatedEffortHours),
            metadata: [
                'from_version' => $asset->installed_version,
                'to_version' => $asset->latest_version,
                'todo_count' => $normalisedTodos->count(),
                'priority_counts' => collect($todosByPriority)->map->count()->all(),
            ],
        );
    }

    protected function normaliseTodo(Asset $asset, mixed $todo): ?UpstreamTodoData
    {
        if ($todo instanceof UpstreamTodoData) {
            return $todo;
        }

        if ($todo instanceof UpstreamTodoModel) {
            return new UpstreamTodoData(
                assetKey: $this->assetKey($asset),
                assetName: $this->assetName($asset),
                kind: $todo->type,
                priority: $this->normalisePriority($todo->priority),
                title: $todo->title,
                description: $todo->description ?? '',
                fromVersion: $todo->from_version ?? $asset->installed_version,
                toVersion: $todo->to_version ?? $asset->latest_version,
                issuePlatform: $todo->github_issue_number ? 'github' : null,
                issueId: $todo->github_issue_number,
                issueStatus: $todo->github_issue_number ? 'created' : 'pending',
                estimatedEffortHours: $this->estimatedEffortHours($todo->effort),
                suggestedSolution: $todo->port_notes ? ['steps' => [$todo->port_notes]] : [],
                metadata: [
                    'files' => $todo->files ?? [],
                    'dependencies' => $todo->dependencies ?? [],
                    'source_todo_id' => $todo->id,
                ],
            );
        }

        if ($todo instanceof Arrayable) {
            $todo = $todo->toArray();
        } elseif (is_object($todo)) {
            $todo = get_object_vars($todo);
        }

        if (! is_array($todo)) {
            return null;
        }

        $title = trim((string) (data_get($todo, 'title') ?? ''));
        if ($title === '') {
            return null;
        }

        return new UpstreamTodoData(
            assetKey: (string) (data_get($todo, 'asset_key') ?? data_get($todo, 'assetKey') ?? $this->assetKey($asset)),
            assetName: (string) (data_get($todo, 'asset_name') ?? data_get($todo, 'assetName') ?? $this->assetName($asset)),
            kind: $this->normaliseKind((string) (data_get($todo, 'kind') ?? data_get($todo, 'type') ?? 'breaking')),
            priority: $this->normalisePriority(data_get($todo, 'priority', 'medium')),
            title: $title,
            description: (string) (data_get($todo, 'description') ?? ''),
            fromVersion: data_get($todo, 'from_version') ?? data_get($todo, 'fromVersion') ?? $asset->installed_version,
            toVersion: data_get($todo, 'to_version') ?? data_get($todo, 'toVersion') ?? $asset->latest_version,
            issuePlatform: data_get($todo, 'issue_platform') ?? data_get($todo, 'issuePlatform'),
            issueUrl: data_get($todo, 'issue_url') ?? data_get($todo, 'issueUrl'),
            issueId: data_get($todo, 'issue_id') ?? data_get($todo, 'issueId'),
            issueStatus: (string) (data_get($todo, 'issue_status') ?? data_get($todo, 'issueStatus') ?? 'pending'),
            dedupeKey: (string) (data_get($todo, 'dedupe_key') ?? data_get($todo, 'dedupeKey') ?? ''),
            titleHash: (string) (data_get($todo, 'title_hash') ?? data_get($todo, 'titleHash') ?? ''),
            estimatedEffortHours: $this->estimatedEffortHours(data_get($todo, 'estimated_effort_hours') ?? data_get($todo, 'estimatedEffortHours') ?? data_get($todo, 'effort', 1)),
            suggestedSolution: $this->normaliseArray(data_get($todo, 'suggested_solution') ?? data_get($todo, 'suggestedSolution') ?? []),
            metadata: $this->normaliseArray(data_get($todo, 'metadata', [])),
        );
    }

    /**
     * @param  Collection<int, UpstreamTodoData>  $todos
     * @return array<int, array<string, mixed>>
     */
    protected function buildMigrationChecklist(Asset $asset, Collection $todos): array
    {
        $steps = [
            [
                'order' => 1,
                'priority' => 'setup',
                'action' => "Review {$this->assetName($asset)} release notes and linked upstream issues.",
            ],
            [
                'order' => 2,
                'priority' => 'setup',
                'action' => 'Create a migration branch and capture the current test baseline.',
            ],
            [
                'order' => 3,
                'priority' => 'setup',
                'action' => "Update {$this->assetName($asset)} from ".($asset->installed_version ?? 'current').' to '.($asset->latest_version ?? 'target').'.',
            ],
        ];

        $order = count($steps) + 1;

        foreach (['high', 'medium', 'low'] as $priority) {
            foreach ($todos->where('priority', $priority)->values() as $todo) {
                $steps[] = [
                    'order' => $order,
                    'priority' => $priority,
                    'action' => $this->todoChecklistAction($todo),
                    'todo_title' => $todo->title,
                    'kind' => $todo->kind,
                    'issue_url' => $todo->issueUrl,
                ];
                $order++;
            }
        }

        $steps[] = [
            'order' => $order,
            'priority' => 'verify',
            'action' => 'Run the focused regression suite and any package-specific build checks.',
        ];
        $order++;

        $steps[] = [
            'order' => $order,
            'priority' => 'verify',
            'action' => 'Document residual risks, close linked issues, and record the installed version.',
        ];

        return $steps;
    }

    protected function todoChecklistAction(UpstreamTodoData $todo): string
    {
        return match (true) {
            $this->isBreakingTodo($todo) => "Resolve breaking change: {$todo->title}.",
            $todo->kind === 'security' => "Apply security update: {$todo->title}.",
            default => "Complete {$todo->priority} priority task: {$todo->title}.",
        };
    }

    /**
     * @param  Collection<int, UpstreamTodoData>  $todos
     */
    protected function buildStrategy(Asset $asset, Collection $todos, int $breakingCount, int $estimatedEffortHours): string
    {
        if ($todos->isEmpty()) {
            return "No upstream todos were generated for {$this->assetName($asset)}; perform a standard version bump and smoke test.";
        }

        $strategy = "Migrate {$this->assetName($asset)} by resolving high priority work before medium and low priority tasks.";

        if ($breakingCount > 0) {
            $strategy .= " {$breakingCount} breaking change(s) must be handled before rollout.";
        }

        $strategy .= " Estimated effort: {$estimatedEffortHours} hour(s).";

        return $strategy;
    }

    protected function normalisePriority(mixed $priority): string
    {
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
            default => 'low',
        };
    }

    protected function normaliseKind(string $kind): string
    {
        return Str::of($kind)
            ->lower()
            ->replace([' ', '-'], '_')
            ->trim()
            ->toString();
    }

    protected function kindOrder(string $kind): int
    {
        return match ($kind) {
            'breaking', 'breaking_change' => 0,
            'security' => 1,
            default => 2,
        };
    }

    protected function isBreakingTodo(UpstreamTodoData $todo): bool
    {
        return in_array($todo->kind, ['breaking', 'breaking_change', 'incompatible', 'major'], true)
            || str_contains(Str::lower($todo->title), 'breaking');
    }

    protected function estimatedEffortHours(mixed $effort): int
    {
        if (is_numeric($effort)) {
            return max(1, (int) $effort);
        }

        return match (Str::lower((string) $effort)) {
            'low', 'small' => 1,
            'high', 'large' => 8,
            default => 4,
        };
    }

    protected function normaliseArray(mixed $value): array
    {
        if ($value instanceof Collection) {
            return $value->all();
        }

        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        if (is_array($value)) {
            return $value;
        }

        return $value === null ? [] : [$value];
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

    /**
     * Check if the Agentic module is available.
     */
    protected function agenticModuleAvailable(): bool
    {
        return class_exists(AgentPlan::class)
            && class_exists(AgentPhase::class);
    }

    /**
     * Generate an AgentPlan from a version release analysis.
     *
     * @return AgentPlan|null Returns null if Agentic module unavailable or no todos
     */
    public function generateFromRelease(VersionRelease $release, array $options = []): mixed
    {
        if (! $this->agenticModuleAvailable()) {
            report(new \RuntimeException('Agentic module not available - cannot generate plan from release'));

            return null;
        }

        $vendor = $release->vendor;
        $todos = UpstreamTodoModel::where('vendor_id', $vendor->id)
            ->where('from_version', $release->previous_version)
            ->where('to_version', $release->version)
            ->where('status', 'pending')
            ->orderByDesc('priority')
            ->get();

        if ($todos->isEmpty()) {
            return null;
        }

        return $this->createPlanFromTodos($vendor, $release, $todos, $options);
    }

    /**
     * Generate an AgentPlan from vendor's pending todos.
     *
     * @return AgentPlan|null Returns null if Agentic module unavailable or no todos
     */
    public function generateFromVendor(Vendor $vendor, array $options = []): mixed
    {
        if (! $this->agenticModuleAvailable()) {
            report(new \RuntimeException('Agentic module not available - cannot generate plan from vendor'));

            return null;
        }

        $todos = UpstreamTodoModel::where('vendor_id', $vendor->id)
            ->where('status', 'pending')
            ->orderByDesc('priority')
            ->get();

        if ($todos->isEmpty()) {
            return null;
        }

        $release = $vendor->releases()->latest()->first();

        return $this->createPlanFromTodos($vendor, $release, $todos, $options);
    }

    /**
     * Create AgentPlan from a collection of todos.
     *
     * @return AgentPlan
     */
    protected function createPlanFromTodos(
        Vendor $vendor,
        ?VersionRelease $release,
        Collection $todos,
        array $options = []
    ): mixed {
        $version = $release?->version ?? $vendor->current_version ?? 'latest';
        $activateImmediately = $options['activate'] ?? false;
        $includeContext = $options['include_context'] ?? true;

        // Create plan title
        $title = $options['title'] ?? "Port {$vendor->name} {$version}";
        $slug = AgentPlan::generateSlug($title);

        // Build context
        $context = $includeContext ? $this->buildContext($vendor, $release, $todos) : null;

        // Group todos by type for phases
        $groupedTodos = $this->groupTodosForPhases($todos);

        // Create the plan
        $plan = AgentPlan::create([
            'slug' => $slug,
            'title' => $title,
            'description' => $this->buildDescription($vendor, $release, $todos),
            'context' => $context,
            'status' => $activateImmediately ? AgentPlan::STATUS_ACTIVE : AgentPlan::STATUS_DRAFT,
            'metadata' => [
                'source' => 'upstream_analysis',
                'vendor_id' => $vendor->id,
                'vendor_slug' => $vendor->slug,
                'version_release_id' => $release?->id,
                'version' => $version,
                'todo_count' => $todos->count(),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);

        // Create phases
        $this->createPhasesFromGroupedTodos($plan, $groupedTodos);

        return $plan->fresh(['agentPhases']);
    }

    /**
     * Group todos into logical phases.
     */
    protected function groupTodosForPhases(Collection $todos): array
    {
        // Define phase order and groupings
        $phaseConfig = [
            'security' => [
                'name' => 'Security Updates',
                'description' => 'Critical security patches that should be applied first',
                'types' => ['security'],
                'priority' => 1,
            ],
            'database' => [
                'name' => 'Database & Schema Changes',
                'description' => 'Database migrations and schema updates',
                'types' => ['migration', 'database'],
                'priority' => 2,
            ],
            'core' => [
                'name' => 'Core Feature Updates',
                'description' => 'Main feature implementations and bug fixes',
                'types' => ['feature', 'bugfix', 'block'],
                'priority' => 3,
            ],
            'api' => [
                'name' => 'API Changes',
                'description' => 'API endpoint and integration updates',
                'types' => ['api'],
                'priority' => 4,
            ],
            'ui' => [
                'name' => 'UI & Frontend Changes',
                'description' => 'User interface and visual updates',
                'types' => ['ui', 'view'],
                'priority' => 5,
            ],
            'refactor' => [
                'name' => 'Refactoring & Dependencies',
                'description' => 'Code refactoring and dependency updates',
                'types' => ['refactor', 'dependency'],
                'priority' => 6,
            ],
        ];

        $phases = [];
        $assignedTodoIds = [];

        // Assign todos to phases based on type
        foreach ($phaseConfig as $phaseKey => $config) {
            $phaseTodos = $todos->filter(function ($todo) use ($config, $assignedTodoIds) {
                return in_array($todo->type, $config['types']) &&
                       ! in_array($todo->id, $assignedTodoIds);
            });

            if ($phaseTodos->isNotEmpty()) {
                $phases[$phaseKey] = [
                    'config' => $config,
                    'todos' => $phaseTodos,
                ];
                $assignedTodoIds = array_merge($assignedTodoIds, $phaseTodos->pluck('id')->toArray());
            }
        }

        // Handle any remaining unassigned todos
        $remainingTodos = $todos->filter(fn ($todo) => ! in_array($todo->id, $assignedTodoIds));
        if ($remainingTodos->isNotEmpty()) {
            $phases['other'] = [
                'config' => [
                    'name' => 'Other Changes',
                    'description' => 'Additional updates and changes',
                    'priority' => 99,
                ],
                'todos' => $remainingTodos,
            ];
        }

        // Sort by priority
        uasort($phases, fn ($a, $b) => ($a['config']['priority'] ?? 99) <=> ($b['config']['priority'] ?? 99));

        return $phases;
    }

    /**
     * Create AgentPhases from grouped todos.
     *
     * @param  AgentPlan  $plan
     */
    protected function createPhasesFromGroupedTodos(mixed $plan, array $groupedPhases): void
    {
        $order = 1;

        foreach ($groupedPhases as $phaseKey => $phaseData) {
            $config = $phaseData['config'];
            $todos = $phaseData['todos'];

            // Build tasks from todos
            $tasks = $todos->map(function ($todo) {
                return [
                    'name' => $todo->title,
                    'status' => 'pending',
                    'notes' => $todo->description,
                    'todo_id' => $todo->id,
                    'priority' => $todo->priority,
                    'effort' => $todo->effort,
                    'files' => $todo->files,
                ];
            })->sortByDesc('priority')->values()->toArray();

            // Create the phase
            AgentPhase::create([
                'agent_plan_id' => $plan->id,
                'order' => $order,
                'name' => $config['name'],
                'description' => $config['description'] ?? null,
                'tasks' => $tasks,
                'status' => AgentPhase::STATUS_PENDING,
                'metadata' => [
                    'phase_key' => $phaseKey,
                    'todo_count' => $todos->count(),
                    'todo_ids' => $todos->pluck('id')->toArray(),
                ],
            ]);

            $order++;
        }

        // Add review phase
        AgentPhase::create([
            'agent_plan_id' => $plan->id,
            'order' => $order,
            'name' => 'Review & Testing',
            'description' => 'Final review, testing, and documentation updates',
            'tasks' => [
                ['name' => 'Run test suite', 'status' => 'pending'],
                ['name' => 'Review all changes', 'status' => 'pending'],
                ['name' => 'Update documentation', 'status' => 'pending'],
                ['name' => 'Create PR/merge request', 'status' => 'pending'],
            ],
            'status' => AgentPhase::STATUS_PENDING,
            'metadata' => [
                'phase_key' => 'review',
                'is_final' => true,
            ],
        ]);
    }

    /**
     * Build context string for the plan.
     */
    protected function buildContext(Vendor $vendor, ?VersionRelease $release, Collection $todos): string
    {
        $context = "## Upstream Porting Context\n\n";
        $context .= "**Vendor:** {$vendor->name} ({$vendor->vendor_name})\n";
        $context .= "**Source Type:** {$vendor->getSourceTypeLabel()}\n";

        if ($release) {
            $context .= "**Version:** {$release->getVersionCompare()}\n";
            $context .= "**Files Changed:** {$release->getTotalChanges()}\n";
        }

        $context .= "**Total Todos:** {$todos->count()}\n\n";

        // Quick stats
        $byType = $todos->groupBy('type');
        $context .= "### Changes by Type\n\n";
        foreach ($byType as $type => $items) {
            $context .= "- **{$type}:** {$items->count()}\n";
        }

        // Path mapping info
        if ($vendor->path_mapping) {
            $context .= "\n### Path Mapping\n\n";
            foreach ($vendor->path_mapping as $from => $to) {
                $context .= "- `{$from}` → `{$to}`\n";
            }
        }

        // Target repo
        if ($vendor->target_repo) {
            $context .= "\n**Target Repository:** {$vendor->target_repo}\n";
        }

        // Quick wins
        $quickWins = $todos->filter(fn ($t) => $t->effort === 'low' && $t->priority >= 5);
        if ($quickWins->isNotEmpty()) {
            $context .= "\n### Quick Wins ({$quickWins->count()})\n\n";
            foreach ($quickWins->take(5) as $todo) {
                $context .= "- {$todo->title}\n";
            }
            if ($quickWins->count() > 5) {
                $context .= '- ... and '.($quickWins->count() - 5)." more\n";
            }
        }

        // Security items
        $security = $todos->where('type', 'security');
        if ($security->isNotEmpty()) {
            $context .= "\n### Security Updates ({$security->count()})\n\n";
            foreach ($security as $todo) {
                $context .= "- {$todo->title}\n";
            }
        }

        return $context;
    }

    /**
     * Build description for the plan.
     */
    protected function buildDescription(Vendor $vendor, ?VersionRelease $release, Collection $todos): string
    {
        $desc = "Auto-generated plan for porting {$vendor->name} updates";

        if ($release) {
            $desc .= " from version {$release->previous_version} to {$release->version}";
        }

        $desc .= ". Contains {$todos->count()} items";

        $security = $todos->where('type', 'security')->count();
        if ($security > 0) {
            $desc .= " including {$security} security update(s)";
        }

        $desc .= '.';

        return $desc;
    }

    /**
     * Sync AgentPlan tasks with UpstreamTodo status.
     *
     * @param  AgentPlan  $plan
     */
    public function syncPlanWithTodos(mixed $plan): int
    {
        if (! $this->agenticModuleAvailable()) {
            report(new \RuntimeException('Agentic module not available - cannot sync plan with todos'));

            return 0;
        }

        $synced = 0;

        foreach ($plan->agentPhases as $phase) {
            $tasks = $phase->tasks ?? [];
            $updated = false;

            foreach ($tasks as $i => $task) {
                if (! isset($task['todo_id'])) {
                    continue;
                }

                $todo = UpstreamTodoModel::find($task['todo_id']);
                if (! $todo) {
                    continue;
                }

                // Sync status
                $newStatus = match ($todo->status) {
                    'ported', 'wont_port', 'skipped' => 'completed',
                    'in_progress' => 'in_progress',
                    default => 'pending',
                };

                if (($task['status'] ?? 'pending') !== $newStatus) {
                    $tasks[$i]['status'] = $newStatus;
                    $updated = true;
                    $synced++;
                }
            }

            if ($updated) {
                $phase->update(['tasks' => $tasks]);
            }
        }

        return $synced;
    }

    /**
     * Mark upstream todo as ported when task is completed.
     */
    public function markTodoAsPorted(int $todoId): bool
    {
        $todo = UpstreamTodoModel::find($todoId);
        if (! $todo) {
            return false;
        }

        $todo->update([
            'status' => 'ported',
            'completed_at' => now(),
        ]);

        return true;
    }
}
