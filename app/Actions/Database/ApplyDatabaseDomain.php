<?php

namespace App\Actions\Database;

use App\Actions\Proxy\StartDbProxy;
use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Services\DatabaseProxy\DatabaseProxyConfiguration;
use App\Services\Dns\CloudflareDnsProvider;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;

class ApplyDatabaseDomain
{
    use AsAction;

    public function configureJob(JobDecorator $job): void
    {
        $job->onQueue(deployment_queue());
    }

    /**
     * Expose a database with its domain.
     *
     * The database proxy publishes one port per database protocol and picks the database by the
     * hostname of the TLS handshake, so the database needs no TCP proxy container and no port of
     * its own. The routing is written as dynamic configuration of the database proxy.
     */
    public function handle(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|ServiceDatabase $database): void
    {
        $server = $database->proxyServer();
        if (! $server) {
            return;
        }

        if ($database->hasProxyDomain()) {
            // The domain replaces the TCP proxy container of the database.
            StopDatabaseProxy::run($database);

            $publicCertificate = DatabaseProxyConfiguration::usesPublicCertificate($database);
            if (! $publicCertificate) {
                // The proxy forwards the encrypted traffic to the database, so the database serves
                // the certificate itself.
                if (! $database->enable_ssl) {
                    throw new \Exception('A database with a domain needs its own certificate when the zone of the domain is not managed by Cloudflare. Enable SSL on the database, or add a Cloudflare integration for the zone.');
                }
                if (method_exists($database, 'sslCertificates')) {
                    $database->sslCertificates()->delete();
                }
            } elseif ($database->proxyDomainConflictsWithDatabaseTls()) {
                // The proxy terminates TLS, so the database accepts the plaintext of the proxy.
                $database->ssl_mode = 'prefer';
                $database->save();
            }

            $this->createDnsRecord($database, $server);
        }

        StartDbProxy::run($server, async: false);

        // The database serves its own certificate in the passthrough mode, so it has to be
        // recreated to load it.
        if ($database->hasProxyDomain() && ! DatabaseProxyConfiguration::usesPublicCertificate($database) && $database->isRunning()) {
            RestartDatabase::run($database);
        }
    }

    /**
     * Point the domain at the server when the zone is managed by Cloudflare.
     */
    private function createDnsRecord(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|ServiceDatabase $database, Server $server): void
    {
        $teamId = $database->team()?->id;
        $ip = $server->getIp;
        if (! $teamId || blank($ip) || blank($database->domain)) {
            return;
        }

        try {
            $provider = app(CloudflareDnsProvider::class);
            $zone = $provider->findZones($teamId, $database->domain)->first();
            if (! $zone) {
                return;
            }
            // A database is reached on a port that Cloudflare does not proxy, so the record stays
            // on the DNS only.
            $provider->createRecord($zone, $database->domain, $ip, $database, proxied: false);
        } catch (\Throwable $e) {
            Log::error('Could not create the DNS record of the database domain.', [
                'database_id' => $database->id,
                'domain' => $database->domain,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
