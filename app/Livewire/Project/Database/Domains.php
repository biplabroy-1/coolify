<?php

namespace App\Livewire\Project\Database;

use App\Actions\Shared\CheckDomainDns;
use App\Livewire\Concerns\InteractsWithCloudflareDomainConnect;
use App\Livewire\Concerns\InteractsWithDnsProviders;
use App\Rules\ValidHostname;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Domains extends Component
{
    use AuthorizesRequests;
    use InteractsWithCloudflareDomainConnect;
    use InteractsWithDnsProviders;

    public $database;

    /** The address of the server of the database, the target of the DNS records. */
    #[Locked]
    public ?string $serverIp = null;

    /**
     * The domains of the database with their DNS status.
     *
     * @var array<int, array{url: string, dns_status: string, dns_message: string, expected_ip: ?string}>
     */
    #[Locked]
    public array $domainRows = [];

    public string $newDomain = '';

    /**
     * The DNS status of every domain of the database.
     *
     * @var array<string, array{status: string, message: string, expected_ip: ?string, checked_at: string}>
     */
    public array $dnsStatuses = [];

    public function mount(): void
    {
        $this->authorize('view', $this->database);
        $this->serverIp = $this->database->proxyServer()?->getIp;
        $this->loadDomainRows();
        $this->refreshDnsStatuses();
    }

    /**
     * The domains of the database, the first one is the primary domain.
     */
    public function domains(): array
    {
        return $this->database->proxyDomains();
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
        $this->loadDomainRows();
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

        $this->loadDomainRows();
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

    protected function authorizeUpdateForDomainConnect(): void
    {
        $this->authorize('update', $this->database);
    }

    /**
     * The DNS status of a database is checked when its domains page opens, so there is nothing
     * to persist.
     */
    protected function persistDomainDnsStatuses(array $startingCheckKeys = []): void
    {
        //
    }

    protected function dnsResource(): Model
    {
        return $this->database;
    }

    protected function dnsResourceForHostname(string $hostname): ?Model
    {
        return $this->database;
    }

    protected function usesInstanceNetworkAddressesForDnsHints(): bool
    {
        return $this->database->proxyServer()?->id === 0;
    }

    private function loadDomainRows(): void
    {
        $this->domainRows = collect($this->domains())->map(fn (string $domain): array => [
            'url' => $domain,
            'dns_status' => 'pending',
            'dns_message' => '',
            'expected_ip' => null,
        ])->all();
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

        foreach ($this->domainRows as $index => $row) {
            $status = $this->dnsStatuses[$row['url']] ?? null;
            if ($status === null) {
                continue;
            }
            $this->domainRows[$index]['dns_status'] = $status['status'];
            $this->domainRows[$index]['dns_message'] = $status['message'];
            $this->domainRows[$index]['expected_ip'] = $status['expected_ip'];
        }
    }

    public function render()
    {
        return view('livewire.project.database.domains');
    }
}
