@php
    $domain = $database->domain;
    $status = $domain ? ($dnsStatuses[$domain]['status'] ?? 'pending') : null;
    $message = $domain ? ($dnsStatuses[$domain]['message'] ?? '') : '';
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
    $port = $domain ? $database->publicEndpointPort() : null;
@endphp

<div>
    <x-application.settings-section id="domains-section" title="Domains"
        description="Expose the database with a domain. The database proxy of the server publishes one port per database type and picks the database by the hostname of the TLS handshake, so every database of a type shares the port.">
        <x-slot:actions>
            @if (filled($domain))
                <x-forms.button wire:click="checkAllDns" wire:loading.attr="disabled" wire:target="checkAllDns">
                    Check all DNS
                </x-forms.button>
            @endif
        </x-slot:actions>

        @if (filled($domain))
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-neutral-200 text-xs uppercase tracking-wide text-neutral-500 dark:border-white/10 dark:text-neutral-400">
                            <th class="px-2 py-2 font-medium">Domain</th>
                            <th class="px-2 py-2 font-medium">Port</th>
                            <th class="px-2 py-2 font-medium">DNS status</th>
                            <th class="px-2 py-2 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr wire:key="domain-{{ $domain }}" class="border-b border-neutral-100 dark:border-white/5">
                            <td class="px-2 py-2 font-medium text-neutral-900 dark:text-white">{{ $domain }}</td>
                            <td class="px-2 py-2 text-neutral-600 dark:text-neutral-300">{{ $port }}</td>
                            <td class="px-2 py-2">
                                <x-status-badge :status="$dnsLabel" :type="$dnsType" :title="$message" />
                            </td>
                            <td class="px-2 py-2">
                                <x-forms.button wire:click="removeDomain" wire:loading.attr="disabled" wire:target="removeDomain"
                                    wire:confirm="Are you sure you want to remove the domain of this database?">
                                    Remove
                                </x-forms.button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @else
            <div class="text-sm text-neutral-500 dark:text-neutral-400">
                This database has no domain. Add one to reach it through the database proxy of the server.
            </div>
        @endif
    </x-application.settings-section>

    @if (blank($domain))
        <x-application.settings-section id="add-domain-section" title="Add domain"
            description="The hostname of the database. It has to point at the server of the database.">
            <form wire:submit="addDomain" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="w-full sm:max-w-md">
                    <x-forms.input id="newDomain" placeholder="db.example.com" label="Domain"
                        canGate="update" :canResource="$database" />
                </div>
                <x-forms.button type="submit" wire:loading.attr="disabled" wire:target="addDomain">
                    Add domain
                </x-forms.button>
            </form>
        </x-application.settings-section>
    @endif
</div>
