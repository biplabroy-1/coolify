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
     * The DNS status of the domain of the database.
     *
     * @var array<string, array{status: string, message: string, expected_ip: ?string, checked_at: string}>
     */
    public array $dnsStatuses = [];

    public function mount(): void
    {
        $this->authorize('view', $this->database);
        $this->refreshDnsStatuses();
    }

    /**
     * Expose the database with a domain. The domain replaces the public port: the database proxy
     * of the server publishes one port per database type and picks the database by the hostname.
     */
    public function addDomain(): void
    {
        $this->authorize('update', $this->database);

        $this->validate([
            'newDomain' => ['required', 'string', 'max:253', new ValidHostname],
        ]);

        $this->database->domain = strtolower(trim($this->newDomain));
        $this->database->is_public = true;
        $this->database->save();

        $this->newDomain = '';
        $this->refreshDnsStatuses();
        $this->dispatch('success', 'The domain was added.');
    }

    public function removeDomain(): void
    {
        $this->authorize('update', $this->database);

        $this->database->domain = null;
        $this->database->save();

        $this->refreshDnsStatuses();
        $this->dispatch('success', 'The domain was removed.');
    }

    /**
     * Check the DNS record of the domain.
     */
    public function checkAllDns(): void
    {
        $this->authorize('view', $this->database);

        $this->refreshDnsStatuses();
        $this->dispatch('success', 'The DNS record was checked.');
    }

    private function refreshDnsStatuses(): void
    {
        $domain = $this->database->domain;
        if (blank($domain)) {
            $this->dnsStatuses = [];

            return;
        }

        $server = $this->database->proxyServer();
        $this->dnsStatuses = CheckDomainDns::run([$domain => $domain], $server, $server?->getIp);
    }

    public function render()
    {
        return view('livewire.project.database.domains');
    }
}
