<?php

namespace App\Traits;

use App\Actions\Database\ApplyDatabaseDomain;
use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Support\DatabaseProxyProtocol;

trait HasDatabaseProxyDomain
{
    /**
     * The Traefik labels of a container cannot change without recreating it, so a change of the
     * domain or of the public state has to recreate the database container.
     */
    public static function bootHasDatabaseProxyDomain(): void
    {
        static::saved(function (self $database): void {
            $hadDomain = (bool) $database->getOriginal('is_public') && filled($database->getOriginal('domain'));
            if ($hadDomain !== $database->hasProxyDomain()) {
                ApplyDatabaseDomain::dispatch($database);
            }
        });
    }

    /**
     * The protocol this database is reached with through the database proxy.
     */
    public function proxyProtocol(): ?DatabaseProxyProtocol
    {
        return DatabaseProxyProtocol::forType($this->proxyDatabaseType());
    }

    /**
     * The type of the database, for example standalone-postgresql.
     */
    public function proxyDatabaseType(): string
    {
        if ($this instanceof ServiceDatabase) {
            return $this->databaseType();
        }

        return (string) $this->database_type;
    }

    /**
     * A database with a domain is exposed through the database proxy instead of a TCP proxy
     * container of its own, so it needs no public port of its own.
     */
    public function hasProxyDomain(): bool
    {
        return (bool) $this->is_public && filled($this->domain) && $this->proxyProtocol() !== null;
    }

    /**
     * Whether the database is reachable from outside the server.
     */
    public function isPubliclyAccessible(): bool
    {
        return (bool) $this->is_public && (filled($this->domain) || filled($this->public_port));
    }

    /**
     * The server the database runs on.
     */
    public function proxyServer(): ?Server
    {
        if ($this instanceof ServiceDatabase) {
            return $this->service?->server;
        }

        return $this->destination?->server;
    }

    /**
     * The container the proxy forwards the traffic of this database to.
     */
    public function proxyContainerName(): ?string
    {
        if ($this instanceof ServiceDatabase) {
            return filled($this->name) && filled($this->service?->uuid) ? "{$this->name}-{$this->service->uuid}" : null;
        }

        return $this->uuid;
    }

    /**
     * The docker network the database container and the proxy share.
     */
    public function proxyNetwork(): ?string
    {
        if ($this instanceof ServiceDatabase) {
            return $this->service?->uuid;
        }

        return $this->destination?->network;
    }

    /**
     * The host a client connects to: the domain when the database is exposed through the proxy,
     * the server address when it is exposed through a TCP proxy container.
     */
    public function publicEndpointHost(): ?string
    {
        if ($this->hasProxyDomain()) {
            return $this->domain;
        }

        $serverIp = $this->proxyServer()?->getIp;

        return empty($serverIp) ? null : formatHostForUrl($serverIp);
    }

    /**
     * The port a client connects to: the port the proxy publishes for the protocol when the
     * database has a domain, the public port of the TCP proxy container otherwise.
     */
    public function publicEndpointPort(): ?int
    {
        if ($this->hasProxyDomain()) {
            return $this->proxyProtocol()?->port;
        }

        return $this->public_port;
    }

    /**
     * The proxy forwards plaintext to the database container, so a database that rejects
     * plaintext on its own port cannot be routed to by the proxy.
     */
    public function proxyDomainConflictsWithDatabaseTls(): bool
    {
        return $this->type() === 'standalone-mongodb'
            && $this->enable_ssl
            && in_array($this->ssl_mode, ['require', 'verify-full'], true);
    }
}
