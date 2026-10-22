<?php

namespace App\Livewire\Project\Database;

use App\Actions\Shared\CheckDomainDns;
use App\Rules\ValidHostname;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Domains extends Component
{
    use AuthorizesRequests;

    public $database;

    public string $newDomain = '';

    /**
     * The DNS status of every domain of the database.
     *
     * @var array<string, array{status: string, message: string, expected_ip: ?string, checked_at: string}>
     */
    public array $dnsStatuses = [];

    /**
     * The domains of the database, the first one is the primary domain.
     */
    public function domains(): array
    {
        return array_values(array_filter(array_map(
            fn (string $domain): string => strtolower(trim($domain)),
            explode(',', (string) $this->database->domain)
        )));
    }

    public function mount(): void
    {
        $this->authorize('view', $this->database);
        $this->refreshDnsStatuses();
    }

    /**
     * Expose the database with a domain. The domain replaces the public port: the database proxy
     * of the server publishes one port per database type and picks the database by the hostname
     * of the TLS handshake, so every database of a type shares the port.
     */
    public function addDomain(): void
    {
        $this->authorize('update', $this->database);

        $this->validate([
            'newDomain' => ['required', 'string', 'max:253', new ValidHostname],
        ]);

        $domain = strtolower(trim($this->newDomain));
        $domains = $this->domains();
        if (! in_array($domain, $domains, true)) {
            $domains[] = $domain;
        }

        $this->database->domain = implode(',', $domains);
        $this->database->is_public = true;
        $this->database->save();

        $this->newDomain = '';
        $this->refreshDnsStatuses();
        $this->dispatch('success', 'The domain was added.');
    }

    public function removeDomain(string $domain): void
    {
        $this->authorize('update', $this->database);

        $domains = array_values(array_filter(
            $this->domains(),
            fn (string $current): bool => $current !== strtolower(trim($domain))
        ));

        $this->database->domain = $domains === [] ? null : implode(',', $domains);
        $this->database->save();

        $this->refreshDnsStatuses();
        $this->dispatch('success', 'The domain was removed.');
    }

    /**
     * Check the DNS records of the domains.
     */
    public function checkAllDns(): void
    {
        $this->authorize('view', $this->database);

        $this->refreshDnsStatuses();
        $this->dispatch('success', 'The DNS records were checked.');
    }

    private function refreshDnsStatuses(): void
    {
        $domains = $this->domains();
        if ($domains === []) {
            $this->dnsStatuses = [];

            return;
        }

        $server = $this->database->proxyServer();
        $entries = [];
        foreach ($domains as $domain) {
            $entries[$domain] = $domain;
        }

        $this->dnsStatuses = CheckDomainDns::run($entries, $server, $server?->getIp);
    }

    public function render()
    {
        return view('livewire.project.database.domains');
    }
}
