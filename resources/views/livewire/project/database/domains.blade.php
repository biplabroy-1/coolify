@php
    $domains = $this->domains();
    $port = $database->publicEndpointPort();
    $hasDnsChecksInProgress = collect($dnsStatuses)->contains(fn (array $status): bool => $status['status'] === 'checking');
@endphp

<div id="database-domains-section" class="flex flex-col gap-4"
    x-data="{
        domainSearch: '',
        matchesDomainSearch(value) {
            return !this.domainSearch.trim() || value.toLowerCase().includes(this.domainSearch.trim().toLowerCase());
        },
    }">
    @if ($hasDnsChecksInProgress)
        <div class="hidden" wire:poll.2000ms="pollDnsChecks" aria-hidden="true"></div>
    @endif
    @cannot('update', $database)
        <x-callout type="danger" title="Insufficient permissions">
            You don't have permission to manage domains. Contact your team administrator for access.
        </x-callout>
    @endcannot

    {{-- Toolbar --}}
    <div id="domains-section" class="flex flex-wrap items-center gap-2">
        @if (count($domains) > 0)
            <div class="relative w-full sm:w-64">
                <x-reicon name="search"
                    class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                <input type="search" x-model="domainSearch" aria-label="Search domains"
                    class="input h-8! w-full pl-8! text-[13px]!" placeholder="Search domains" />
            </div>
        @endif
        <div class="ml-auto flex flex-wrap items-center gap-2">
            @can('update', $database)
                @if (count($domains) > 0)
                    <x-forms.button wire:click="checkAllDns" :showLoadingIndicator="false" wire:loading.attr="disabled"
                        wire:target="checkAllDns">
                        <x-reicon name="refresh" class="size-3.5" />
                        Check all DNS
                    </x-forms.button>
                @endif

                <x-modal-input title="Add domain" :closeOutside="false" :wireIgnore="false"
                    canGate="update" :canResource="$database">
                    <x-slot:content>
                        <button type="button" class="button button-highlighted">
                            <x-reicon name="plus" class="size-3.5" />
                            Add domain
                        </button>
                    </x-slot:content>
                    <form wire:submit="addDomain" class="application-settings-form flex flex-col gap-4">
                        <x-forms.input id="newDomain" placeholder="db.example.com" label="Domain"
                            helper="The hostname of the database. It has to point at the server of the database."
                            canGate="update" :canResource="$database" />

                        <div class="flex flex-wrap items-center justify-between gap-2 pt-2">
                            <x-forms.button canGate="update" :canResource="$database" type="submit" isHighlighted>
                                Save
                            </x-forms.button>
                        </div>
                    </form>
                </x-modal-input>
            @endcan
        </div>
    </div>

    @if (count($domains) === 0)
        <div class="application-settings-section-body mt-1 w-full scroll-mt-28">
            <x-empty size="sm" title="No domains configured"
                description="Add your first domain with the Add domain button above. The database proxy of the server routes it to the database."
                icon-name="globe" />
        </div>
    @else
        <div wire:key="database-domains-list" class="flex flex-col gap-3">
            <section id="database-domain-group" class="application-settings-section-body is-flush overflow-visible">
                <div class="flex w-full flex-wrap items-center gap-3 rounded-t-lg border-b border-neutral-200 bg-neutral-50 px-4 py-3 dark:border-white/10 dark:bg-white/[0.04]">
                    <span class="min-w-0 flex-1 truncate text-sm font-medium text-black dark:text-white">Domains</span>
                    <div class="flex w-full items-center gap-2 sm:w-auto service-domains-https">
                        <label class="mb-0! whitespace-nowrap text-[12px]!">Port</label>
                        <span class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ $port }}</span>
                    </div>
                </div>

                <div wire:key="database-domain-rows">
                    @foreach ($domains as $domain)
                        @php
                            $status = $dnsStatuses[$domain]['status'] ?? 'pending';
                            $message = $dnsStatuses[$domain]['message'] ?? '';
                            $dnsType = match ($status) {
                                'ok' => 'success',
                                'failed' => 'error',
                                'skipped' => 'warning',
                                default => 'neutral',
                            };
                            $dnsLabel = match ($status) {
                                'ok' => 'DNS matches',
                                'failed' => 'DNS mismatch',
                                'skipped' => 'DNS skipped',
                                'checking' => 'Checking DNS...',
                                'pending' => 'Not checked',
                                default => 'DNS unknown',
                            };
                        @endphp
                        <div wire:key="database-domain-{{ $domain }}"
                            x-show="matchesDomainSearch(@js($domain))" class="data-table-row env-table-item">
                            <div class="flex min-w-0 flex-col gap-1">
                                <div class="flex min-w-0 items-center gap-2">
                                    <x-reicon name="globe" class="size-4 shrink-0 text-neutral-400 dark:text-fg-faint" />
                                    <span class="min-w-0 flex-1 truncate text-[13px] text-black dark:text-white"
                                        title="{{ $domain }}">{{ $domain }}</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-status-badge :status="$dnsLabel" :type="$dnsType" :title="$message" />
                                @can('update', $database)
                                    <x-forms.button wire:click="removeDomain('{{ $domain }}')" wire:loading.attr="disabled"
                                        wire:target="removeDomain" wire:confirm="Are you sure you want to remove the domain of this database?">
                                        Remove
                                    </x-forms.button>
                                @endcan
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        </div>
    @endif
</div>
