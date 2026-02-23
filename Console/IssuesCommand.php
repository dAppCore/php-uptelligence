<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Console;

use Core\Mod\Uptelligence\Models\UpstreamTodo;
use Core\Mod\Uptelligence\Models\Vendor;
use Illuminate\Console\Command;

class IssuesCommand extends Command
{
    protected $signature = 'upstream:issues
                            {vendor? : Filter by vendor slug}
                            {--status=pending : Filter by status (pending, in_progress, ported, skipped, wont_port, all)}
                            {--type= : Filter by type (feature, bugfix, security, ui, api, refactor, dependency)}
                            {--quick-wins : Show only quick wins (low effort, high priority)}
                            {--limit=50 : Maximum number of issues to display}';

    protected $description = 'List upstream todos/issues for tracking';

    public function handle(): int
    {
        $vendorSlug = $this->argument('vendor');
        $status = $this->option('status');
        $type = $this->option('type');
        $quickWins = $this->option('quick-wins');
        $limit = (int) $this->option('limit');

        $query = UpstreamTodo::with('vendor');

        // Filter by vendor
        if ($vendorSlug) {
            $vendor = Vendor::where('slug', $vendorSlug)->first();
            if (! $vendor) {
                $this->error("Vendor not found: {$vendorSlug}");

                return self::FAILURE;
            }
            $query->where('vendor_id', $vendor->id);
        }

        // Filter by status
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        // Filter by type
        if ($type) {
            $query->where('type', $type);
        }

        // Quick wins filter
        if ($quickWins) {
            $query->quickWins();
        }

        // Order by priority
        $query->orderByDesc('priority')->orderByDesc('created_at');

        $todos = $query->limit($limit)->get();

        if ($todos->isEmpty()) {
            $this->info('No issues found matching criteria.');

            return self::SUCCESS;
        }

        // Show summary stats
        $this->line('<fg=cyan>Issue Summary:</>');
        $this->newLine();

        $totalPending = UpstreamTodo::pending()->count();
        $totalInProgress = UpstreamTodo::inProgress()->count();
        $totalQuickWins = UpstreamTodo::quickWins()->count();
        $totalSecurity = UpstreamTodo::securityRelated()->pending()->count();

        $this->line("  Pending: {$totalPending}");
        $this->line("  In Progress: {$totalInProgress}");
        $this->line("  Quick Wins: {$totalQuickWins}");
        $this->line("  Security: {$totalSecurity}");

        $this->newLine();
        $this->line("Showing {$todos->count()} issues:");
        $this->newLine();

        $table = [];
        foreach ($todos as $todo) {
            $icon = $todo->getTypeIcon();
            $priorityColor = match (true) {
                $todo->priority >= 8 => 'red',
                $todo->priority >= 5 => 'yellow',
                default => 'gray',
            };

            $statusBadge = match ($todo->status) {
                'pending' => '<fg=yellow>pending</>',
                'in_progress' => '<fg=blue>in progress</>',
                'ported' => '<fg=green>ported</>',
                'skipped' => '<fg=gray>skipped</>',
                'wont_port' => '<fg=red>wont port</>',
                default => $todo->status,
            };

            $quickWinBadge = $todo->isQuickWin() ? ' <fg=green>[QW]</>' : '';

            $table[] = [
                $todo->id,
                $todo->vendor->slug,
                "{$icon} {$todo->type}",
                "<fg={$priorityColor}>{$todo->priority}</>",
                $todo->effort,
                mb_substr($todo->title, 0, 40).(mb_strlen($todo->title) > 40 ? '...' : '').$quickWinBadge,
                $statusBadge,
                $todo->github_issue_number ? "#{$todo->github_issue_number}" : '-',
            ];
        }

        $this->table(
            ['ID', 'Vendor', 'Type', 'Pri', 'Effort', 'Title', 'Status', 'Issue'],
            $table
        );

        // Show vendor breakdown if not filtered
        if (! $vendorSlug) {
            $this->newLine();
            $this->line('<fg=cyan>By Vendor:</>');

            $byVendor = $todos->groupBy(fn ($t) => $t->vendor->slug);
            foreach ($byVendor as $slug => $vendorTodos) {
                $quickWinCount = $vendorTodos->filter->isQuickWin()->count();
                $qwInfo = $quickWinCount > 0 ? " ({$quickWinCount} quick wins)" : '';
                $this->line("  {$slug}: {$vendorTodos->count()}{$qwInfo}");
            }
        }

        return self::SUCCESS;
    }
}
