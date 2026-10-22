<?php

namespace App\Livewire\Concerns;

use App\Rules\ValidHostname;
use App\Services\DatabaseProxy\DatabaseProxyConfiguration;
use Illuminate\Validation\ValidationException;

trait HasDatabaseDomain
{
    public ?string $domain = null;

    /**
     * @return array<int, mixed>
     */
    public function databaseDomainRules(): array
    {
        return ['nullable', 'string', 'max:253', new ValidHostname];
    }

    /**
     * The domain is a hostname: no scheme, no port, no path.
     */
    public function syncDatabaseDomain(bool $toModel = false): void
    {
        if ($toModel) {
            $domain = trim((string) $this->domain);
            if ($domain !== '' && ! $this->database->enable_ssl && ! DatabaseProxyConfiguration::usesPublicCertificate($this->database, $domain)) {
                throw ValidationException::withMessages([
                    'domain' => 'A database with a domain needs its own certificate when the zone of the domain is not managed by Cloudflare. Enable SSL on the database, or add a Cloudflare integration for the zone.',
                ]);
            }
            $this->database->domain = $domain === '' ? null : strtolower($domain);
        } else {
            $this->domain = $this->database->domain;
        }
    }

    /**
     * A database with a domain is exposed through the database proxy, so it needs no public port.
     */
    public function databaseNeedsPublicPort(): bool
    {
        return blank($this->database?->domain);
    }
}
