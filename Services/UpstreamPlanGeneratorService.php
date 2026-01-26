<?php

declare(strict_types=1);

namespace Core\Uptelligence\Services;

use Illuminate\Support\Collection;
use Core\Uptelligence\Models\UpstreamTodo;
use Core\Uptelligence\Models\Vendor;
use Core\Uptelligence\Models\VersionRelease;

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
    /**
     * Check if the Agentic module is available.
     */
    protected function agenticModuleAvailable(): bool
    {
        return class_exists(\Mod\Agentic\Models\AgentPlan::class)
            && class_exists(\Mod\Agentic\Models\AgentPhase::class);
    }

    /**
     * Generate an AgentPlan from a version release analysis.
     *
     * @return \Mod\Agentic\Models\AgentPlan|null Returns null if Agentic module unavailable or no todos
     */
    public function generateFromRelease(VersionRelease $release, array $options = []): mixed
    {
        if (! $this->agenticModuleAvailable()) {
            report(new \RuntimeException('Agentic module not available - cannot generate plan from release'));

            return null;
        }

        $vendor = $release->vendor;
        $todos = UpstreamTodo::where('vendor_id', $vendor->id)
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
     * @return \Mod\Agentic\Models\AgentPlan|null Returns null if Agentic module unavailable or no todos
     */
    public function generateFromVendor(Vendor $vendor, array $options = []): mixed
    {
        if (! $this->agenticModuleAvailable()) {
            report(new \RuntimeException('Agentic module not available - cannot generate plan from vendor'));

            return null;
        }

        $todos = UpstreamTodo::where('vendor_id', $vendor->id)
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
     * @return \Mod\Agentic\Models\AgentPlan
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
        $slug = \Mod\Agentic\Models\AgentPlan::generateSlug($title);

        // Build context
        $context = $includeContext ? $this->buildContext($vendor, $release, $todos) : null;

        // Group todos by type for phases
        $groupedTodos = $this->groupTodosForPhases($todos);

        // Create the plan
        $plan = \Mod\Agentic\Models\AgentPlan::create([
            'slug' => $slug,
            'title' => $title,
            'description' => $this->buildDescription($vendor, $release, $todos),
            'context' => $context,
            'status' => $activateImmediately ? \Mod\Agentic\Models\AgentPlan::STATUS_ACTIVE : \Mod\Agentic\Models\AgentPlan::STATUS_DRAFT,
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
     * @param  \Mod\Agentic\Models\AgentPlan  $plan
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
            \Mod\Agentic\Models\AgentPhase::create([
                'agent_plan_id' => $plan->id,
                'order' => $order,
                'name' => $config['name'],
                'description' => $config['description'] ?? null,
                'tasks' => $tasks,
                'status' => \Mod\Agentic\Models\AgentPhase::STATUS_PENDING,
                'metadata' => [
                    'phase_key' => $phaseKey,
                    'todo_count' => $todos->count(),
                    'todo_ids' => $todos->pluck('id')->toArray(),
                ],
            ]);

            $order++;
        }

        // Add review phase
        \Mod\Agentic\Models\AgentPhase::create([
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
            'status' => \Mod\Agentic\Models\AgentPhase::STATUS_PENDING,
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
     * @param  \Mod\Agentic\Models\AgentPlan  $plan
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

                $todo = UpstreamTodo::find($task['todo_id']);
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
        $todo = UpstreamTodo::find($todoId);
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
