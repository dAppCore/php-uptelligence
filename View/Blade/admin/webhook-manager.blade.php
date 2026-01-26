<admin:module title="Webhook Manager" subtitle="Receive vendor release notifications via webhooks">
    <x-slot:actions>
        <div class="flex items-center gap-2">
            <flux:select wire:model.live="vendorId" size="sm" placeholder="All Vendors">
                <option value="">All Vendors</option>
                @foreach ($this->vendors as $vendor)
                    <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="provider" size="sm" placeholder="All Providers">
                <option value="">All Providers</option>
                <option value="github">GitHub</option>
                <option value="gitlab">GitLab</option>
                <option value="npm">npm</option>
                <option value="packagist">Packagist</option>
                <option value="custom">Custom</option>
            </flux:select>
            <flux:select wire:model.live="status" size="sm" placeholder="All Status">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="disabled">Disabled</option>
            </flux:select>
            <flux:button wire:click="openCreateModal" variant="primary" size="sm" icon="plus">
                New Webhook
            </flux:button>
            <flux:button href="{{ route('hub.admin.uptelligence') }}" wire:navigate variant="ghost" size="sm" icon="arrow-left">
                Back
            </flux:button>
        </div>
    </x-slot:actions>

    <flux:card class="p-0 overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Vendor</flux:table.column>
                <flux:table.column>Provider</flux:table.column>
                <flux:table.column>Endpoint URL</flux:table.column>
                <flux:table.column align="center">Deliveries (24h)</flux:table.column>
                <flux:table.column>Last Received</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column align="end">Actions</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->webhooks as $webhook)
                    <flux:table.row wire:key="webhook-{{ $webhook->id }}">
                        <flux:table.cell variant="strong">
                            <div class="flex items-center gap-2">
                                <flux:icon name="{{ $webhook->getProviderIcon() }}" class="size-4 text-zinc-500" />
                                <div>
                                    <div>{{ $webhook->vendor->name }}</div>
                                    <div class="text-xs text-zinc-500">{{ $webhook->vendor->slug }}</div>
                                </div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="zinc" size="sm">
                                {{ $webhook->getProviderLabel() }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="font-mono text-xs text-zinc-500 max-w-xs truncate">
                            {{ $webhook->getEndpointUrl() }}
                        </flux:table.cell>
                        <flux:table.cell align="center">
                            @if($webhook->recent_deliveries_count > 0)
                                <flux:badge color="blue" size="sm">{{ $webhook->recent_deliveries_count }}</flux:badge>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-zinc-500 text-sm">
                            {{ $webhook->last_received_at?->diffForHumans() ?? 'Never' }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $webhook->status_color }}" size="sm">
                                {{ $webhook->status_label }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button wire:click="selectWebhook({{ $webhook->id }})" variant="ghost" size="sm" icon="eye" />
                                <flux:dropdown>
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" />
                                    <flux:menu>
                                        <flux:menu.item wire:click="selectWebhook({{ $webhook->id }})" icon="eye">
                                            View Details
                                        </flux:menu.item>
                                        <flux:menu.item wire:click="viewDeliveries({{ $webhook->id }})" icon="inbox-stack">
                                            View Deliveries
                                        </flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item wire:click="regenerateSecret({{ $webhook->id }})" icon="key">
                                            Rotate Secret
                                        </flux:menu.item>
                                        <flux:menu.item wire:click="toggleActive({{ $webhook->id }})" icon="{{ $webhook->is_active ? 'pause' : 'play' }}">
                                            {{ $webhook->is_active ? 'Disable' : 'Enable' }}
                                        </flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item wire:click="deleteWebhook({{ $webhook->id }})" wire:confirm="Are you sure you want to delete this webhook?" icon="trash" variant="danger">
                                            Delete
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7" class="text-center py-12">
                            <div class="flex flex-col items-center gap-2 text-zinc-500">
                                <flux:icon name="globe-alt" class="size-12 opacity-50" />
                                <span class="text-lg">No webhooks configured</span>
                                <span class="text-sm">Create a webhook to receive vendor release notifications</span>
                                <flux:button wire:click="openCreateModal" variant="primary" size="sm" icon="plus" class="mt-2">
                                    Create Webhook
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        @if($this->webhooks->hasPages())
            <div class="p-4 border-t border-zinc-200 dark:border-zinc-700">
                {{ $this->webhooks->links() }}
            </div>
        @endif
    </flux:card>

    {{-- Webhook Detail Modal --}}
    <flux:modal wire:model="showWebhookModal" name="webhook-detail" class="max-w-2xl">
        @if($this->selectedWebhook)
            <div class="space-y-6">
                <div class="flex items-start justify-between">
                    <div>
                        <flux:heading size="xl">{{ $this->selectedWebhook->vendor->name }}</flux:heading>
                        <flux:subheading>{{ $this->selectedWebhook->getProviderLabel() }} Webhook</flux:subheading>
                    </div>
                    <flux:badge color="{{ $this->selectedWebhook->status_color }}">
                        {{ $this->selectedWebhook->status_label }}
                    </flux:badge>
                </div>

                <div class="p-4 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg">
                    <div class="text-sm text-zinc-500 mb-1">Webhook Endpoint URL</div>
                    <div class="flex items-center gap-2">
                        <code class="text-sm bg-zinc-100 dark:bg-zinc-800 px-2 py-1 rounded flex-1 overflow-x-auto">
                            {{ $this->selectedWebhook->getEndpointUrl() }}
                        </code>
                        <flux:button
                            variant="ghost"
                            size="sm"
                            icon="clipboard-document"
                            x-on:click="navigator.clipboard.writeText('{{ $this->selectedWebhook->getEndpointUrl() }}')"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="p-4 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg">
                        <div class="text-sm text-zinc-500">Total Deliveries</div>
                        <div class="text-lg font-semibold">{{ $this->selectedWebhook->deliveries_count }}</div>
                    </div>
                    <div class="p-4 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg">
                        <div class="text-sm text-zinc-500">Last 24 Hours</div>
                        <div class="text-lg font-semibold">{{ $this->selectedWebhook->recent_deliveries_count }}</div>
                    </div>
                    <div class="p-4 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg">
                        <div class="text-sm text-zinc-500">Last Received</div>
                        <div class="text-lg">{{ $this->selectedWebhook->last_received_at?->format('d M Y H:i') ?? 'Never' }}</div>
                    </div>
                    <div class="p-4 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg">
                        <div class="text-sm text-zinc-500">Failure Count</div>
                        <div class="text-lg font-semibold {{ $this->selectedWebhook->failure_count > 0 ? 'text-red-600' : 'text-green-600' }}">
                            {{ $this->selectedWebhook->failure_count }}
                        </div>
                    </div>
                </div>

                @if($this->selectedWebhook->isInGracePeriod())
                    <div class="p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg">
                        <div class="flex items-center gap-2 text-yellow-800 dark:text-yellow-200">
                            <flux:icon name="exclamation-triangle" class="size-5" />
                            <span class="font-medium">Secret rotation in progress</span>
                        </div>
                        <p class="text-sm text-yellow-700 dark:text-yellow-300 mt-1">
                            Both old and new secrets are accepted until {{ $this->selectedWebhook->grace_ends_at->format('d M Y H:i') }}.
                        </p>
                    </div>
                @endif

                <div class="p-4 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg">
                    <div class="text-sm text-zinc-500 mb-2">Setup Instructions</div>
                    <div class="text-sm space-y-2">
                        @if($this->selectedWebhook->provider === 'github')
                            <p>1. Go to your GitHub repository Settings &gt; Webhooks</p>
                            <p>2. Click "Add webhook"</p>
                            <p>3. Paste the endpoint URL above</p>
                            <p>4. Set Content type to <code class="bg-zinc-100 dark:bg-zinc-800 px-1 rounded">application/json</code></p>
                            <p>5. Enter your webhook secret</p>
                            <p>6. Select "Let me select individual events" and choose "Releases"</p>
                        @elseif($this->selectedWebhook->provider === 'gitlab')
                            <p>1. Go to your GitLab project Settings &gt; Webhooks</p>
                            <p>2. Enter the endpoint URL</p>
                            <p>3. Add your secret token</p>
                            <p>4. Select "Releases events" trigger</p>
                        @elseif($this->selectedWebhook->provider === 'npm')
                            <p>1. Configure your npm package hooks using <code class="bg-zinc-100 dark:bg-zinc-800 px-1 rounded">npm hook add</code></p>
                            <p>2. Use the endpoint URL and your webhook secret</p>
                        @elseif($this->selectedWebhook->provider === 'packagist')
                            <p>1. Go to your Packagist package page</p>
                            <p>2. Edit the package settings</p>
                            <p>3. Add a webhook URL pointing to this endpoint</p>
                        @else
                            <p>Configure your system to POST JSON payloads to the endpoint URL.</p>
                            <p>Include the version in your payload as <code class="bg-zinc-100 dark:bg-zinc-800 px-1 rounded">version</code>, <code class="bg-zinc-100 dark:bg-zinc-800 px-1 rounded">tag</code>, or <code class="bg-zinc-100 dark:bg-zinc-800 px-1 rounded">tag_name</code>.</p>
                            <p>Sign payloads with HMAC-SHA256 using your secret and include in <code class="bg-zinc-100 dark:bg-zinc-800 px-1 rounded">X-Signature</code> header.</p>
                        @endif
                    </div>
                </div>

                <div class="flex justify-between gap-2 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                    <flux:button wire:click="deleteWebhook({{ $this->selectedWebhook->id }})" wire:confirm="Are you sure?" variant="danger" icon="trash">
                        Delete
                    </flux:button>
                    <div class="flex gap-2">
                        <flux:button wire:click="regenerateSecret({{ $this->selectedWebhook->id }})" variant="ghost" icon="key">
                            Rotate Secret
                        </flux:button>
                        <flux:button wire:click="viewDeliveries({{ $this->selectedWebhook->id }})" variant="ghost" icon="inbox-stack">
                            View Deliveries
                        </flux:button>
                        <flux:button wire:click="closeWebhookModal" variant="primary">Close</flux:button>
                    </div>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- Create Webhook Modal --}}
    <flux:modal wire:model="showCreateModal" name="create-webhook" class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Create Webhook</flux:heading>
                <flux:subheading>Configure a new vendor release webhook</flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:select wire:model="createVendorId" label="Vendor" :error="$errors->first('createVendorId')">
                    <option value="">Select a vendor...</option>
                    @foreach ($this->vendors as $vendor)
                        <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="createProvider" label="Provider" :error="$errors->first('createProvider')">
                    <option value="github">GitHub</option>
                    <option value="gitlab">GitLab</option>
                    <option value="npm">npm</option>
                    <option value="packagist">Packagist</option>
                    <option value="custom">Custom</option>
                </flux:select>
            </div>

            <div class="flex justify-end gap-2 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                <flux:button wire:click="closeCreateModal" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="createWebhook" variant="primary">Create Webhook</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Secret Display Modal --}}
    <flux:modal wire:model="showSecretModal" name="secret-display" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Webhook Secret</flux:heading>
                <flux:subheading>Copy this secret now - it will not be shown again</flux:subheading>
            </div>

            <div class="p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg">
                <div class="flex items-center gap-2 text-amber-800 dark:text-amber-200 mb-2">
                    <flux:icon name="exclamation-triangle" class="size-5" />
                    <span class="font-medium">Important</span>
                </div>
                <p class="text-sm text-amber-700 dark:text-amber-300">
                    This is the only time you will see this secret. Copy it now and store it securely.
                </p>
            </div>

            @if($displaySecret)
                <div class="p-4 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg">
                    <div class="text-sm text-zinc-500 mb-2">Webhook Secret</div>
                    <div class="flex items-center gap-2">
                        <code class="text-sm bg-zinc-100 dark:bg-zinc-800 px-2 py-1 rounded flex-1 font-mono break-all">
                            {{ $displaySecret }}
                        </code>
                        <flux:button
                            variant="ghost"
                            size="sm"
                            icon="clipboard-document"
                            x-on:click="navigator.clipboard.writeText('{{ $displaySecret }}')"
                        />
                    </div>
                </div>
            @endif

            <div class="flex justify-end pt-4 border-t border-zinc-200 dark:border-zinc-700">
                <flux:button wire:click="closeSecretModal" variant="primary">I have copied the secret</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Deliveries Modal --}}
    <flux:modal wire:model="showDeliveriesModal" name="webhook-deliveries" class="max-w-4xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Webhook Deliveries</flux:heading>
                <flux:subheading>Recent webhook delivery history</flux:subheading>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-zinc-500 uppercase">Time</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-zinc-500 uppercase">Event</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-zinc-500 uppercase">Version</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-zinc-500 uppercase">Status</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-zinc-500 uppercase">Signature</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-zinc-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($this->selectedWebhookDeliveries as $delivery)
                            <tr wire:key="delivery-{{ $delivery->id }}">
                                <td class="px-3 py-2 text-sm text-zinc-500">
                                    {{ $delivery->created_at->format('d M H:i:s') }}
                                </td>
                                <td class="px-3 py-2">
                                    <flux:badge color="{{ $delivery->event_color }}" size="sm">
                                        {{ $delivery->event_type }}
                                    </flux:badge>
                                </td>
                                <td class="px-3 py-2 font-mono text-sm">
                                    {{ $delivery->version ?? '-' }}
                                </td>
                                <td class="px-3 py-2">
                                    <flux:badge color="{{ $delivery->status_color }}" size="sm">
                                        {{ ucfirst($delivery->status) }}
                                    </flux:badge>
                                </td>
                                <td class="px-3 py-2">
                                    <flux:badge color="{{ $delivery->signature_color }}" size="sm">
                                        {{ ucfirst($delivery->signature_status ?? 'unknown') }}
                                    </flux:badge>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    @if($delivery->canRetry())
                                        <flux:button wire:click="retryDelivery({{ $delivery->id }})" variant="ghost" size="sm" icon="arrow-path">
                                            Retry
                                        </flux:button>
                                    @endif
                                </td>
                            </tr>
                            @if($delivery->error_message)
                                <tr wire:key="delivery-error-{{ $delivery->id }}">
                                    <td colspan="6" class="px-3 py-2 bg-red-50 dark:bg-red-900/20">
                                        <div class="text-sm text-red-700 dark:text-red-300">
                                            <strong>Error:</strong> {{ $delivery->error_message }}
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="6" class="px-3 py-8 text-center text-zinc-500">
                                    No deliveries recorded yet
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end pt-4 border-t border-zinc-200 dark:border-zinc-700">
                <flux:button wire:click="closeDeliveriesModal" variant="primary">Close</flux:button>
            </div>
        </div>
    </flux:modal>
</admin:module>
