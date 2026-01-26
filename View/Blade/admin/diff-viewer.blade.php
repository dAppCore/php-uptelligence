<admin:module title="Diff Viewer" subtitle="View file changes between vendor versions">
    <x-slot:actions>
        <div class="flex items-center gap-2">
            <flux:button href="{{ route('hub.admin.uptelligence') }}" wire:navigate variant="ghost" size="sm" icon="arrow-left">
                Back
            </flux:button>
        </div>
    </x-slot:actions>

    {{-- Vendor and Release Selection --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <flux:select wire:model.live="vendorId" label="Select Vendor">
            <option value="">Choose a vendor...</option>
            @foreach($this->vendors as $vendor)
                <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
            @endforeach
        </flux:select>

        @if($this->releases->isNotEmpty())
            <flux:select wire:model.live="releaseId" label="Select Release">
                <option value="">Choose a release...</option>
                @foreach($this->releases as $release)
                    <option value="{{ $release->id }}">
                        {{ $release->getVersionCompare() }} - {{ $release->analyzed_at?->format('d M Y') }}
                    </option>
                @endforeach
            </flux:select>
        @else
            <div class="flex items-end">
                <div class="p-3 bg-zinc-100 dark:bg-zinc-800 rounded-lg text-zinc-500 text-sm w-full">
                    Select a vendor to view available releases
                </div>
            </div>
        @endif
    </div>

    @if($this->selectedRelease)
        {{-- Release Summary --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <flux:card class="p-4">
                <div class="text-sm text-zinc-500">Total Changes</div>
                <div class="text-2xl font-bold">{{ $this->diffStats['total'] }}</div>
            </flux:card>
            <flux:card class="p-4">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-zinc-500">Added</div>
                    <flux:badge color="green" size="sm">{{ $this->diffStats['added'] }}</flux:badge>
                </div>
                <div class="text-2xl font-bold text-green-600">+{{ $this->diffStats['added'] }}</div>
            </flux:card>
            <flux:card class="p-4">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-zinc-500">Modified</div>
                    <flux:badge color="blue" size="sm">{{ $this->diffStats['modified'] }}</flux:badge>
                </div>
                <div class="text-2xl font-bold text-blue-600">~{{ $this->diffStats['modified'] }}</div>
            </flux:card>
            <flux:card class="p-4">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-zinc-500">Removed</div>
                    <flux:badge color="red" size="sm">{{ $this->diffStats['removed'] }}</flux:badge>
                </div>
                <div class="text-2xl font-bold text-red-600">-{{ $this->diffStats['removed'] }}</div>
            </flux:card>
        </div>

        {{-- Category Breakdown --}}
        @if(count($this->diffStats['by_category']) > 0)
            <div class="mb-6">
                <flux:heading size="sm" class="mb-3">Changes by Category</flux:heading>
                <div class="flex flex-wrap gap-2">
                    @foreach($this->diffStats['by_category'] as $cat => $count)
                        <flux:button
                            wire:click="$set('category', '{{ $category === $cat ? '' : $cat }}')"
                            variant="{{ $category === $cat ? 'filled' : 'ghost' }}"
                            size="sm"
                        >
                            {{ ucfirst($cat) }}
                            <flux:badge size="sm" class="ml-1">{{ $count }}</flux:badge>
                        </flux:button>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Filter by Change Type --}}
        <div class="flex items-center gap-2 mb-4">
            <span class="text-sm text-zinc-500">Filter:</span>
            <flux:button wire:click="$set('changeType', '')" variant="{{ $changeType === '' ? 'filled' : 'ghost' }}" size="sm">
                All
            </flux:button>
            <flux:button wire:click="$set('changeType', 'added')" variant="{{ $changeType === 'added' ? 'filled' : 'ghost' }}" size="sm">
                <span class="text-green-600">Added</span>
            </flux:button>
            <flux:button wire:click="$set('changeType', 'modified')" variant="{{ $changeType === 'modified' ? 'filled' : 'ghost' }}" size="sm">
                <span class="text-blue-600">Modified</span>
            </flux:button>
            <flux:button wire:click="$set('changeType', 'removed')" variant="{{ $changeType === 'removed' ? 'filled' : 'ghost' }}" size="sm">
                <span class="text-red-600">Removed</span>
            </flux:button>
        </div>

        {{-- Diffs Table --}}
        <flux:card class="p-0 overflow-hidden">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column class="w-10">Type</flux:table.column>
                    <flux:table.column>File Path</flux:table.column>
                    <flux:table.column>Category</flux:table.column>
                    <flux:table.column align="center">Lines</flux:table.column>
                    <flux:table.column align="end">Actions</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->diffs as $diff)
                        <flux:table.row wire:key="diff-{{ $diff->id }}">
                            <flux:table.cell>
                                @if($diff->change_type === 'added')
                                    <flux:badge color="green" size="sm">+</flux:badge>
                                @elseif($diff->change_type === 'modified')
                                    <flux:badge color="blue" size="sm">~</flux:badge>
                                @else
                                    <flux:badge color="red" size="sm">-</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="font-mono text-sm">
                                    <span class="text-zinc-500">{{ $diff->getDirectory() }}/</span>{{ $diff->getFileName() }}
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center gap-1">
                                    <span>{{ $diff->getCategoryIcon() }}</span>
                                    <span class="text-sm">{{ ucfirst($diff->category) }}</span>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell align="center">
                                @if($diff->diff_content)
                                    <div class="text-sm">
                                        <span class="text-green-600">+{{ $diff->getAddedLines() }}</span>
                                        <span class="text-zinc-400">/</span>
                                        <span class="text-red-600">-{{ $diff->getRemovedLines() }}</span>
                                    </div>
                                @else
                                    <span class="text-zinc-400">-</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:button wire:click="viewDiff({{ $diff->id }})" variant="ghost" size="sm" icon="eye">
                                    View
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="text-center py-12">
                                <div class="flex flex-col items-center gap-2 text-zinc-500">
                                    <flux:icon name="document-magnifying-glass" class="size-12 opacity-50" />
                                    <span class="text-lg">No diffs found</span>
                                    <span class="text-sm">Select a release to view file changes</span>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            @if($this->diffs->hasPages())
                <div class="p-4 border-t border-zinc-200 dark:border-zinc-700">
                    {{ $this->diffs->links() }}
                </div>
            @endif
        </flux:card>
    @else
        <flux:card class="p-12">
            <div class="flex flex-col items-center justify-center text-center">
                <div class="w-16 h-16 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center mb-4">
                    <flux:icon name="document-magnifying-glass" class="size-8 text-blue-500" />
                </div>
                <flux:heading size="lg">Select a Vendor and Release</flux:heading>
                <flux:subheading class="mt-1">Choose a vendor and release version to view file diffs.</flux:subheading>
            </div>
        </flux:card>
    @endif

    {{-- Diff Detail Modal --}}
    <flux:modal wire:model="showDiffModal" name="diff-detail" class="max-w-5xl">
        @if($this->selectedDiff)
            <div class="space-y-4">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <span>{{ $this->selectedDiff->getChangeTypeIcon() }}</span>
                            <flux:heading size="lg" class="font-mono">{{ $this->selectedDiff->getFileName() }}</flux:heading>
                        </div>
                        <flux:subheading class="font-mono text-sm">{{ $this->selectedDiff->file_path }}</flux:subheading>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:badge class="{{ $this->selectedDiff->getChangeTypeBadgeClass() }}">
                            {{ ucfirst($this->selectedDiff->change_type) }}
                        </flux:badge>
                        <flux:badge color="zinc">{{ ucfirst($this->selectedDiff->category) }}</flux:badge>
                    </div>
                </div>

                @if($this->selectedDiff->diff_content)
                    <div class="bg-zinc-900 rounded-lg overflow-hidden">
                        <div class="p-3 border-b border-zinc-700 flex items-center justify-between">
                            <span class="text-sm text-zinc-400">Unified Diff</span>
                            <div class="flex items-center gap-3 text-sm">
                                <span class="text-green-400">+{{ $this->selectedDiff->getAddedLines() }} added</span>
                                <span class="text-red-400">-{{ $this->selectedDiff->getRemovedLines() }} removed</span>
                            </div>
                        </div>
                        <pre class="p-4 overflow-x-auto text-sm font-mono max-h-[60vh] overflow-y-auto"><code class="language-diff">{{ $this->selectedDiff->diff_content }}</code></pre>
                    </div>
                @elseif($this->selectedDiff->new_content)
                    <div class="bg-zinc-900 rounded-lg overflow-hidden">
                        <div class="p-3 border-b border-zinc-700">
                            <span class="text-sm text-zinc-400">New File Content</span>
                        </div>
                        <pre class="p-4 overflow-x-auto text-sm font-mono max-h-[60vh] overflow-y-auto text-zinc-300"><code>{{ $this->selectedDiff->new_content }}</code></pre>
                    </div>
                @else
                    <div class="p-8 text-center text-zinc-500 bg-zinc-100 dark:bg-zinc-800 rounded-lg">
                        <flux:icon name="document" class="size-12 opacity-50 mx-auto mb-2" />
                        <p>No content available for this file change.</p>
                        @if($this->selectedDiff->change_type === 'removed')
                            <p class="text-sm mt-1">This file was removed in the new version.</p>
                        @endif
                    </div>
                @endif

                <div class="flex justify-end gap-2 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                    <flux:button wire:click="closeDiffModal" variant="ghost">Close</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</admin:module>
