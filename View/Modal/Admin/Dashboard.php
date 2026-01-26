<?php

declare(strict_types=1);

namespace Core\Uptelligence\View\Modal\Admin;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Core\Uptelligence\Models\Asset;
use Core\Uptelligence\Models\UpstreamTodo;
use Core\Uptelligence\Models\Vendor;
use Core\Uptelligence\Models\VersionRelease;

#[Title('Uptelligence Dashboard')]
class Dashboard extends Component
{
    public function mount(): void
    {
        $this->checkHadesAccess();
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'vendors_tracked' => Vendor::active()->count(),
            'pending_todos' => UpstreamTodo::pending()->count(),
            'quick_wins' => UpstreamTodo::quickWins()->count(),
            'security_updates' => UpstreamTodo::securityRelated()->pending()->count(),
            'in_progress' => UpstreamTodo::inProgress()->count(),
            'assets_tracked' => Asset::active()->count(),
            'assets_need_update' => Asset::needsUpdate()->count(),
        ];
    }

    #[Computed]
    public function statCards(): array
    {
        return [
            ['value' => $this->stats['vendors_tracked'], 'label' => 'Vendors Tracked', 'icon' => 'building-office', 'color' => 'blue'],
            ['value' => $this->stats['pending_todos'], 'label' => 'Pending Todos', 'icon' => 'clipboard-document-list', 'color' => 'yellow'],
            ['value' => $this->stats['quick_wins'], 'label' => 'Quick Wins', 'icon' => 'bolt', 'color' => 'green'],
            ['value' => $this->stats['security_updates'], 'label' => 'Security Updates', 'icon' => 'shield-exclamation', 'color' => 'red'],
        ];
    }

    #[Computed]
    public function recentReleases(): \Illuminate\Database\Eloquent\Collection
    {
        return VersionRelease::with('vendor')
            ->analyzed()
            ->latest()
            ->take(5)
            ->get();
    }

    #[Computed]
    public function recentTodos(): \Illuminate\Database\Eloquent\Collection
    {
        return UpstreamTodo::with('vendor')
            ->pending()
            ->orderByDesc('priority')
            ->take(10)
            ->get();
    }

    #[Computed]
    public function vendorSummary(): array
    {
        return Vendor::active()
            ->withCount(['todos as pending_todos_count' => fn ($q) => $q->pending()])
            ->orderByDesc('pending_todos_count')
            ->take(5)
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'slug' => $v->slug,
                'source_type' => $v->source_type,
                'current_version' => $v->current_version,
                'pending_todos' => $v->pending_todos_count,
                'last_checked' => $v->last_checked_at?->diffForHumans() ?? 'Never',
            ])
            ->toArray();
    }

    #[Computed]
    public function todosByType(): array
    {
        return UpstreamTodo::pending()
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();
    }

    #[Computed]
    public function todosByEffort(): array
    {
        return UpstreamTodo::pending()
            ->selectRaw('effort, COUNT(*) as count')
            ->groupBy('effort')
            ->pluck('count', 'effort')
            ->toArray();
    }

    public function refresh(): void
    {
        unset(
            $this->stats,
            $this->statCards,
            $this->recentReleases,
            $this->recentTodos,
            $this->vendorSummary,
            $this->todosByType,
            $this->todosByEffort
        );
    }

    private function checkHadesAccess(): void
    {
        if (! auth()->user()?->isHades()) {
            abort(403, 'Hades access required');
        }
    }

    public function render(): View
    {
        return view('uptelligence::admin.dashboard')
            ->layout('hub::admin.layouts.app', ['title' => 'Uptelligence Dashboard']);
    }
}
