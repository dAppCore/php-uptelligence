<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\View\Modal\Admin;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Core\Mod\Uptelligence\Models\DiffCache;
use Core\Mod\Uptelligence\Models\Vendor;
use Core\Mod\Uptelligence\Models\VersionRelease;

#[Title('Diff Viewer')]
class DiffViewer extends Component
{
    use WithPagination;

    #[Url]
    public ?int $vendorId = null;

    #[Url]
    public ?int $releaseId = null;

    #[Url]
    public string $category = '';

    #[Url]
    public string $changeType = '';

    public ?int $selectedDiffId = null;

    public bool $showDiffModal = false;

    public function mount(): void
    {
        $this->checkHadesAccess();
    }

    #[Computed]
    public function vendors(): \Illuminate\Database\Eloquent\Collection
    {
        return Vendor::active()
            ->whereHas('releases')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function releases(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->vendorId) {
            return collect();
        }

        return VersionRelease::where('vendor_id', $this->vendorId)
            ->analyzed()
            ->latest()
            ->get();
    }

    #[Computed]
    public function selectedRelease(): ?VersionRelease
    {
        if (! $this->releaseId) {
            return null;
        }

        return VersionRelease::with('vendor')->find($this->releaseId);
    }

    #[Computed]
    public function diffs(): \Illuminate\Pagination\LengthAwarePaginator
    {
        if (! $this->releaseId) {
            return DiffCache::whereNull('id')->paginate(20);
        }

        return DiffCache::where('version_release_id', $this->releaseId)
            ->when($this->category, fn ($q) => $q->where('category', $this->category))
            ->when($this->changeType, fn ($q) => $q->where('change_type', $this->changeType))
            ->orderByRaw("FIELD(change_type, 'added', 'modified', 'removed')")
            ->orderBy('file_path')
            ->paginate(30);
    }

    #[Computed]
    public function diffStats(): array
    {
        if (! $this->releaseId) {
            return [
                'total' => 0,
                'added' => 0,
                'modified' => 0,
                'removed' => 0,
                'by_category' => [],
            ];
        }

        $diffs = DiffCache::where('version_release_id', $this->releaseId)->get();

        return [
            'total' => $diffs->count(),
            'added' => $diffs->where('change_type', DiffCache::CHANGE_ADDED)->count(),
            'modified' => $diffs->where('change_type', DiffCache::CHANGE_MODIFIED)->count(),
            'removed' => $diffs->where('change_type', DiffCache::CHANGE_REMOVED)->count(),
            'by_category' => $diffs->groupBy('category')->map->count()->toArray(),
        ];
    }

    #[Computed]
    public function selectedDiff(): ?DiffCache
    {
        if (! $this->selectedDiffId) {
            return null;
        }

        return DiffCache::find($this->selectedDiffId);
    }

    public function selectVendor(int $vendorId): void
    {
        $this->vendorId = $vendorId;
        $this->releaseId = null;
        $this->resetPage();
        unset($this->releases, $this->selectedRelease, $this->diffs, $this->diffStats);
    }

    public function selectRelease(int $releaseId): void
    {
        $this->releaseId = $releaseId;
        $this->resetPage();
        unset($this->selectedRelease, $this->diffs, $this->diffStats);
    }

    public function viewDiff(int $diffId): void
    {
        $this->selectedDiffId = $diffId;
        $this->showDiffModal = true;
        unset($this->selectedDiff);
    }

    public function closeDiffModal(): void
    {
        $this->showDiffModal = false;
        $this->selectedDiffId = null;
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function updatedChangeType(): void
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
        return view('uptelligence::admin.diff-viewer')
            ->layout('hub::admin.layouts.app', ['title' => 'Diff Viewer']);
    }
}
