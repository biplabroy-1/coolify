<?php

namespace App\Services\DatabaseProxy;

use App\Models\DnsProviderZone;
use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Services\Dns\CloudflareDnsProvider;
use App\Support\DatabaseProxyProtocol;
use Illuminate\Support\Collection;

/**
 * The configuration of the database proxy.
 *
 * The database proxy is a dedicated Traefik container on the server. It publishes one port per
 * database protocol and routes the traffic of a database domain to the database container. The
 * routing is written as dynamic configuration files, so the database containers need no labels
 * and the proxy configuration of the server is not touched.
 */
class DatabaseProxyConfiguration
{
    public const CONTAINER_NAME = 'coolify-db-proxy';

    public const CERTIFICATE_RESOLVER = 'letsencrypt';

    /**
     * The databases with a domain on this server.
     */
    public static function databases(Server $server): Collection
    {
        $standalone = $server->databases()
            ->filter(fn ($database) => $database->hasProxyDomain())
            ->values();

        $service = ServiceDatabase::query()
            ->whereIn('service_id', $server->services()->select('id'))
            ->get()
            ->filter(fn ($database) => $database->hasProxyDomain())
            ->values();

        return $standalone->concat($service)->values();
    }

    /**
     * The protocols in use on this server.
     */
    public static function protocols(Server $server): Collection
    {
        return self::databases($server)
            ->map(fn ($database) => $database->proxyProtocol())
            ->filter()
            ->unique(fn (DatabaseProxyProtocol $protocol) => $protocol->name)
            ->values();
    }

    /**
     * The entrypoints of the proxy: the shared port of a protocol and the ports of the databases
     * that need one of their own.
     *
     * @return array<string, array{entrypoint: string, port: int}>
     */
    public static function entries(Server $server): array
    {
        $entries = [];

        foreach (self::databases($server) as $database) {
            $protocol = $database->proxyProtocol();
            if ($protocol === null) {
                continue;
            }
            $dedicatedPort = $database->proxyPort();
            $name = $dedicatedPort ? "db-{$protocol->name}-{$dedicatedPort}" : $protocol->entrypoint();
            $entries[$name] = [
                'entrypoint' => $name,
                'port' => $dedicatedPort ?? $protocol->port,
            ];
        }

        return $entries;
    }

    /**
     * The static configuration of the proxy container.
     */
    public static function compose(Server $server): array
    {
        $command = [
            '--ping=true',
            '--providers.file.directory=/traefik/dynamic/',
            '--providers.file.watch=true',
            '--certificatesresolvers.'.self::CERTIFICATE_RESOLVER.'.acme.storage=/traefik/acme.json',
        ];
        $ports = [];
        foreach (self::entries($server) as $entry) {
            $command[] = "--entrypoints.{$entry['entrypoint']}.address=:{$entry['port']}";
            $ports[] = "{$entry['port']}:{$entry['port']}";
        }

        $networks = self::networks($server);
        $compose = [
            'name' => 'coolify-db-proxy',
            'networks' => [],
            'services' => [
                'traefik' => [
                    'container_name' => self::CONTAINER_NAME,
                    'image' => 'traefik:v3.7',
                    'restart' => RESTART_MODE,
                    'extra_hosts' => [
                        'host.docker.internal:host-gateway',
                    ],
                    'networks' => $networks,
                    'ports' => $ports,
                    'volumes' => [
                        devHostDockerPath($server, $server->databaseProxyPath()).':/traefik',
                    ],
                    'command' => $command,
                ],
            ],
        ];

        $environment = self::cloudflareEnvironment($server);
        if ($environment !== []) {
            $compose['services']['traefik']['environment'] = $environment;
        }

        foreach ($networks as $network) {
            $compose['networks'][$network] = ['external' => true];
        }

        return $compose;
    }

    /**
     * The dynamic routing configuration of the proxy: one router and one service per database.
     */
    public static function routing(Server $server): array
    {
        $routers = [];
        $services = [];

        foreach (self::databases($server) as $database) {
            $protocol = $database->proxyProtocol();
            $container = $database->proxyContainerName();
            if ($protocol === null || $container === null) {
                continue;
            }

            $name = 'db-'.$database->uuid;
            $passthrough = ! self::usesPublicCertificate($database);
            $port = $passthrough && $database->enable_ssl ? $protocol->tlsPort : $protocol->port;
            $dedicatedPort = $database->proxyPort();

            $router = [
                'entryPoints' => [$dedicatedPort ? "db-{$protocol->name}-{$dedicatedPort}" : $protocol->entrypoint()],
                'service' => $name,
            ];
            if (! $dedicatedPort) {
                // The shared port of a protocol picks the database by the hostname of the TLS
                // handshake. A database with a port of its own is the only database on it.
                $router['rule'] = collect($database->proxyDomains())
                    ->map(fn (string $domain): string => "HostSNI(`{$domain}`)")
                    ->implode(' || ');
            }
            if ($passthrough) {
                $router['tls'] = ['passthrough' => true];
            } else {
                $router['tls'] = ['certResolver' => self::CERTIFICATE_RESOLVER];
                if ($dedicatedPort) {
                    // Without a rule the proxy does not know the domain of the certificate.
                    $router['tls']['domains'] = collect($database->proxyDomains())
                        ->map(fn (string $domain): array => ['main' => $domain])
                        ->all();
                }
            }
            $routers[$name] = $router;
            $services[$name] = [
                'loadBalancer' => [
                    'servers' => [
                        ['address' => "{$container}:{$port}"],
                    ],
                ],
            ];
        }

        return [
            'tcp' => [
                'routers' => $routers,
                'services' => $services,
            ],
        ];
    }

    /**
     * Whether the proxy can get a public certificate for the domain of the database: the zone of
     * the domain has to be managed by a Cloudflare integration, because the proxy solves the
     * ACME challenge with it.
     */
    public static function usesPublicCertificate(object $database, ?string $domain = null): bool
    {
        $teamId = $database->team()?->id;
        $domain = $domain ?? $database->domain;
        if (! $teamId || blank($domain)) {
            return false;
        }

        return app(CloudflareDnsProvider::class)->findZones($teamId, $domain)->isNotEmpty();
    }

    /**
     * The Cloudflare tokens of the team, so the proxy can solve the ACME challenge for the
     * domains of the team.
     */
    private static function cloudflareEnvironment(Server $server): array
    {
        $environment = [];

        $zones = DnsProviderZone::query()
            ->whereHas('integrationToken', fn ($query) => $query->where('team_id', $server->team_id)->where('provider', 'cloudflare'))
            ->with('integrationToken')
            ->get();

        foreach ($zones as $zone) {
            $token = $zone->integrationToken?->token;
            if (blank($token) || blank($zone->provider_zone_id)) {
                continue;
            }
            $environment['CF_DNS_API_TOKEN_'.strtolower((string) $zone->provider_zone_id)] = $token;
        }

        return $environment;
    }

    /**
     * The docker networks the proxy shares with the database containers.
     */
    private static function networks(Server $server): array
    {
        return self::databases($server)
            ->map(fn ($database) => $database->proxyNetwork())
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
