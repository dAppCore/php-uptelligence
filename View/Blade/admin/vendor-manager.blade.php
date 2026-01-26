<admin:module title="Vendor Manager" subtitle="Track and manage upstream software vendors">
    <x-slot:actions>
        <div class="flex items-center gap-2">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search vendors..." icon="magnifying-glass" size="sm" class="w-64" />
            <flux:select wire:model.live="sourceType" size="sm">
                <option value="">All Types</option>
                <option value="licensed">Licensed</option>
                <option value="oss">Open Source</option>
                <option value="plugin">Plugin</option>
            </flux:select>
            <flux:button href="{{ route('hub.admin.uptelligence') }}" wire:navigate variant="ghost" size="sm" icon="arrow-left">
                Back
            </flux:button>
        </div>
    </x-slot:actions>

    <flux:card class="p-0 overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDir" wire:click="sortBy('name')">
                    Vendor
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'source_type'" :direction="$sortDir" wire:click="sortBy('source_type')">
                    Type
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'current_version'" :direction="$sortDir" wire:click="sortBy('current_version')">
                    Current Version
                </flux:table.column>
                <flux:table.column align="center">Pending Todos</flux:table.column>
                <flux:table.column align="center">Quick Wins</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'last_checked_at'" :direction="$sortDir" wire:click="sortBy('last_checked_at')">
                    Last Checked
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'is_active'" :direction="$sortDir" wire:click="sortBy('is_active')">
                    Status
                </flux:table.column>
                <flux:table.column align="end">Actions</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->vendors as $vendor)
                    <flux:table.row wire:key="vendor-{{ $vendor->id }}">
                        <flux:table.cell variant="strong">
                            <div class="flex items-center gap-2">
                                @if($vendor->source_type === 'licensed')
                                    <flux:icon name="lock-closed" class="size-4 text-amber-500" />
                                @elseif($vendor->source_type === 'oss')
                                    <flux:icon name="globe-alt" class="size-4 text-green-500" />
                                @else
                                    <flux:icon name="puzzle-piece" class="size-4 text-blue-500" />
                                @endif
                                <div>
                                    <div>{{ $vendor->name }}</div>
                                    <div class="text-xs text-zinc-500">{{ $vendor->slug }}</div>
                                </div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $vendor->source_type === 'licensed' ? 'amber' : ($vendor->source_type === 'oss' ? 'green' : 'blue') }}" size="sm">
                                {{ $vendor->getSourceTypeLabel() }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="font-mono text-sm">
                            {{ $vendor->current_version ?? 'N/A' }}
                        </flux:table.cell>
                        <flux:table.cell align="center">
                            @if($vendor->pending_todos_count > 0)
                                <flux:badge color="{{ $vendor->pending_todos_count > 10 ? 'red' : ($vendor->pending_todos_count > 5 ? 'yellow' : 'blue') }}" size="sm">
                                    {{ $vendor->pending_todos_count }}
                                </flux:badge>
                            @else
                                <flux:badge color="green" size="sm">0</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="center">
                            @if($vendor->quick_wins_count > 0)
                                <flux:badge color="emerald" size="sm">{{ $vendor->quick_wins_count }}</flux:badge>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-zinc-500 text-sm">
                            {{ $vendor->last_checked_at?->diffForHumans() ?? 'Never' }}
                        </flux:table.cell>
                        <flux:table.cell>
                            @if($vendor->is_active)
                                <flux:badge color="green" size="sm">Active</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button wire:click="selectVendor({{ $vendor->id }})" variant="ghost" size="sm" icon="eye" />
                                <flux:dropdown>
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" />
                                    <flux:menu>
                                        <flux:menu.item wire:click="selectVendor({{ $vendor->id }})" icon="eye">
                                            View Details
                                        </flux:menu.item>
                                        <flux:menu.item href="{{ route('hub.admin.uptelligence.todos') }}?vendorId={{ $vendor->id }}" wire:navigate icon="clipboard-document-list">
                                            View Todos
                                        </flux:menu.item>
                                        <flux:menu.item href="{{ route('hub.admin.uptelligence.diffs') }}?vendorId={{ $vendor->id }}" wire:navigate icon="document-magnifying-glass">
                                            View Diffs
                                        </flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item wire:click="toggleActive({{ $vendor->id }})" icon="{{ $vendor->is_active ? 'pause' : 'play' }}">
                                            {{ $vendor->is_active ? 'Deactivate' : 'Activate' }}
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8" class="text-center py-12">
                            <div class="flex flex-col items-center gap-2 text-zinc-500">
                                <flux:icon name="building-office" class="size-12 opacity-50" />
                                <span class="text-lg">No vendors found</span>
                                <span class="text-sm">Try adjusting your search or filters</span>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        @if($this->vendors->hasPages())
            <div class="p-4 border-t border-zinc-200 dark:border-zinc-700">
                {{ $this->vendors->links() }}
            </div>
        @endif
    </flux:card>

    {{-- Vendor Detail Modal --}}
    <flux:modal wire:model="showVendorModal" name="vendor-detail" class="max-w-3xl">
        @if($this->selectedVendor)
            <div class="space-y-6">
                <div class="flex items-start justify-between">
                    <div>
                        <flux:heading size="xl">{{ $this->selectedVendor->name }}</flux:heading>
                        <flux:subheading>{{ $this->selectedVendor->vendor_name ?? $this->selectedVendor->slug }}</flux:subheading>
                    </div>
                    <flux:badge color="{{ $this->selectedVendor->source_type === 'licensed' ? 'amber' : ($this->selectedVendor->source_type === 'oss' ? 'green' : 'blue') }}">
                        {{ $this->selectedVendor->getSourceTypeLabel() }}
                    </flux:badge>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="p-4 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg">
                        <div class="text-sm text-zinc-500">Current Version</div>
                        <div class="text-lg font-mono">{{ $this->selectedVendor->current_version ?? 'N/A' }}</div>
                    </div>
                    <div class="p-4 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg">
                        <div class="text-sm text-zinc-500">Previous Version</div>
                        <div class="text-lg font-mono">{{ $this->selectedVendor->previous_version ?? 'N/A' }}</div>
                    </div>
                    <div class="p-4 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg">
                        <div class="text-sm text-zinc-500">Pending Todos</div>
                        <div class="text-lg font-semibold">{{ $this->selectedVendor->pending_todos_count }}</div>
                    </div>
                    <div class="p-4 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg">
                        <div class="text-sm text-zinc-500">Last Checked</div>
                        <div class="text-lg">{{ $this->selectedVendor->last_checked_at?->format('d M Y H:i') ?? 'Never' }}</div>
                    </div>
                </div>

                @if($this->selectedVendor->git_repo_url)
                    <div class="p-4 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg">
                        <div class="text-sm text-zinc-500 mb-1">Git Repository</div>
                        <a href="{{ $this->selectedVendor->git_repo_url }}" target="_blank" class="text-blue-600 hover:underline flex items-center gap-1">
                            {{ $this->selectedVendor->git_repo_url }}
                            <flux:icon name="arrow-top-right-on-square" class="size-4" />
                        </a>
                    </div>
                @endif

                @if($this->selectedVendor->todos->isNotEmpty())
                    <div>
                        <flux:heading size="sm" class="mb-3">Recent Todos</flux:heading>
                        <div class="space-y-2">
                            @foreach($this->selectedVendor->todos as $todo)
                                <div class="p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span>{{ $todo->getTypeIcon() }}</span>
                                        <span class="font-medium truncate max-w-sm">{{ $todo->title }}</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <flux:badge color="{{ $todo->priority >= 8 ? 'red' : ($todo->priority >= 6 ? 'orange' : 'zinc') }}" size="sm">
                                            P{{ $todo->priority }}
                                        </flux:badge>
                                        <flux:badge color="{{ $todo->effort === 'low' ? 'green' : ($todo->effort === 'medium' ? 'yellow' : 'red') }}" size="sm">
                                            {{ ucfirst($todo->effort) }}
                                        </flux:badge>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if($this->selectedVendorReleases->isNotEmpty())
                    <div>
                        <flux:heading size="sm" class="mb-3">Recent Releases</flux:heading>
                        <div class="space-y-2">
                            @foreach($this->selectedVendorReleases->take(5) as $release)
                                <div class="p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg flex items-center justify-between">
                                    <div class="font-mono text-sm">{{ $release->getVersionCompare() }}</div>
                                    <div class="flex items-center gap-3 text-sm">
                                        <span class="text-green-600">+{{ $release->files_added }}</span>
                                        <span class="text-blue-600">~{{ $release->files_modified }}</span>
                                        <span class="text-red-600">-{{ $release->files_removed }}</span>
                                        <span class="text-zinc-500">{{ $release->analyzed_at?->diffForHumans() }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex justify-end gap-2 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                    <flux:button wire:click="closeVendorModal" variant="ghost">Close</flux:button>
                    <flux:button href="{{ route('hub.admin.uptelligence.todos') }}?vendorId={{ $this->selectedVendor->id }}" wire:navigate variant="primary">
                        View All Todos
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</admin:module>
