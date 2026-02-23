<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\View\Modal\Admin;

use Core\Mod\Uptelligence\Models\Asset;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Asset Manager')]
class AssetManager extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $licenceType = '';

    #[Url]
    public bool $needsUpdate = false;

    #[Url]
    public string $sortBy = 'name';

    #[Url]
    public string $sortDir = 'asc';

    public function mount(): void
    {
        $this->checkHadesAccess();
    }

    #[Computed]
    public function assets(): \Illuminate\Pagination\LengthAwarePaginator
    {
        return Asset::query()
            ->when($this->search, fn ($q) => $q->where(function ($sq) {
                $sq->where('name', 'like', "%{$this->search}%")
                    ->orWhere('slug', 'like', "%{$this->search}%")
                    ->orWhere('package_name', 'like', "%{$this->search}%");
            }))
            ->when($this->type, fn ($q) => $q->where('type', $this->type))
            ->when($this->licenceType, fn ($q) => $q->where('licence_type', $this->licenceType))
            ->when($this->needsUpdate, fn ($q) => $q->needsUpdate())
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(20);
    }

    #[Computed]
    public function assetStats(): array
    {
        return [
            'total' => Asset::active()->count(),
            'needs_update' => Asset::needsUpdate()->count(),
            'composer' => Asset::composer()->count(),
            'npm' => Asset::npm()->count(),
            'expiring_soon' => Asset::active()->get()->filter->isLicenceExpiringSoon()->count(),
            'expired' => Asset::active()->get()->filter->isLicenceExpired()->count(),
        ];
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

    public function toggleActive(int $assetId): void
    {
        $asset = Asset::findOrFail($assetId);
        $asset->update(['is_active' => ! $asset->is_active]);
        unset($this->assets, $this->assetStats);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedLicenceType(): void
    {
        $this->resetPage();
    }

    public function updatedNeedsUpdate(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'type', 'licenceType', 'needsUpdate']);
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
        return view('uptelligence::admin.asset-manager')
            ->layout('hub::admin.layouts.app', ['title' => 'Asset Manager']);
    }
}
