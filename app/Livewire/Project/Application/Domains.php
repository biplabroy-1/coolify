<?php

namespace App\Livewire\Project\Application;

use App\Actions\Shared\CheckDomainDns;
use App\Jobs\CheckDomainDnsJob;
use App\Livewire\Concerns\InteractsWithCloudflareDomainConnect;
use App\Livewire\Concerns\ManagesResourceDomains;
use App\Livewire\Concerns\InteractsWithDnsProviders;
use App\Livewire\Project\Shared\ConfigurationChecker;
use App\Models\Application;
use App\Models\Server;
use App\Support\DomainPortOverrides;
use App\Support\DomainUrlParts;
use App\Support\ValidationPatterns;
use App\Traits\AuditsApplicationSettings;
use App\Traits\ListensToTeamChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Domains extends Component
{
    use AuditsApplicationSettings;
    use AuthorizesRequests;
    use ManagesResourceDomains;
    use InteractsWithCloudflareDomainConnect;
    use InteractsWithDnsProviders;
    use ListensToTeamChannel;

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

    /**
     * Livewire wire:model treats dots as nesting (serviceRedirects.api.test).
     * Encode them so compose service names like "api.test" stay flat string values.
     */
    public function serviceRedirectWireKey(string $serviceName): string
    {
        return str_replace('.', '__dot__', $serviceName);
    }

    protected function normalizeRedirect(?string $redirect): string
    {
        return in_array($redirect, ['www', 'non-www', 'both'], true) ? $redirect : 'both';
    }

    protected function serviceRedirectFor(?string $serviceName): string
    {
        if ($serviceName === null) {
            return $this->normalizeRedirect($this->redirect);
        }

        return $this->normalizeRedirect(
            $this->serviceRedirects[$this->serviceRedirectWireKey($serviceName)] ?? null
        );
    }

    /**
     * Missing www/non-www counterparts as full domain rows (DNS-checkable, not yet saved).
     *
     * Messaging depends on Direction:
     * - both: both hosts are first-class and need DNS to the server
     * - www / non-www: the missing pair is for Coolify's redirect (still must resolve to
     *   the server so the proxy can redirect; not framed as a separate primary site)
     *
     * @param  array<int, array<string, mixed>>  $configuredRows
     * @param  array<string, array{status?: string, message?: string, expected_ip?: ?string, checked_at?: ?string}>  $stored
     * @return array<int, array{url: string, service: ?string, dns_status: string, dns_message: string, expected_ip: ?string, checked_at: ?string, is_suggested: bool, suggested_for: ?string, suggestion_label: ?string, needs_force_add: bool, suggestion_role: ?string}>
     */
    protected function buildSuggestedWwwRows(array $configuredRows, array $stored, ?string $service = null): array
    {
        $knownHosts = [];
        foreach ($configuredRows as $row) {
            $host = $this->domainHost((string) ($row['url'] ?? ''));
            if ($host !== null) {
                $knownHosts[strtolower($host)] = true;
            }
        }

        $suggested = [];
        foreach ($configuredRows as $row) {
            $url = (string) ($row['url'] ?? '');
            $rowService = $service ?? ($row['service'] ?? null);
            $counterpart = $this->wwwCounterpartUrl($url);
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

            $base = $this->domainRowFromStored($counterpart, $rowService, $stored);
            $isWww = str_starts_with($hostKey, 'www.');
            $meta = $this->suggestedDomainMeta($isWww, $this->serviceRedirectFor(is_string($rowService) ? $rowService : null));

            $base['is_suggested'] = true;
            $base['suggested_for'] = $url;
            $base['suggestion_label'] = null;
            $base['suggestion_role'] = $meta['role'];
            $base['needs_force_add'] = false;
            $base['dns_message'] = $meta['pending_message'];

            $suggested[] = $base;
        }

        return $suggested;
    }

    /**
     * Labels/copy for a suggested www or non-www host based on Direction.
     *
     * @return array{label: string, role: string, pending_message: string, dns_suffix: string}
     */
    protected function suggestedDomainMeta(bool $suggestedIsWww, ?string $redirectOverride = null): array
    {
        $pendingMessage = 'Not configured yet.';
        $redirect = $redirectOverride ?? ($this->redirect ?: 'both');

        return match ($redirect) {
            'www' => $suggestedIsWww
                ? [
                    'label' => 'Not added · canonical www',
                    'role' => 'canonical',
                    'pending_message' => $pendingMessage,
                    'dns_suffix' => '',
                ]
                : [
                    'label' => 'Not added · redirect source',
                    'role' => 'redirect_source',
                    'pending_message' => $pendingMessage,
                    'dns_suffix' => '',
                ],
            'non-www' => $suggestedIsWww
                ? [
                    'label' => 'Not added · redirect source',
                    'role' => 'redirect_source',
                    'pending_message' => $pendingMessage,
                    'dns_suffix' => '',
                ]
                : [
                    'label' => 'Not added · canonical non-www',
                    'role' => 'canonical',
                    'pending_message' => $pendingMessage,
                    'dns_suffix' => '',
                ],
            default => [
                'label' => $suggestedIsWww ? 'Not added · www' : 'Not added · non-www',
                'role' => 'pair',
                'pending_message' => $pendingMessage,
                'dns_suffix' => '',
            ],
        };
    }

    /**
     * @param  array{scheme: string, host: string, port: string, path: string}  $parts
     */
    protected function portFromParts(array $parts): ?int
    {
        $port = trim((string) ($parts['port'] ?? ''));
        if ($port === '' || ! ctype_digit($port) || (int) $port <= 0) {
            return null;
        }

        return (int) $port;
    }

    protected function currentRowPort(string $url): ?int
    {
        $canonical = DomainPortOverrides::withoutPort($url);
        $override = ($this->application->domain_port_overrides ?? [])[$canonical] ?? null;
        if (filled($override) && (int) $override > 0) {
            return (int) $override;
        }

        $legacy = DomainUrlParts::split($url)['port'] ?? '';

        return $legacy !== '' && ctype_digit($legacy) ? (int) $legacy : null;
    }

    protected function shouldConfirmPort(?int $port, ?int $currentPort = null, ?string $serviceName = null): bool
    {
        if ($this->forceUseUnknownPort || $port === null) {
            return false;
        }
        if ($currentPort !== null && $port === $currentPort) {
            return false;
        }

        return $this->application->portRequiresConfirmation($port, $serviceName);
    }

    protected function openPortWarning(?int $port, string $action): void
    {
        $this->unrecognizedPort = $port;
        $this->pendingPortAction = $action;
        $this->showPortWarningModal = true;
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

    public function addSuggestedDomain(int $index): void
    {
        try {
            $this->authorize('update', $this->application);

            if ($this->labelsAreWritable) {
                $this->dispatch('error', 'Domains cannot be edited while container labels are writable.');

                return;
            }

            if (! isset($this->domainRows[$index]) || ! ($this->domainRows[$index]['is_suggested'] ?? false)) {
                return;
            }

            $url = (string) $this->domainRows[$index]['url'];
            $normalized = ValidationPatterns::normalizeApplicationDomains($url);
            if (blank($normalized)) {
                $this->dispatch('error', 'Invalid suggested domain.');

                return;
            }

            $serviceName = $this->domainRows[$index]['service'] ?? null;
            $newUrls = $this->splitDomains($normalized);
            $current = $this->currentDomainList($serviceName);
            foreach ($newUrls as $newUrl) {
                if ($current->contains($newUrl)) {
                    $this->refreshDomains();

                    return;
                }
            }

            $force = $this->forceAddSuggestedIndex === $index
                || (bool) ($this->domainRows[$index]['needs_force_add'] ?? false);

            if (! $force && $this->shouldValidateDnsForAdd()) {
                $dnsFailure = $this->findDnsFailureMessage($newUrls);
                if ($dnsFailure !== null) {
                    $this->domainRows[$index]['dns_status'] = 'failed';
                    $this->domainRows[$index]['dns_message'] = $dnsFailure;
                    $this->domainRows[$index]['checked_at'] = now()->toIso8601String();
                    $this->domainRows[$index]['needs_force_add'] = true;
                    $this->forceAddSuggestedIndex = $index;
                    $this->editingIndex = $index;
                    $this->persistDomainDnsStatuses();

                    return;
                }
            }

            $merged = $current->merge($newUrls)->unique()->values();
            $this->pendingAction = 'suggested';
            $this->editingIndex = $index;
            if (! $this->saveDomainList($merged, $serviceName)) {
                return;
            }

            $this->forceAddSuggestedIndex = null;
            $this->editingIndex = null;
            $this->pendingAction = null;
            $this->forceSaveDomains = false;
            $this->dispatch('success', 'Domain added.');
            $this->refreshDomains();
            $this->checkUrlsDns($newUrls, $serviceName);
        } catch (\Throwable $e) {
            handleError($e, $this);
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

    public function setRedirect(): void
    {
        try {
            $this->authorize('update', $this->application);

            if ($this->isCompose) {
                $this->dispatch('error', 'Set the redirect direction per compose service.');

                return;
            }

            $this->validateOnly('redirect');

            $this->application->redirect = $this->redirect;

            // www / non-www redirects need both hosts configured as real domains so the
            // proxy can serve the canonical host and redirect the other. Auto-add missing
            // counterparts instead of leaving them as optional suggestions.
            $addedDomains = [];
            if (in_array($this->redirect, ['www', 'non-www'], true)) {
                $domainsBeforePairing = $this->currentDomainList();
                if (! $this->ensureWwwNonWwwPairsConfigured(null)) {
                    return;
                }

                $this->application->refresh();
                $this->application->redirect = $this->redirect;
                $addedDomains = $this->currentDomainList()->diff($domainsBeforePairing)->values()->all();
            }

            $domains = collect($this->application->fqdns);
            if (! $this->assertRedirectDomainsPresent($this->redirect, $domains)) {
                return;
            }

            $this->application->save();
            $this->pendingAction = null;
            $this->pendingRedirectService = null;
            $this->forceSaveDomains = false;
            $this->resetDefaultLabels();
            $this->dispatch('success', 'Redirect updated.');
            $this->refreshDomains();
            $this->queueUrlsDns($addedDomains);
            $this->pruneDomainDnsStatusesToCurrentDomains();
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
     * @param  mixed  ...$modalArgs  Extra args from modal-confirmation (password, etc.)
     */
    public function setServiceRedirect(string $serviceName, mixed ...$modalArgs): void
    {
        try {
            $this->authorize('update', $this->application);

            if (! $this->isCompose) {
                $this->dispatch('error', 'Per-service redirect is only available for Docker Compose applications.');

                return;
            }

            // modal-confirmation passes string args with surrounding quotes intact.
            $serviceName = trim($serviceName, " \t\n\r\0\x0B'\"");

            if (blank($serviceName)) {
                $this->dispatch('error', 'A service is required.');

                return;
            }

            // Drop any nested arrays left from broken wire:model paths (e.g. service names with dots).
            $this->serviceRedirects = collect($this->serviceRedirects)
                ->filter(fn ($value) => is_string($value) || is_numeric($value))
                ->map(fn ($value) => $this->normalizeRedirect(is_string($value) ? $value : (string) $value))
                ->all();

            $wireKey = $this->serviceRedirectWireKey($serviceName);
            if (! array_key_exists($wireKey, $this->serviceRedirects)) {
                $this->serviceRedirects[$wireKey] = 'both';
            }

            $this->validateOnly("serviceRedirects.{$wireKey}");
            $redirect = $this->normalizeRedirect($this->serviceRedirects[$wireKey] ?? null);
            $this->serviceRedirects[$wireKey] = $redirect;
            $this->pendingRedirectService = $serviceName;

            // Promote the optional www/non-www suggestion to a real domain for redirects.
            $addedDomains = [];
            if (in_array($redirect, ['www', 'non-www'], true)) {
                $domainsBeforePairing = $this->currentDomainList($serviceName);
                if (! $this->ensureWwwNonWwwPairsConfigured($serviceName)) {
                    return;
                }
                // Ensure we re-read domains after pair save before writing redirect.
                $this->application->refresh();
                $addedDomains = $this->currentDomainList($serviceName)->diff($domainsBeforePairing)->values()->all();
            }

            $allDomains = $this->application->docker_compose_domains
                ? json_decode($this->application->docker_compose_domains, true)
                : [];
            if (! is_array($allDomains)) {
                $allDomains = [];
            }

            $existing = is_array($allDomains[$serviceName] ?? null) ? $allDomains[$serviceName] : [];
            $allDomains[$serviceName] = array_merge($existing, [
                'redirect' => $redirect,
            ]);

            // Keep domain key present when only redirect is set.
            if (! array_key_exists('domain', $allDomains[$serviceName])) {
                $allDomains[$serviceName]['domain'] = null;
            }

            $this->application->docker_compose_domains = json_encode($allDomains);
            $this->application->save();

            $domains = $this->currentDomainList($serviceName);
            if (! $this->assertRedirectDomainsPresent($redirect, $domains)) {
                return;
            }

            $this->pendingAction = null;
            $this->pendingRedirectService = null;
            $this->forceSaveDomains = false;
            $this->resetDefaultLabels();
            if ($this->notifyRedirectUpdate) {
                $this->dispatch('success', "Redirect updated for {$serviceName}.");
            }
            $this->refreshDomains();
            $this->queueUrlsDns($addedDomains, $serviceName);
            $this->pruneDomainDnsStatusesToCurrentDomains();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
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

    /**
     * @param  Collection<int, string>  $previousServiceUrls
     * @param  array<string, int>|null  $incomingOverrides
     * @return array<string, int>|null
     */
    protected function mergeComposeDomainPortOverrides(
        Collection $previousServiceUrls,
        ?string $newDomainString,
        ?array $incomingOverrides,
    ): ?array {
        $merged = $this->application->domain_port_overrides ?? [];
        $newCanonical = collect($this->splitDomains($newDomainString))
            ->map(fn (string $url): string => DomainPortOverrides::withoutPort($url))
            ->all();

        foreach ($previousServiceUrls as $url) {
            $canonical = DomainPortOverrides::withoutPort($url);
            if (! in_array($canonical, $newCanonical, true)) {
                unset($merged[$canonical]);
            }
        }

        foreach ($incomingOverrides ?? [] as $url => $port) {
            $merged[$url] = (int) $port;
        }

        return $merged ?: null;
    }

    protected function resetDefaultLabels(): void
    {
        try {
            if (! $this->application->settings->is_container_label_readonly_enabled) {
                return;
            }

            $customLabels = str(implode('|coolify|', generateLabelsApplication($this->application)))->replace('|coolify|', "\n");
            $this->application->custom_labels = base64_encode($customLabels);
            $this->application->save();
            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Build the www/non-www counterpart URL for a host.
     *
     * @param  bool  $forRedirectPairing  When true, also pair sslip/nip hosts so www↔non-www
     *                                    redirects can be configured (suggestions still skip them).
     */
    protected function wwwCounterpartUrl(string $url, bool $forRedirectPairing = false): ?string
    {
        $host = $this->domainHost($url);
        if ($host === null) {
            return null;
        }

        $lowerHost = strtolower($host);

        // Always skip bare IPs and localhost.
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || $lowerHost === 'localhost') {
            return null;
        }

        // Optional suggestions skip auto-generated hosts; redirect pairing includes them so
        // Set Direction can promote the missing side to a real domain.
        if (
            ! $forRedirectPairing
            && (str_contains($lowerHost, 'sslip.io') || str_contains($lowerHost, 'nip.io'))
        ) {
            return null;
        }

        $counterpartHost = str_starts_with($lowerHost, 'www.')
            ? substr($host, 4)
            : 'www.'.$host;

        if ($counterpartHost === '' || $counterpartHost === $host) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return "{$scheme}://{$counterpartHost}{$port}{$path}{$query}{$fragment}";
    }


    public function render()
    {
        return view('livewire.project.application.domains');
    }
}
