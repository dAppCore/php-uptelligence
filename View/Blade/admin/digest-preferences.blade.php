<admin:module title="Digest Preferences" subtitle="Configure email notifications for vendor updates">
    <x-slot:actions>
        <div class="flex items-center gap-2">
            <core:button href="{{ route('hub.admin.uptelligence') }}" wire:navigate variant="ghost" icon="arrow-left" size="sm">
                Back to Dashboard
            </core:button>
        </div>
    </x-slot:actions>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Settings Panel --}}
        <div class="lg:col-span-2 space-y-6">
            {{-- Enable/Disable Card --}}
            <flux:card class="p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:heading size="lg">Email Digests</flux:heading>
                        <flux:subheading>Receive periodic summaries of vendor updates and pending tasks</flux:subheading>
                    </div>
                    <flux:switch
                        wire:click="toggleEnabled"
                        :checked="$isEnabled"
                        label="{{ $isEnabled ? 'Enabled' : 'Disabled' }}"
                    />
                </div>
            </flux:card>

            {{-- Frequency Selection --}}
            <flux:card class="p-6">
                <flux:heading size="lg" class="mb-4">Frequency</flux:heading>

                <flux:radio.group wire:model.live="frequency" class="space-y-3">
                    @foreach(\Core\Uptelligence\Models\UptelligenceDigest::getFrequencyOptions() as $value => $label)
                        <flux:radio
                            value="{{ $value }}"
                            label="{{ $label }}"
                            description="{{ match($value) {
                                'daily' => 'Sent every morning at 9am UK time',
                                'weekly' => 'Sent every Monday at 9am UK time',
                                'monthly' => 'Sent on the 1st of each month at 9am UK time',
                                default => ''
                            } }}"
                        />
                    @endforeach
                </flux:radio.group>
            </flux:card>

            {{-- Content Types --}}
            <flux:card class="p-6">
                <flux:heading size="lg" class="mb-4">Include in Digest</flux:heading>

                <div class="space-y-4">
                    <flux:checkbox
                        wire:click="toggleType('releases')"
                        :checked="in_array('releases', $selectedTypes)"
                        label="New Releases"
                        description="Version updates and changelog summaries from tracked vendors"
                    />

                    <flux:checkbox
                        wire:click="toggleType('todos')"
                        :checked="in_array('todos', $selectedTypes)"
                        label="Pending Tasks"
                        description="Summary of porting tasks grouped by priority"
                    />

                    <flux:checkbox
                        wire:click="toggleType('security')"
                        :checked="in_array('security', $selectedTypes)"
                        label="Security Updates"
                        description="Highlight security-related updates that need attention"
                    />
                </div>
            </flux:card>

            {{-- Vendor Filter --}}
            <flux:card class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <flux:heading size="lg">Vendor Filter</flux:heading>
                        <flux:subheading>Select which vendors to include (leave empty for all)</flux:subheading>
                    </div>
                    @if(!empty($selectedVendorIds))
                        <flux:button wire:click="selectAllVendors" variant="ghost" size="sm">
                            Clear Filter
                        </flux:button>
                    @endif
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-64 overflow-y-auto">
                    @foreach($this->vendors as $vendor)
                        <flux:checkbox
                            wire:click="toggleVendor({{ $vendor->id }})"
                            :checked="empty($selectedVendorIds) || in_array($vendor->id, $selectedVendorIds)"
                            label="{{ $vendor->name }}"
                        >
                            <x-slot:description>
                                @if($vendor->source_type === 'licensed')
                                    <flux:icon name="lock-closed" class="size-3 inline text-amber-500" />
                                @elseif($vendor->source_type === 'oss')
                                    <flux:icon name="globe-alt" class="size-3 inline text-green-500" />
                                @else
                                    <flux:icon name="puzzle-piece" class="size-3 inline text-blue-500" />
                                @endif
                                {{ $vendor->slug }}
                            </x-slot:description>
                        </flux:checkbox>
                    @endforeach
                </div>

                @if($this->vendors->isEmpty())
                    <div class="text-center py-8 text-zinc-500">
                        <flux:icon name="building-office" class="size-8 opacity-50 mx-auto mb-2" />
                        <span>No vendors tracked yet</span>
                    </div>
                @endif
            </flux:card>

            {{-- Priority Threshold --}}
            <flux:card class="p-6">
                <flux:heading size="lg" class="mb-4">Priority Threshold</flux:heading>
                <flux:subheading class="mb-4">Only include tasks at or above this priority level</flux:subheading>

                <flux:select wire:model.live="minPriority">
                    <flux:option :value="null">All priorities</flux:option>
                    <flux:option value="4">Medium and above (4+)</flux:option>
                    <flux:option value="6">High and above (6+)</flux:option>
                    <flux:option value="8">Critical only (8+)</flux:option>
                </flux:select>
            </flux:card>

            {{-- Actions --}}
            <div class="flex items-center justify-between">
                <flux:button wire:click="sendTestDigest" variant="ghost" icon="paper-airplane">
                    Send Test Digest
                </flux:button>

                <div class="flex items-center gap-3">
                    <flux:button wire:click="showPreview" variant="ghost" icon="eye">
                        Preview
                    </flux:button>
                    <flux:button wire:click="save" variant="primary" icon="check">
                        Save Preferences
                    </flux:button>
                </div>
            </div>
        </div>

        {{-- Preview Panel --}}
        <div class="lg:col-span-1">
            <flux:card class="p-6 sticky top-6">
                <flux:heading size="lg" class="mb-4">Preview</flux:heading>
                <flux:subheading class="mb-6">What your next digest would include</flux:subheading>

                @php $preview = $this->preview; @endphp

                @if(!$preview['has_content'])
                    <div class="text-center py-8 text-zinc-500">
                        <flux:icon name="inbox" class="size-8 opacity-50 mx-auto mb-2" />
                        <span>No content to preview</span>
                        <p class="text-sm mt-1">There are no updates matching your filters</p>
                    </div>
                @else
                    <div class="space-y-4">
                        {{-- Security Alert --}}
                        @if($preview['security_count'] > 0)
                            <div class="p-3 bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800">
                                <div class="flex items-center gap-2 text-red-700 dark:text-red-400">
                                    <flux:icon name="shield-exclamation" class="size-5" />
                                    <span class="font-medium">{{ $preview['security_count'] }} security update{{ $preview['security_count'] !== 1 ? 's' : '' }}</span>
                                </div>
                            </div>
                        @endif

                        {{-- Releases --}}
                        @if($preview['releases']->isNotEmpty())
                            <div>
                                <h4 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">Recent Releases</h4>
                                <ul class="space-y-1 text-sm text-zinc-600 dark:text-zinc-400">
                                    @foreach($preview['releases'] as $release)
                                        <li class="flex items-center justify-between">
                                            <span>{{ $release['vendor_name'] }}</span>
                                            <span class="font-mono text-xs">{{ $release['version'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        {{-- Todos Summary --}}
                        @if(($preview['todos']['total'] ?? 0) > 0)
                            <div>
                                <h4 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">Pending Tasks</h4>
                                <div class="grid grid-cols-2 gap-2 text-sm">
                                    @if($preview['todos']['critical'] > 0)
                                        <div class="flex items-center justify-between p-2 bg-red-50 dark:bg-red-900/20 rounded">
                                            <span class="text-red-700 dark:text-red-400">Critical</span>
                                            <span class="font-medium">{{ $preview['todos']['critical'] }}</span>
                                        </div>
                                    @endif
                                    @if($preview['todos']['high'] > 0)
                                        <div class="flex items-center justify-between p-2 bg-orange-50 dark:bg-orange-900/20 rounded">
                                            <span class="text-orange-700 dark:text-orange-400">High</span>
                                            <span class="font-medium">{{ $preview['todos']['high'] }}</span>
                                        </div>
                                    @endif
                                    @if($preview['todos']['medium'] > 0)
                                        <div class="flex items-center justify-between p-2 bg-yellow-50 dark:bg-yellow-900/20 rounded">
                                            <span class="text-yellow-700 dark:text-yellow-400">Medium</span>
                                            <span class="font-medium">{{ $preview['todos']['medium'] }}</span>
                                        </div>
                                    @endif
                                    @if($preview['todos']['low'] > 0)
                                        <div class="flex items-center justify-between p-2 bg-zinc-50 dark:bg-zinc-800 rounded">
                                            <span class="text-zinc-600 dark:text-zinc-400">Low</span>
                                            <span class="font-medium">{{ $preview['todos']['low'] }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif

                        {{-- Top Vendors --}}
                        @if($preview['top_vendors']->isNotEmpty())
                            <div>
                                <h4 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">Top Vendors</h4>
                                <ul class="space-y-1 text-sm text-zinc-600 dark:text-zinc-400">
                                    @foreach($preview['top_vendors'] as $vendor)
                                        <li class="flex items-center justify-between">
                                            <span>{{ $vendor->name }}</span>
                                            <flux:badge size="sm" color="blue">{{ $vendor->pending_count }} pending</flux:badge>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        {{-- Next Send --}}
                        @if($preview['next_send'])
                            <div class="pt-4 border-t border-zinc-200 dark:border-zinc-700 text-sm text-zinc-500">
                                <p><strong>Frequency:</strong> {{ $preview['frequency_label'] }}</p>
                                <p><strong>Next send:</strong> {{ $preview['next_send'] }}</p>
                            </div>
                        @endif
                    </div>
                @endif
            </flux:card>
        </div>
    </div>
</admin:module>
