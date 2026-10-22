<?php

namespace App\Support;

/**
 * How a database type is reached through the database proxy.
 *
 * Every database of a type on a server shares one published port, and the proxy picks the
 * database by the hostname of the TLS handshake (SNI). The port of the protocol is the port the
 * proxy publishes; the container port is the port the database listens on inside the container.
 */
class DatabaseProxyProtocol
{
    public function __construct(
        public readonly string $name,
        public readonly int $port,
        public readonly int $tlsPort,
    ) {}

    /**
     * The protocol of a database type, or null when the type cannot be exposed through the proxy.
     */
    public static function forType(string $type): ?self
    {
        return match ($type) {
            'standalone-postgresql', 'standalone-postgis', 'standalone-timescale', 'standalone-supabase/postgres' => new self('postgres', 5432, 5432),
            'standalone-mysql', 'standalone-mariadb' => new self('mysql', 3306, 3306),
            'standalone-redis', 'standalone-keydb', 'standalone-dragonfly' => new self('redis', 6379, 6380),
            'standalone-mongodb' => new self('mongodb', 27017, 27017),
            'standalone-clickhouse' => new self('clickhouse', 9000, 9000),
            default => null,
        };
    }

    /**
     * The entrypoint of the proxy that serves this protocol.
     */
    public function entrypoint(): string
    {
        return "db-{$this->name}";
    }

    /**
     * The port the proxy publishes for this protocol.
     */
    public function publishedPort(): string
    {
        return "{$this->port}:{$this->port}";
    }

    /**
     * The flag that declares the entrypoint in the proxy configuration.
     */
    public function entrypointFlag(): string
    {
        return "--entrypoints.{$this->entrypoint()}.address=:{$this->port}";
    }
}
