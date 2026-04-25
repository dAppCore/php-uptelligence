<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\View\Modal\Admin;

use Core\Mod\Uptelligence\Models\Vendor;
use Core\Mod\Uptelligence\Models\VersionRelease;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Vendor Manager')]
class VendorManager extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $sourceType = '';

    #[Url]
    public string $sortBy = 'name';

    #[Url]
    public string $sortDir = 'asc';

    public ?int $selectedVendorId = null;

    public bool $showVendorModal = false;

    public function mount(mixed $vendor = null): void
    {
        $this->checkHadesAccess();

        if (is_numeric($vendor)) {
            $this->selectedVendorId = (int) $vendor;
            $this->showVendorModal = true;
        }
    }

    #[Computed]
    public function vendors(): \Illuminate\Pagination\LengthAwarePaginator
    {
        return Vendor::query()
            ->withCount([
                'todos as pending_todos_count' => fn ($q) => $q->pending(),
                'todos as quick_wins_count' => fn ($q) => $q->quickWins(),
            ])
            ->when($this->search, fn ($q) => $q->where(function ($sq) {
                $sq->where('name', 'like', "%{$this->search}%")
                    ->orWhere('slug', 'like', "%{$this->search}%")
                    ->orWhere('vendor_name', 'like', "%{$this->search}%");
            }))
            ->when($this->sourceType, fn ($q) => $q->where('source_type', $this->sourceType))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(15);
    }

    #[Computed]
    public function selectedVendor(): ?Vendor
    {
        if (! $this->selectedVendorId) {
            return null;
        }

        return Vendor::with(['todos' => fn ($q) => $q->pending()->orderByDesc('priority')->take(5)])
            ->withCount(['todos as pending_todos_count' => fn ($q) => $q->pending()])
            ->find($this->selectedVendorId);
    }

    #[Computed]
    public function selectedVendorReleases(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->selectedVendorId) {
            return collect();
        }

        return VersionRelease::where('vendor_id', $this->selectedVendorId)
            ->analyzed()
            ->latest()
            ->take(10)
            ->get();
    }

    public function selectVendor(int $vendorId): void
    {
        $this->selectedVendorId = $vendorId;
        $this->showVendorModal = true;
        unset($this->selectedVendor, $this->selectedVendorReleases);
    }

    public function closeVendorModal(): void
    {
        $this->showVendorModal = false;
        $this->selectedVendorId = null;
    }

    public function sortBy(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }

        $this->resetPage();
    }

    public function toggleActive(int $vendorId): void
    {
        $vendor = Vendor::findOrFail($vendorId);
        $vendor->update(['is_active' => ! $vendor->is_active]);
        unset($this->vendors);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSourceType(): void
    {
        $this->resetPage();
    }

    private function checkHadesAccess(): void
    {
        if (! auth()->user()?->isHades()) {
            abort(403, 'Hades access required');
        }
    }

    public function render(): View
    {
        return view('uptelligence::admin.vendor-manager')
            ->layout('hub::admin.layouts.app', ['title' => 'Vendor Manager']);
    }
}
