<admin:module title="Uptelligence" subtitle="Upstream vendor tracking and todo management">
    <x-slot:actions>
        <div class="flex items-center gap-2">
            <core:button href="{{ route('hub.admin.uptelligence.vendors') }}" wire:navigate variant="primary" icon="building-office" size="sm">
                Manage Vendors
            </core:button>
            <core:button wire:click="refresh" icon="arrow-path" size="sm" variant="ghost">
                Refresh
            </core:button>
            <flux:dropdown>
                <flux:button icon="ellipsis-vertical" variant="ghost" size="sm" />
                <flux:menu>
                    <flux:menu.item href="{{ route('hub.admin.uptelligence.todos') }}" wire:navigate icon="clipboard-document-list">
                        View Todos
                    </flux:menu.item>
                    <flux:menu.item href="{{ route('hub.admin.uptelligence.diffs') }}" wire:navigate icon="document-magnifying-glass">
                        View Diffs
                    </flux:menu.item>
                    <flux:menu.item href="{{ route('hub.admin.uptelligence.assets') }}" wire:navigate icon="cube">
                        Manage Assets
                    </flux:menu.item>
                    <flux:menu.separator />
                    <flux:menu.item href="{{ route('hub.admin.uptelligence.webhooks') }}" wire:navigate icon="globe-alt">
                        Webhook Manager
                    </flux:menu.item>
                    <flux:menu.item href="{{ route('hub.admin.uptelligence.digests') }}" wire:navigate icon="envelope">
                        Digest Preferences
                    </flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </x-slot:actions>

    {{-- Summary Stats --}}
    <admin:stats :items="$this->statCards" />

    {{-- Secondary Stats Row --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <flux:card class="p-4">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900/30">
                    <flux:icon name="arrow-path" class="size-5 text-blue-600 dark:text-blue-400" />
                </div>
                <div>
                    <flux:subheading>In Progress</flux:subheading>
                    <flux:heading>{{ $this->stats['in_progress'] }}</flux:heading>
                </div>
            </div>
        </flux:card>

        <flux:card class="p-4">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-purple-100 dark:bg-purple-900/30">
                    <flux:icon name="cube" class="size-5 text-purple-600 dark:text-purple-400" />
                </div>
                <div>
                    <flux:subheading>Assets Tracked</flux:subheading>
                    <flux:heading>{{ $this->stats['assets_tracked'] }}</flux:heading>
                </div>
            </div>
        </flux:card>

        <flux:card class="p-4">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-orange-100 dark:bg-orange-900/30">
                    <flux:icon name="arrow-up-circle" class="size-5 text-orange-600 dark:text-orange-400" />
                </div>
                <div>
                    <flux:subheading>Assets Need Update</flux:subheading>
                    <flux:heading>{{ $this->stats['assets_need_update'] }}</flux:heading>
                </div>
            </div>
        </flux:card>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Vendors Summary --}}
        <flux:card class="p-0 overflow-hidden">
            <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                <div>
                    <flux:heading size="lg">Top Vendors</flux:heading>
                    <flux:subheading>By pending todos</flux:subheading>
                </div>
                <flux:button href="{{ route('hub.admin.uptelligence.vendors') }}" wire:navigate variant="ghost" size="sm" icon-trailing="arrow-right">
                    View All
                </flux:button>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Vendor</flux:table.column>
                    <flux:table.column>Version</flux:table.column>
                    <flux:table.column align="center">Pending</flux:table.column>
                    <flux:table.column align="end">Last Checked</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->vendorSummary as $vendor)
                        <flux:table.row>
                            <flux:table.cell variant="strong">
                                <div class="flex items-center gap-2">
                                    @if($vendor['source_type'] === 'licensed')
                                        <flux:icon name="lock-closed" class="size-4 text-amber-500" />
                                    @elseif($vendor['source_type'] === 'oss')
                                        <flux:icon name="globe-alt" class="size-4 text-green-500" />
                                    @else
                                        <flux:icon name="puzzle-piece" class="size-4 text-blue-500" />
                                    @endif
                                    {{ $vendor['name'] }}
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500 font-mono text-sm">
                                {{ $vendor['current_version'] ?? 'N/A' }}
                            </flux:table.cell>
                            <flux:table.cell align="center">
                                @if($vendor['pending_todos'] > 0)
                                    <flux:badge color="{{ $vendor['pending_todos'] > 10 ? 'red' : ($vendor['pending_todos'] > 5 ? 'yellow' : 'blue') }}" size="sm">
                                        {{ $vendor['pending_todos'] }}
                                    </flux:badge>
                                @else
                                    <flux:badge color="green" size="sm">0</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end" class="text-zinc-500 text-sm">
                                {{ $vendor['last_checked'] }}
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="text-center py-8">
                                <div class="flex flex-col items-center gap-2 text-zinc-500">
                                    <flux:icon name="building-office" class="size-8 opacity-50" />
                                    <span>No vendors tracked yet</span>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>

        {{-- Todos by Type --}}
        <flux:card class="p-0 overflow-hidden">
            <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                <div>
                    <flux:heading size="lg">Todos by Type</flux:heading>
                    <flux:subheading>Pending items breakdown</flux:subheading>
                </div>
                <flux:button href="{{ route('hub.admin.uptelligence.todos') }}" wire:navigate variant="ghost" size="sm" icon-trailing="arrow-right">
                    View All
                </flux:button>
            </div>

            <div class="p-4 space-y-3">
                @php
                    $typeConfig = [
                        'feature' => ['icon' => 'sparkles', 'color' => 'blue', 'label' => 'Features'],
                        'bugfix' => ['icon' => 'bug-ant', 'color' => 'yellow', 'label' => 'Bug Fixes'],
                        'security' => ['icon' => 'shield-check', 'color' => 'red', 'label' => 'Security'],
                        'ui' => ['icon' => 'paint-brush', 'color' => 'purple', 'label' => 'UI Changes'],
                        'api' => ['icon' => 'code-bracket', 'color' => 'cyan', 'label' => 'API Changes'],
                        'refactor' => ['icon' => 'arrow-path-rounded-square', 'color' => 'green', 'label' => 'Refactors'],
                        'dependency' => ['icon' => 'cube', 'color' => 'orange', 'label' => 'Dependencies'],
                        'block' => ['icon' => 'square-3-stack-3d', 'color' => 'pink', 'label' => 'Blocks'],
                    ];
                @endphp

                @forelse ($this->todosByType as $type => $count)
                    @php $config = $typeConfig[$type] ?? ['icon' => 'document', 'color' => 'zinc', 'label' => ucfirst($type)]; @endphp
                    <div class="flex items-center justify-between p-2 rounded-lg bg-zinc-50 dark:bg-zinc-800/50">
                        <div class="flex items-center gap-2">
                            <flux:icon name="{{ $config['icon'] }}" class="size-5 text-{{ $config['color'] }}-500" />
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $config['label'] }}</span>
                        </div>
                        <flux:badge color="{{ $config['color'] }}" size="sm">{{ $count }}</flux:badge>
                    </div>
                @empty
                    <div class="text-center py-8 text-zinc-500">
                        <flux:icon name="clipboard-document-list" class="size-8 opacity-50 mx-auto mb-2" />
                        <span>No pending todos</span>
                    </div>
                @endforelse
            </div>
        </flux:card>
    </div>

    {{-- Recent Todos --}}
    <flux:card class="p-0 overflow-hidden mt-6">
        <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
            <div>
                <flux:heading size="lg">Recent High-Priority Todos</flux:heading>
                <flux:subheading>Pending items ordered by priority</flux:subheading>
            </div>
            <flux:button href="{{ route('hub.admin.uptelligence.todos') }}?status=pending" wire:navigate variant="ghost" size="sm" icon-trailing="arrow-right">
                View All Pending
            </flux:button>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>Todo</flux:table.column>
                <flux:table.column>Vendor</flux:table.column>
                <flux:table.column>Type</flux:table.column>
                <flux:table.column align="center">Priority</flux:table.column>
                <flux:table.column align="center">Effort</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->recentTodos as $todo)
                    <flux:table.row>
                        <flux:table.cell variant="strong" class="max-w-xs truncate">
                            {{ $todo->title }}
                            @if($todo->isQuickWin())
                                <flux:badge color="green" size="sm" class="ml-1">Quick Win</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-zinc-500">
                            {{ $todo->vendor->name }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <span class="text-sm">{{ $todo->getTypeIcon() }} {{ ucfirst($todo->type) }}</span>
                        </flux:table.cell>
                        <flux:table.cell align="center">
                            <flux:badge color="{{ $todo->priority >= 8 ? 'red' : ($todo->priority >= 6 ? 'orange' : ($todo->priority >= 4 ? 'yellow' : 'zinc')) }}" size="sm">
                                {{ $todo->getPriorityLabel() }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="center" class="text-zinc-500 text-sm">
                            {{ $todo->getEffortLabel() }}
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center py-8">
                            <div class="flex flex-col items-center gap-2 text-zinc-500">
                                <flux:icon name="check-circle" class="size-8 opacity-50" />
                                <span>No pending todos</span>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    {{-- Recent Releases --}}
    @if($this->recentReleases->isNotEmpty())
        <flux:card class="p-0 overflow-hidden mt-6">
            <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                <div>
                    <flux:heading size="lg">Recent Version Releases</flux:heading>
                    <flux:subheading>Latest analysed vendor updates</flux:subheading>
                </div>
                <flux:button href="{{ route('hub.admin.uptelligence.diffs') }}" wire:navigate variant="ghost" size="sm" icon-trailing="arrow-right">
                    View Diffs
                </flux:button>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Vendor</flux:table.column>
                    <flux:table.column>Version</flux:table.column>
                    <flux:table.column align="center">Changes</flux:table.column>
                    <flux:table.column align="center">Impact</flux:table.column>
                    <flux:table.column align="end">Analysed</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->recentReleases as $release)
                        <flux:table.row>
                            <flux:table.cell variant="strong">
                                {{ $release->vendor->name }}
                            </flux:table.cell>
                            <flux:table.cell class="font-mono text-sm">
                                {{ $release->getVersionCompare() }}
                            </flux:table.cell>
                            <flux:table.cell align="center">
                                <div class="flex items-center justify-center gap-1 text-sm">
                                    <span class="text-green-600">+{{ $release->files_added }}</span>
                                    <span class="text-blue-600">~{{ $release->files_modified }}</span>
                                    <span class="text-red-600">-{{ $release->files_removed }}</span>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell align="center">
                                <flux:badge class="{{ $release->getImpactBadgeClass() }}" size="sm">
                                    {{ ucfirst($release->getImpactLevel()) }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell align="end" class="text-zinc-500 text-sm">
                                {{ $release->analyzed_at?->diffForHumans() ?? 'Pending' }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif
</admin:module>
