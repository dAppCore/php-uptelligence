<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\View\Modal\Admin;

use Core\Mod\Uptelligence\Models\UptelligenceDigest;
use Core\Mod\Uptelligence\Models\Vendor;
use Core\Mod\Uptelligence\Services\UptelligenceDigestService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Digest Preferences')]
class DigestPreferences extends Component
{
    // Form state
    public bool $isEnabled = false;

    public string $frequency = 'weekly';

    public array $selectedVendorIds = [];

    public array $selectedTypes = ['releases', 'todos', 'security'];

    public ?int $minPriority = null;

    // UI state
    public bool $showPreview = false;

    protected UptelligenceDigestService $digestService;

    public function boot(UptelligenceDigestService $digestService): void
    {
        $this->digestService = $digestService;
    }

    public function mount(): void
    {
        $this->checkHadesAccess();
        $this->loadPreferences();
    }

    /**
     * Load existing preferences from database.
     */
    protected function loadPreferences(): void
    {
        $digest = $this->getDigest();

        $this->isEnabled = $digest->is_enabled;
        $this->frequency = $digest->frequency;
        $this->selectedVendorIds = $digest->getVendorIds() ?? [];
        $this->selectedTypes = $digest->getIncludedTypes();
        $this->minPriority = $digest->getMinPriority();
    }

    /**
     * Get or create the digest record for the current user.
     */
    protected function getDigest(): UptelligenceDigest
    {
        $user = auth()->user();
        $workspaceId = $user->defaultHostWorkspace()?->id;

        if (! $workspaceId) {
            abort(403, 'No workspace context');
        }

        return $this->digestService->getOrCreateDigest($user->id, $workspaceId);
    }

    #[Computed]
    public function vendors(): \Illuminate\Database\Eloquent\Collection
    {
        return Vendor::active()
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'source_type']);
    }

    #[Computed]
    public function digest(): UptelligenceDigest
    {
        return $this->getDigest();
    }

    #[Computed]
    public function preview(): array
    {
        $digest = $this->getDigest();

        // Apply current form values to preview
        $digest->is_enabled = true; // Preview as if enabled
        $digest->frequency = $this->frequency;
        $digest->preferences = [
            'vendor_ids' => empty($this->selectedVendorIds) ? null : $this->selectedVendorIds,
            'include_types' => $this->selectedTypes,
            'min_priority' => $this->minPriority,
        ];

        return $this->digestService->getDigestPreview($digest);
    }

    /**
     * Save preferences to database.
     */
    public function save(): void
    {
        $digest = $this->getDigest();

        $digest->update([
            'is_enabled' => $this->isEnabled,
            'frequency' => $this->frequency,
            'preferences' => [
                'vendor_ids' => empty($this->selectedVendorIds) ? null : $this->selectedVendorIds,
                'include_types' => $this->selectedTypes,
                'min_priority' => $this->minPriority,
            ],
        ]);

        $this->dispatch('toast', message: 'Digest preferences saved successfully.', type: 'success');

        // Refresh computed
        unset($this->digest);
    }

    /**
     * Toggle digest enabled state.
     */
    public function toggleEnabled(): void
    {
        $this->isEnabled = ! $this->isEnabled;
        $this->save();
    }

    /**
     * Toggle vendor selection.
     */
    public function toggleVendor(int $vendorId): void
    {
        if (in_array($vendorId, $this->selectedVendorIds)) {
            $this->selectedVendorIds = array_values(
                array_diff($this->selectedVendorIds, [$vendorId])
            );
        } else {
            $this->selectedVendorIds[] = $vendorId;
        }

        // Clear preview cache
        unset($this->preview);
    }

    /**
     * Select all vendors.
     */
    public function selectAllVendors(): void
    {
        $this->selectedVendorIds = [];
        unset($this->preview);
    }

    /**
     * Toggle type selection.
     */
    public function toggleType(string $type): void
    {
        if (in_array($type, $this->selectedTypes)) {
            $this->selectedTypes = array_values(
                array_diff($this->selectedTypes, [$type])
            );
        } else {
            $this->selectedTypes[] = $type;
        }

        unset($this->preview);
    }

    /**
     * Show the preview panel.
     */
    public function showPreview(): void
    {
        $this->showPreview = true;
        unset($this->preview);
    }

    /**
     * Hide the preview panel.
     */
    public function hidePreview(): void
    {
        $this->showPreview = false;
    }

    /**
     * Send a test digest immediately.
     */
    public function sendTestDigest(): void
    {
        $digest = $this->getDigest();

        // Temporarily enable for test
        $wasEnabled = $digest->is_enabled;
        $digest->is_enabled = true;

        try {
            $sent = $this->digestService->sendDigest($digest);

            if ($sent) {
                $this->dispatch('toast', message: 'Test digest sent to your email.', type: 'success');
            } else {
                $this->dispatch('toast', message: 'No content to include in digest.', type: 'info');
            }
        } catch (\Exception $e) {
            $this->dispatch('toast', message: 'Failed to send test digest: '.$e->getMessage(), type: 'error');
        }

        // Restore original state
        if (! $wasEnabled) {
            $digest->update(['is_enabled' => false]);
        }
    }

    private function checkHadesAccess(): void
    {
        if (! auth()->user()?->isHades()) {
            abort(403, 'Hades access required');
        }
    }

    public function render(): View
    {
        return view('uptelligence::admin.digest-preferences')
            ->layout('hub::admin.layouts.app', ['title' => 'Digest Preferences']);
    }
}
