<?php

namespace App\Livewire\Project\Application;

use App\Livewire\Concerns\InteractsWithCloudflareDomainConnect;
use App\Livewire\Concerns\InteractsWithDnsProviders;
use App\Livewire\Concerns\ManagesResourceDomains;
use App\Livewire\Project\Shared\ConfigurationChecker;
use App\Models\Application;
use App\Traits\AuditsApplicationSettings;
use App\Traits\ListensToTeamChannel;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Domains extends Component
{
    use AuditsApplicationSettings;
    use AuthorizesRequests;
    use InteractsWithCloudflareDomainConnect;
    use InteractsWithDnsProviders;
    use ListensToTeamChannel;
    use ManagesResourceDomains;

    public Application $application;

    /** When set, the next addSuggestedDomain call for this index skips the DNS block. */
    #[Locked]
    public function toggleNoindexDomain(string $domain, string|bool $indexing): void
    {
        $this->authorize('update', $this->application);

        $noindex = $indexing === true || $indexing === 'noindex';
        $domains = $this->application->noindexDomains();
        $domains = $noindex ? $domains->push($domain) : $domains->reject(fn (string $item) => $item === $domain);

        $this->application->setNoindexDomains($domains);
        $this->application->save();
        $this->application->refresh();
        $this->resetDefaultLabels();
        $this->dispatch('configurationChanged')->to(ConfigurationChecker::class);
        $this->dispatch('success', 'Search engine indexing updated.');
    }

    public function updateRedirect(string $redirect): void
    {
        $this->redirect = $redirect;
        $this->setRedirect();
    }

    public function updateForceHttps(): void
    {
        $this->authorize('update', $this->application);
        $this->validateOnly('isForceHttpsEnabled');

        $this->application->settings->is_force_https_enabled = $this->isForceHttpsEnabled;
        $this->saveApplicationSettingsWithAudit($this->application);
        $this->resetDefaultLabels();
        $this->dispatch('configurationChanged')->to(ConfigurationChecker::class);
        $this->dispatch('success', 'HTTP to HTTPS redirect updated.');
    }

    public function updatePermanentRedirect(): void
    {
        $this->authorize('update', $this->application);
        $this->validateOnly('isPermanentRedirectEnabled');

        $this->application->settings->is_permanent_redirect_enabled = $this->isPermanentRedirectEnabled;
        $this->saveApplicationSettingsWithAudit($this->application);
        $this->resetDefaultLabels();
        $this->dispatch('configurationChanged')->to(ConfigurationChecker::class);
        $this->dispatch('success', 'Redirect type updated.');
    }

    public function updatedNewDomainService(): void
    {
        $this->resetAddDomainDnsGate();
    }

    public function confirmUseUnknownPort(): void
    {
        $this->authorize('update', $this->application);
        $this->forceUseUnknownPort = true;
        $this->showPortWarningModal = false;
        $action = $this->pendingPortAction;
        $this->pendingPortAction = null;

        if ($action === 'update') {
            $this->updateDomain();

            return;
        }

        $this->addDomain();
    }

    public function cancelUseUnknownPort(): void
    {
        $this->showPortWarningModal = false;
        $this->forceUseUnknownPort = false;
        $this->unrecognizedPort = null;
        $this->pendingPortAction = null;
    }

    /**
     * Clear pending conflict state when the modal is dismissed without confirmation.
     * confirmDomainUsage sets forceSaveDomains before closing the modal.
     */
    public function updatedShowDomainConflictModal(bool $value): void
    {
        if ($value || $this->forceSaveDomains) {
            return;
        }

        $this->authorize('update', $this->application);
        $wasRedirect = $this->pendingAction === 'redirect';
        $this->pendingAction = null;
        $this->pendingRedirectService = null;
        if ($wasRedirect) {
            $this->refreshDomains();
        }
    }

    public function regenerateEditingDomain(): void
    {
        $this->authorize('update', $this->application);

        if ($this->labelsAreWritable || $this->editingIndex === null || ! isset($this->domainRows[$this->editingIndex])) {
            return;
        }

        $server = data_get($this->application, 'destination.server');
        if (! $server) {
            $this->dispatch('error', 'No server found for this application.');

            return;
        }

        $generatedHost = parse_url(generateUrl(server: $server, random: new_public_id()), PHP_URL_HOST);
        if (! is_string($generatedHost) || $generatedHost === '') {
            $this->dispatch('error', 'Could not generate a domain.');

            return;
        }

        $currentHost = (string) ($this->editingDomainParts['host'] ?? '');
        $this->editingGeneratedHost = $generatedHost;
        $this->editingDomainParts['host'] = str_starts_with(strtolower($currentHost), 'www.')
            ? 'www.'.$generatedHost
            : $generatedHost;
        $this->editingDomainPartsChanged = true;
        $this->editingDomainWasRegenerated = true;
        $this->resetEditDomainDnsGate();
        $this->resetErrorBag('editingDomain');
    }

    public function generateDomain(?string $serviceName = null): void
    {
        try {
            $this->authorize('update', $this->application);

            if ($this->labelsAreWritable) {
                $this->dispatch('error', 'Domains cannot be edited while container labels are writable.');

                return;
            }

            $server = data_get($this->application, 'destination.server');
            if (! $server) {
                $this->dispatch('error', 'No server found for this application.');

                return;
            }

            if ($this->isCompose) {
                $serviceName = $serviceName ?: $this->newDomainService ?: ($this->composeServices[0] ?? null);
                if (! $serviceName) {
                    $this->dispatch('error', 'No compose service available for domain generation.');

                    return;
                }

                $domain = generateUrl(server: $server, random: new_public_id());
                $current = $this->currentDomainList($serviceName);
                $merged = $current->push($domain)->unique()->values();

                if (! $this->saveDomainList($merged, $serviceName, checkConflicts: false)) {
                    return;
                }

                $pairedUrls = $this->syncRedirectDomainPairs($serviceName);
                $this->resetAddDomainForm();
                $this->dispatch('close-modal');
                $this->dispatch('success', 'Domain generated.');
                $this->refreshDomains();
                $this->checkUrlsDns(array_values(array_unique(array_merge([$domain], $pairedUrls))), $serviceName);

                return;
            }

            $fqdn = generateUrl(server: $server, random: $this->application->uuid);
            $merged = $this->currentDomainList()->push($fqdn)->unique()->values();
            if (! $this->saveDomainList($merged, null, checkConflicts: false)) {
                return;
            }

            $pairedUrls = $this->syncRedirectDomainPairs(null);
            $this->resetAddDomainForm();
            $this->dispatch('close-modal');
            $this->dispatch('success', 'Domain generated.');
            $this->refreshDomains();
            $this->checkUrlsDns(array_values(array_unique(array_merge([$fqdn], $pairedUrls))));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function updateServiceRedirect(string $serviceName, string $redirect): void
    {
        $this->serviceRedirects[$this->serviceRedirectWireKey($serviceName)] = $redirect;
        $this->setServiceRedirect($serviceName);
    }

    /**
     * @param  Collection<int, string>  $domains
     */
    protected function assertRedirectDomainsPresent(string $redirect, Collection $domains): bool
    {
        if (! in_array($redirect, ['www', 'non-www'], true)) {
            return true;
        }

        $hasWww = $domains->filter(
            fn ($fqdn) => str_starts_with(strtolower((string) $this->domainHost((string) $fqdn)), 'www.')
        )->count();
        $hasNonWww = $domains->filter(
            function ($fqdn) {
                $host = strtolower((string) $this->domainHost((string) $fqdn));

                return $host !== '' && ! str_starts_with($host, 'www.');
            }
        )->count();

        $dnsHint = dnsMismatchGuidanceMessage(
            $this->dnsTargetLabel() ?? $this->serverIp,
            $this->serverIp,
        );

        // Redirects need both hosts: canonical target + source the proxy redirects from.
        if ($hasWww === 0 || $hasNonWww === 0) {
            $missing = $hasWww === 0 ? 'www' : 'non-www';
            $this->dispatch(
                'error',
                "Redirect requires both www and non-www domains, but the {$missing} host could not be added automatically (e.g. only IP/sslip hosts).<br><br>Please add the {$missing} domain manually ({$dnsHint})."
            );

            return false;
        }

        return true;
    }

    /**
     * When saved redirect is www/non-www, ensure missing counterparts exist as real domains
     * (not suggestion rows the user must click Add domain for).
     *
     * @return array<int, string> newly added domain URLs
     */
    protected function syncRedirectDomainPairs(?string $serviceName = null): array
    {
        if ($this->labelsAreWritable) {
            return [];
        }

        $user = auth()->user();
        if ($user === null || ! $user->can('update', $this->application)) {
            return [];
        }

        if ($this->isCompose && $serviceName === null) {
            $added = [];
            $serviceNames = $this->composeServices;
            $domains = $this->application->docker_compose_domains
                ? json_decode($this->application->docker_compose_domains, true)
                : [];
            if (is_array($domains)) {
                foreach (array_keys($domains) as $name) {
                    if (! in_array($name, $serviceNames, true)) {
                        $serviceNames[] = $name;
                    }
                }
            }
            foreach ($serviceNames as $name) {
                $added = array_merge($added, $this->syncRedirectDomainPairs($name));
            }

            return array_values(array_unique($added));
        }

        $redirect = $this->savedRedirectForService($serviceName);
        if (! in_array($redirect, ['www', 'non-www'], true)) {
            return [];
        }

        $before = $this->currentDomainList($serviceName)->all();
        if (! $this->ensureWwwNonWwwPairsConfigured($serviceName)) {
            return [];
        }

        $this->application->refresh();
        $after = $this->currentDomainList($serviceName);

        return $after->reject(fn (string $url) => in_array($url, $before, true))->values()->all();
    }

    protected function savedRedirectForService(?string $serviceName): string
    {
        if ($this->isCompose && filled($serviceName)) {
            $domains = $this->application->docker_compose_domains
                ? json_decode($this->application->docker_compose_domains, true)
                : [];
            $entry = is_array($domains) ? ($domains[$serviceName] ?? null) : null;
            $stored = is_array($entry) ? ($entry['redirect'] ?? null) : null;

            return $this->normalizeRedirect(is_string($stored) ? $stored : null);
        }

        return $this->normalizeRedirect($this->application->redirect ?? null);
    }

    /**
     * Persist missing www/non-www counterparts as normal domains (not suggestions).
     *
     * @return bool false when save was blocked (e.g. domain conflict modal shown)
     */
    protected function ensureWwwNonWwwPairsConfigured(?string $serviceName = null): bool
    {
        $current = $this->currentDomainList($serviceName);
        $knownHosts = [];

        foreach ($current as $url) {
            $host = $this->domainHost($url);
            if ($host !== null) {
                $knownHosts[strtolower($host)] = true;
            }
        }

        $toAdd = collect();
        foreach ($current as $url) {
            // Include sslip/nip so compose/dev hosts can still get redirect pairs.
            $counterpart = $this->wwwCounterpartUrl($url, forRedirectPairing: true);
            if ($counterpart === null) {
                continue;
            }

            $counterpartHost = $this->domainHost($counterpart);
            if ($counterpartHost === null) {
                continue;
            }

            $hostKey = strtolower($counterpartHost);
            if (isset($knownHosts[$hostKey])) {
                continue;
            }

            $knownHosts[$hostKey] = true;
            $toAdd->push($counterpart);
        }

        if ($toAdd->isEmpty()) {
            return true;
        }

        $merged = $current->merge($toAdd)->unique()->values();
        $this->pendingAction = 'redirect';
        $this->pendingRedirectService = $serviceName;

        // Skip DNS: pairing for redirects must still be configured even when DNS is not ready.
        if (! $this->saveDomainList($merged, $serviceName)) {
            return false;
        }

        $this->pendingAction = null;
        $this->pendingRedirectService = null;

        return true;
    }

    public function render()
    {
        return view('livewire.project.application.domains');
    }
}
