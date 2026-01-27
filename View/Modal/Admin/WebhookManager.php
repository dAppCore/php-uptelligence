<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\View\Modal\Admin;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Core\Mod\Uptelligence\Models\UptelligenceWebhook;
use Core\Mod\Uptelligence\Models\UptelligenceWebhookDelivery;
use Core\Mod\Uptelligence\Models\Vendor;

#[Title('Webhook Manager')]
class WebhookManager extends Component
{
    use WithPagination;

    #[Url]
    public ?int $vendorId = null;

    #[Url]
    public string $provider = '';

    #[Url]
    public string $status = '';

    public ?int $selectedWebhookId = null;

    public bool $showWebhookModal = false;

    public bool $showCreateModal = false;

    public bool $showDeliveriesModal = false;

    public bool $showSecretModal = false;

    // Create form fields
    public ?int $createVendorId = null;

    public string $createProvider = UptelligenceWebhook::PROVIDER_GITHUB;

    // Displayed secret after creation/regeneration
    public ?string $displaySecret = null;

    public function mount(): void
    {
        $this->checkHadesAccess();
    }

    #[Computed]
    public function webhooks(): \Illuminate\Pagination\LengthAwarePaginator
    {
        return UptelligenceWebhook::query()
            ->with('vendor')
            ->withCount(['deliveries', 'deliveries as recent_deliveries_count' => fn ($q) => $q->recent(24)])
            ->when($this->vendorId, fn ($q) => $q->where('vendor_id', $this->vendorId))
            ->when($this->provider, fn ($q) => $q->where('provider', $this->provider))
            ->when($this->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->status === 'disabled', fn ($q) => $q->where('is_active', false))
            ->latest()
            ->paginate(15);
    }

    #[Computed]
    public function vendors(): \Illuminate\Database\Eloquent\Collection
    {
        return Vendor::orderBy('name')->get(['id', 'name', 'slug']);
    }

    #[Computed]
    public function selectedWebhook(): ?UptelligenceWebhook
    {
        if (! $this->selectedWebhookId) {
            return null;
        }

        return UptelligenceWebhook::with('vendor')
            ->withCount(['deliveries', 'deliveries as recent_deliveries_count' => fn ($q) => $q->recent(24)])
            ->find($this->selectedWebhookId);
    }

    #[Computed]
    public function selectedWebhookDeliveries(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->selectedWebhookId) {
            return collect();
        }

        return UptelligenceWebhookDelivery::where('webhook_id', $this->selectedWebhookId)
            ->latest()
            ->take(20)
            ->get();
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    public function selectWebhook(int $webhookId): void
    {
        $this->selectedWebhookId = $webhookId;
        $this->showWebhookModal = true;
        $this->displaySecret = null;
        unset($this->selectedWebhook, $this->selectedWebhookDeliveries);
    }

    public function closeWebhookModal(): void
    {
        $this->showWebhookModal = false;
        $this->selectedWebhookId = null;
        $this->displaySecret = null;
    }

    public function openCreateModal(): void
    {
        $this->createVendorId = $this->vendorId;
        $this->createProvider = UptelligenceWebhook::PROVIDER_GITHUB;
        $this->showCreateModal = true;
    }

    public function closeCreateModal(): void
    {
        $this->showCreateModal = false;
        $this->createVendorId = null;
        $this->displaySecret = null;
    }

    public function createWebhook(): void
    {
        $this->validate([
            'createVendorId' => 'required|exists:vendors,id',
            'createProvider' => 'required|in:'.implode(',', UptelligenceWebhook::PROVIDERS),
        ]);

        // Check if webhook already exists for this vendor/provider
        $existing = UptelligenceWebhook::where('vendor_id', $this->createVendorId)
            ->where('provider', $this->createProvider)
            ->first();

        if ($existing) {
            $this->addError('createVendorId', 'A webhook for this vendor and provider already exists.');

            return;
        }

        $webhook = UptelligenceWebhook::create([
            'vendor_id' => $this->createVendorId,
            'provider' => $this->createProvider,
        ]);

        // Show the secret to the user
        $this->displaySecret = $webhook->secret;
        $this->showSecretModal = true;
        $this->showCreateModal = false;

        // Select the new webhook
        $this->selectedWebhookId = $webhook->id;

        unset($this->webhooks);
    }

    public function toggleActive(int $webhookId): void
    {
        $webhook = UptelligenceWebhook::findOrFail($webhookId);
        $webhook->update(['is_active' => ! $webhook->is_active]);

        // Reset failure count when re-enabling
        if ($webhook->is_active) {
            $webhook->resetFailureCount();
        }

        unset($this->webhooks, $this->selectedWebhook);
    }

    public function regenerateSecret(int $webhookId): void
    {
        $webhook = UptelligenceWebhook::findOrFail($webhookId);
        $this->displaySecret = $webhook->rotateSecret();
        $this->showSecretModal = true;
        unset($this->selectedWebhook);
    }

    public function closeSecretModal(): void
    {
        $this->showSecretModal = false;
        $this->displaySecret = null;
    }

    public function viewDeliveries(int $webhookId): void
    {
        $this->selectedWebhookId = $webhookId;
        $this->showDeliveriesModal = true;
        unset($this->selectedWebhookDeliveries);
    }

    public function closeDeliveriesModal(): void
    {
        $this->showDeliveriesModal = false;
    }

    public function retryDelivery(int $deliveryId): void
    {
        $delivery = UptelligenceWebhookDelivery::findOrFail($deliveryId);

        if ($delivery->canRetry()) {
            $delivery->scheduleRetry();
            \Core\Mod\Uptelligence\Jobs\ProcessUptelligenceWebhook::dispatch($delivery);
        }

        unset($this->selectedWebhookDeliveries);
    }

    public function deleteWebhook(int $webhookId): void
    {
        $webhook = UptelligenceWebhook::findOrFail($webhookId);
        $webhook->delete();

        $this->closeWebhookModal();
        unset($this->webhooks);
    }

    public function updatedVendorId(): void
    {
        $this->resetPage();
    }

    public function updatedProvider(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
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
        return view('uptelligence::admin.webhook-manager')
            ->layout('hub::admin.layouts.app', ['title' => 'Webhook Manager']);
    }
}
