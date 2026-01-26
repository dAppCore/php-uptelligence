<admin:module title="Asset Manager" subtitle="Track installed packages, fonts, themes, and CDN resources">
    <x-slot:actions>
        <div class="flex items-center gap-2">
            <flux:button wire:click="resetFilters" variant="ghost" size="sm" icon="x-mark">
                Reset Filters
            </flux:button>
            <flux:button href="{{ route('hub.admin.uptelligence') }}" wire:navigate variant="ghost" size="sm" icon="arrow-left">
                Back
            </flux:button>
        </div>
    </x-slot:actions>

    {{-- Stats Summary --}}
    <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mb-6">
        <flux:card class="p-4">
            <div class="text-sm text-zinc-500">Total Assets</div>
            <div class="text-2xl font-bold">{{ $this->assetStats['total'] }}</div>
        </flux:card>
        <flux:card class="p-4">
            <div class="text-sm text-zinc-500">Need Update</div>
            <div class="text-2xl font-bold text-orange-600">{{ $this->assetStats['needs_update'] }}</div>
        </flux:card>
        <flux:card class="p-4">
            <div class="text-sm text-zinc-500">Composer</div>
            <div class="text-2xl font-bold text-blue-600">{{ $this->assetStats['composer'] }}</div>
        </flux:card>
        <flux:card class="p-4">
            <div class="text-sm text-zinc-500">NPM</div>
            <div class="text-2xl font-bold text-green-600">{{ $this->assetStats['npm'] }}</div>
        </flux:card>
        <flux:card class="p-4">
            <div class="text-sm text-zinc-500">Expiring Soon</div>
            <div class="text-2xl font-bold text-yellow-600">{{ $this->assetStats['expiring_soon'] }}</div>
        </flux:card>
        <flux:card class="p-4">
            <div class="text-sm text-zinc-500">Expired</div>
            <div class="text-2xl font-bold text-red-600">{{ $this->assetStats['expired'] }}</div>
        </flux:card>
    </div>

    {{-- Filters --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search assets..." icon="magnifying-glass" />

        <flux:select wire:model.live="type">
            <option value="">All Types</option>
            <option value="composer">Composer</option>
            <option value="npm">NPM</option>
            <option value="font">Font</option>
            <option value="theme">Theme</option>
            <option value="cdn">CDN</option>
            <option value="manual">Manual</option>
        </flux:select>

        <flux:select wire:model.live="licenceType">
            <option value="">All Licences</option>
            <option value="lifetime">Lifetime</option>
            <option value="subscription">Subscription</option>
            <option value="oss">Open Source</option>
            <option value="trial">Trial</option>
        </flux:select>

        <div class="flex items-center">
            <flux:checkbox wire:model.live="needsUpdate" label="Needs Update Only" />
        </div>
    </div>

    <flux:card class="p-0 overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDir" wire:click="sortBy('name')">
                    Asset
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'type'" :direction="$sortDir" wire:click="sortBy('type')">
                    Type
                </flux:table.column>
                <flux:table.column>Installed</flux:table.column>
                <flux:table.column>Latest</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'licence_type'" :direction="$sortDir" wire:click="sortBy('licence_type')">
                    Licence
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'last_checked_at'" :direction="$sortDir" wire:click="sortBy('last_checked_at')">
                    Last Checked
                </flux:table.column>
                <flux:table.column align="center">Status</flux:table.column>
                <flux:table.column align="end">Actions</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->assets as $asset)
                    <flux:table.row wire:key="asset-{{ $asset->id }}" class="{{ $asset->isLicenceExpired() ? 'bg-red-50 dark:bg-red-900/10' : ($asset->hasUpdate() ? 'bg-orange-50 dark:bg-orange-900/10' : '') }}">
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <span>{{ $asset->getTypeIcon() }}</span>
                                <div>
                                    <div class="font-medium">{{ $asset->name }}</div>
                                    @if($asset->package_name)
                                        <div class="text-xs text-zinc-500 font-mono">{{ $asset->package_name }}</div>
                                    @endif
                                </div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ match($asset->type) {
                                'composer' => 'blue',
                                'npm' => 'green',
                                'font' => 'purple',
                                'theme' => 'pink',
                                'cdn' => 'cyan',
                                default => 'zinc'
                            } }}" size="sm">
                                {{ $asset->getTypeLabel() }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="font-mono text-sm">
                            {{ $asset->installed_version ?? 'N/A' }}
                        </flux:table.cell>
                        <flux:table.cell class="font-mono text-sm">
                            @if($asset->hasUpdate())
                                <span class="text-orange-600 font-semibold">{{ $asset->latest_version }}</span>
                            @else
                                {{ $asset->latest_version ?? 'N/A' }}
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center gap-1">
                                <span>{{ $asset->getLicenceIcon() }}</span>
                                <span class="text-sm">{{ ucfirst($asset->licence_type ?? 'N/A') }}</span>
                            </div>
                            @if($asset->licence_expires_at)
                                <div class="text-xs {{ $asset->isLicenceExpired() ? 'text-red-600' : ($asset->isLicenceExpiringSoon() ? 'text-yellow-600' : 'text-zinc-500') }}">
                                    {{ $asset->licence_expires_at->format('d M Y') }}
                                </div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-zinc-500 text-sm">
                            {{ $asset->last_checked_at?->diffForHumans() ?? 'Never' }}
                        </flux:table.cell>
                        <flux:table.cell align="center">
                            @if($asset->isLicenceExpired())
                                <flux:badge color="red" size="sm">Expired</flux:badge>
                            @elseif($asset->hasUpdate())
                                <flux:badge color="orange" size="sm">Update Available</flux:badge>
                            @elseif($asset->isLicenceExpiringSoon())
                                <flux:badge color="yellow" size="sm">Expiring Soon</flux:badge>
                            @elseif($asset->is_active)
                                <flux:badge color="green" size="sm">Active</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:dropdown>
                                <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" />
                                <flux:menu>
                                    @if($asset->getUpdateCommand())
                                        <flux:menu.item icon="clipboard-document">
                                            Copy Update Command
                                        </flux:menu.item>
                                    @endif
                                    @if($asset->registry_url)
                                        <flux:menu.item href="{{ $asset->registry_url }}" target="_blank" icon="arrow-top-right-on-square">
                                            View in Registry
                                        </flux:menu.item>
                                    @endif
                                    <flux:menu.separator />
                                    <flux:menu.item wire:click="toggleActive({{ $asset->id }})" icon="{{ $asset->is_active ? 'pause' : 'play' }}">
                                        {{ $asset->is_active ? 'Deactivate' : 'Activate' }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8" class="text-center py-12">
                            <div class="flex flex-col items-center gap-2 text-zinc-500">
                                <flux:icon name="cube" class="size-12 opacity-50" />
                                <span class="text-lg">No assets found</span>
                                <span class="text-sm">Try adjusting your filters</span>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        @if($this->assets->hasPages())
            <div class="p-4 border-t border-zinc-200 dark:border-zinc-700">
                {{ $this->assets->links() }}
            </div>
        @endif
    </flux:card>
</admin:module>
