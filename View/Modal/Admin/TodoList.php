<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\View\Modal\Admin;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Core\Mod\Uptelligence\Models\UpstreamTodo;
use Core\Mod\Uptelligence\Models\Vendor;

#[Title('Upstream Todos')]
class TodoList extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public ?int $vendorId = null;

    #[Url]
    public string $status = 'pending';

    #[Url]
    public string $type = '';

    #[Url]
    public string $effort = '';

    #[Url]
    public string $priority = '';

    #[Url]
    public string $sortBy = 'priority';

    #[Url]
    public string $sortDir = 'desc';

    public array $selectedTodos = [];

    public bool $selectAll = false;

    public function mount(): void
    {
        $this->checkHadesAccess();
    }

    #[Computed]
    public function vendors(): \Illuminate\Database\Eloquent\Collection
    {
        return Vendor::active()->orderBy('name')->get();
    }

    #[Computed]
    public function todos(): \Illuminate\Pagination\LengthAwarePaginator
    {
        return UpstreamTodo::with('vendor')
            ->when($this->search, fn ($q) => $q->where(function ($sq) {
                $sq->where('title', 'like', "%{$this->search}%")
                    ->orWhere('description', 'like', "%{$this->search}%");
            }))
            ->when($this->vendorId, fn ($q) => $q->where('vendor_id', $this->vendorId))
            ->when($this->status, fn ($q) => match ($this->status) {
                'pending' => $q->pending(),
                'in_progress' => $q->inProgress(),
                'completed' => $q->completed(),
                'quick_wins' => $q->quickWins(),
                default => $q,
            })
            ->when($this->type, fn ($q) => $q->where('type', $this->type))
            ->when($this->effort, fn ($q) => $q->where('effort', $this->effort))
            ->when($this->priority, fn ($q) => match ($this->priority) {
                'critical' => $q->where('priority', '>=', 8),
                'high' => $q->whereBetween('priority', [6, 7]),
                'medium' => $q->whereBetween('priority', [4, 5]),
                'low' => $q->where('priority', '<', 4),
                default => $q,
            })
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(20);
    }

    #[Computed]
    public function todoStats(): array
    {
        $baseQuery = UpstreamTodo::query()
            ->when($this->vendorId, fn ($q) => $q->where('vendor_id', $this->vendorId));

        return [
            'pending' => (clone $baseQuery)->pending()->count(),
            'in_progress' => (clone $baseQuery)->inProgress()->count(),
            'quick_wins' => (clone $baseQuery)->quickWins()->count(),
            'completed' => (clone $baseQuery)->completed()->count(),
        ];
    }

    public function sortBy(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = $column === 'priority' ? 'desc' : 'asc';
        }

        $this->resetPage();
    }

    public function markStatus(int $todoId, string $status): void
    {
        $todo = UpstreamTodo::findOrFail($todoId);

        match ($status) {
            'in_progress' => $todo->markInProgress(),
            'ported' => $todo->markPorted(),
            'skipped' => $todo->markSkipped(),
            'wont_port' => $todo->markWontPort(),
            default => null,
        };

        unset($this->todos, $this->todoStats);
    }

    public function bulkMarkStatus(string $status): void
    {
        if (empty($this->selectedTodos)) {
            return;
        }

        $todos = UpstreamTodo::whereIn('id', $this->selectedTodos)->get();

        foreach ($todos as $todo) {
            match ($status) {
                'in_progress' => $todo->markInProgress(),
                'ported' => $todo->markPorted(),
                'skipped' => $todo->markSkipped(),
                'wont_port' => $todo->markWontPort(),
                default => null,
            };
        }

        $this->selectedTodos = [];
        $this->selectAll = false;
        unset($this->todos, $this->todoStats);
    }

    public function toggleSelectAll(): void
    {
        if ($this->selectAll) {
            $this->selectedTodos = $this->todos->pluck('id')->toArray();
        } else {
            $this->selectedTodos = [];
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedVendorId(): void
    {
        $this->resetPage();
        unset($this->todoStats);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedEffort(): void
    {
        $this->resetPage();
    }

    public function updatedPriority(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'vendorId', 'status', 'type', 'effort', 'priority']);
        $this->status = 'pending';
        $this->resetPage();
        unset($this->todoStats);
    }

    private function checkHadesAccess(): void
    {
        if (! auth()->user()?->isHades()) {
            abort(403, 'Hades access required');
        }
    }

    public function render(): View
    {
        return view('uptelligence::admin.todo-list')
            ->layout('hub::admin.layouts.app', ['title' => 'Upstream Todos']);
    }
}
