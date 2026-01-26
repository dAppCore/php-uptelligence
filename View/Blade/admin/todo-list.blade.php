<admin:module title="Upstream Todos" subtitle="Manage porting tasks from vendor updates">
    <x-slot:actions>
        <div class="flex items-center gap-2">
            @if(count($selectedTodos) > 0)
                <flux:dropdown>
                    <flux:button variant="filled" size="sm" icon="check-circle">
                        Bulk Actions ({{ count($selectedTodos) }})
                    </flux:button>
                    <flux:menu>
                        <flux:menu.item wire:click="bulkMarkStatus('in_progress')" icon="play">
                            Mark In Progress
                        </flux:menu.item>
                        <flux:menu.item wire:click="bulkMarkStatus('ported')" icon="check">
                            Mark Ported
                        </flux:menu.item>
                        <flux:menu.item wire:click="bulkMarkStatus('skipped')" icon="forward">
                            Mark Skipped
                        </flux:menu.item>
                        <flux:menu.separator />
                        <flux:menu.item wire:click="bulkMarkStatus('wont_port')" icon="x-mark" class="text-red-600">
                            Mark Won't Port
                        </flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            @endif
            <flux:button wire:click="resetFilters" variant="ghost" size="sm" icon="x-mark">
                Reset Filters
            </flux:button>
            <flux:button href="{{ route('hub.admin.uptelligence') }}" wire:navigate variant="ghost" size="sm" icon="arrow-left">
                Back
            </flux:button>
        </div>
    </x-slot:actions>

    {{-- Status Tabs --}}
    <div class="flex items-center gap-2 mb-4">
        <flux:button wire:click="$set('status', 'pending')" variant="{{ $status === 'pending' ? 'filled' : 'ghost' }}" size="sm">
            Pending
            <flux:badge size="sm" class="ml-1">{{ $this->todoStats['pending'] }}</flux:badge>
        </flux:button>
        <flux:button wire:click="$set('status', 'quick_wins')" variant="{{ $status === 'quick_wins' ? 'filled' : 'ghost' }}" size="sm">
            Quick Wins
            <flux:badge color="green" size="sm" class="ml-1">{{ $this->todoStats['quick_wins'] }}</flux:badge>
        </flux:button>
        <flux:button wire:click="$set('status', 'in_progress')" variant="{{ $status === 'in_progress' ? 'filled' : 'ghost' }}" size="sm">
            In Progress
            <flux:badge color="blue" size="sm" class="ml-1">{{ $this->todoStats['in_progress'] }}</flux:badge>
        </flux:button>
        <flux:button wire:click="$set('status', 'completed')" variant="{{ $status === 'completed' ? 'filled' : 'ghost' }}" size="sm">
            Completed
            <flux:badge color="zinc" size="sm" class="ml-1">{{ $this->todoStats['completed'] }}</flux:badge>
        </flux:button>
    </div>

    {{-- Filters --}}
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search todos..." icon="magnifying-glass" />

        <flux:select wire:model.live="vendorId">
            <option value="">All Vendors</option>
            @foreach($this->vendors as $vendor)
                <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="type">
            <option value="">All Types</option>
            <option value="feature">Feature</option>
            <option value="bugfix">Bug Fix</option>
            <option value="security">Security</option>
            <option value="ui">UI</option>
            <option value="api">API</option>
            <option value="refactor">Refactor</option>
            <option value="dependency">Dependency</option>
            <option value="block">Block</option>
        </flux:select>

        <flux:select wire:model.live="effort">
            <option value="">All Effort</option>
            <option value="low">Low (< 1 hour)</option>
            <option value="medium">Medium (1-4 hours)</option>
            <option value="high">High (4+ hours)</option>
        </flux:select>

        <flux:select wire:model.live="priority">
            <option value="">All Priority</option>
            <option value="critical">Critical (8-10)</option>
            <option value="high">High (6-7)</option>
            <option value="medium">Medium (4-5)</option>
            <option value="low">Low (1-3)</option>
        </flux:select>
    </div>

    <flux:card class="p-0 overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column class="w-10">
                    <flux:checkbox wire:model.live="selectAll" wire:click="toggleSelectAll" />
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'title'" :direction="$sortDir" wire:click="sortBy('title')">
                    Todo
                </flux:table.column>
                <flux:table.column>Vendor</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'type'" :direction="$sortDir" wire:click="sortBy('type')">
                    Type
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'priority'" :direction="$sortDir" wire:click="sortBy('priority')" align="center">
                    Priority
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'effort'" :direction="$sortDir" wire:click="sortBy('effort')" align="center">
                    Effort
                </flux:table.column>
                <flux:table.column align="center">Status</flux:table.column>
                <flux:table.column align="end">Actions</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->todos as $todo)
                    <flux:table.row wire:key="todo-{{ $todo->id }}" class="{{ $todo->has_conflicts ? 'bg-red-50 dark:bg-red-900/10' : '' }}">
                        <flux:table.cell>
                            <flux:checkbox wire:model.live="selectedTodos" value="{{ $todo->id }}" />
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="max-w-md">
                                <div class="font-medium text-zinc-900 dark:text-zinc-100 truncate flex items-center gap-2">
                                    {{ $todo->title }}
                                    @if($todo->isQuickWin())
                                        <flux:badge color="emerald" size="sm">Quick Win</flux:badge>
                                    @endif
                                    @if($todo->has_conflicts)
                                        <flux:icon name="exclamation-triangle" class="size-4 text-red-500" title="Has conflicts" />
                                    @endif
                                </div>
                                @if($todo->description)
                                    <div class="text-sm text-zinc-500 truncate">{{ Str::limit($todo->description, 80) }}</div>
                                @endif
                                @if($todo->files && count($todo->files) > 0)
                                    <div class="text-xs text-zinc-400 mt-1">
                                        {{ count($todo->files) }} file(s)
                                    </div>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="text-zinc-500">
                            {{ $todo->vendor->name }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <span class="flex items-center gap-1">
                                <span>{{ $todo->getTypeIcon() }}</span>
                                <span class="text-sm">{{ ucfirst($todo->type) }}</span>
                            </span>
                        </flux:table.cell>
                        <flux:table.cell align="center">
                            <flux:badge color="{{ $todo->priority >= 8 ? 'red' : ($todo->priority >= 6 ? 'orange' : ($todo->priority >= 4 ? 'yellow' : 'zinc')) }}" size="sm">
                                {{ $todo->getPriorityLabel() }} ({{ $todo->priority }})
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="center">
                            <flux:badge color="{{ $todo->effort === 'low' ? 'green' : ($todo->effort === 'medium' ? 'yellow' : 'red') }}" size="sm">
                                {{ $todo->getEffortLabel() }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="center">
                            <flux:badge class="{{ $todo->getStatusBadgeClass() }}" size="sm">
                                {{ ucfirst(str_replace('_', ' ', $todo->status)) }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:dropdown>
                                <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" />
                                <flux:menu>
                                    @if($todo->isPending())
                                        <flux:menu.item wire:click="markStatus({{ $todo->id }}, 'in_progress')" icon="play">
                                            Start Progress
                                        </flux:menu.item>
                                        <flux:menu.item wire:click="markStatus({{ $todo->id }}, 'ported')" icon="check">
                                            Mark Ported
                                        </flux:menu.item>
                                        <flux:menu.item wire:click="markStatus({{ $todo->id }}, 'skipped')" icon="forward">
                                            Skip
                                        </flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item wire:click="markStatus({{ $todo->id }}, 'wont_port')" icon="x-mark" class="text-red-600">
                                            Won't Port
                                        </flux:menu.item>
                                    @elseif($todo->status === 'in_progress')
                                        <flux:menu.item wire:click="markStatus({{ $todo->id }}, 'ported')" icon="check">
                                            Mark Ported
                                        </flux:menu.item>
                                        <flux:menu.item wire:click="markStatus({{ $todo->id }}, 'skipped')" icon="forward">
                                            Skip
                                        </flux:menu.item>
                                    @endif
                                    @if($todo->github_issue_number)
                                        <flux:menu.separator />
                                        <flux:menu.item icon="arrow-top-right-on-square">
                                            View GitHub Issue
                                        </flux:menu.item>
                                    @endif
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8" class="text-center py-12">
                            <div class="flex flex-col items-center gap-2 text-zinc-500">
                                <flux:icon name="clipboard-document-list" class="size-12 opacity-50" />
                                <span class="text-lg">No todos found</span>
                                <span class="text-sm">Try adjusting your filters</span>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        @if($this->todos->hasPages())
            <div class="p-4 border-t border-zinc-200 dark:border-zinc-700">
                {{ $this->todos->links() }}
            </div>
        @endif
    </flux:card>
</admin:module>
