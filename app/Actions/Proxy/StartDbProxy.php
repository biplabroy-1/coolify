<?php

namespace App\Actions\Proxy;

use App\Models\Server;
use App\Services\DatabaseProxy\DatabaseProxyConfiguration;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Symfony\Component\Yaml\Yaml;

class StartDbProxy
{
    use AsAction;

    public function configureJob(JobDecorator $job): void
    {
        $job->onQueue(deployment_queue());
    }

    /**
     * Start the database proxy of the server.
     *
     * The proxy publishes one port per database protocol and routes the traffic of a database
     * domain to the database container. The routing is written as dynamic configuration files, so
     * the database containers need no labels and the proxy of the server is not touched.
     */
    public function handle(Server $server, bool $async = true): string
    {
        $databases = DatabaseProxyConfiguration::databases($server);
        if ($databases->isEmpty()) {
            return StopDbProxy::run($server);
        }

        $path = $server->databaseProxyPath();
        $compose = Yaml::dump(DatabaseProxyConfiguration::compose($server), 10, 2);
        $routing = Yaml::dump(DatabaseProxyConfiguration::routing($server), 10, 2);

        $server->proxy->set('db_status', 'starting');
        $server->save();

        $commands = [
            "mkdir -p {$path}/dynamic",
            "echo '".base64_encode($compose)."' | base64 -d | tee {$path}/docker-compose.yml > /dev/null",
            "echo '".base64_encode($routing)."' | base64 -d | tee {$path}/dynamic/databases.yml > /dev/null",
        ];

        $networks = $databases->map(fn ($database) => $database->proxyNetwork())->filter()->unique();
        foreach ($networks as $network) {
            $commands[] = dockerNetworkEnsureCommand($network, overlay: $server->isSwarm(), quietCreate: true);
        }

        $commands[] = 'docker rm -f '.DatabaseProxyConfiguration::CONTAINER_NAME.' 2>/dev/null || true';
        if ($server->isSwarmManager()) {
            $commands[] = "docker stack deploy --detach=true -c {$path}/docker-compose.yml coolify-db-proxy";
        } else {
            $commands[] = "docker compose -f {$path}/docker-compose.yml up -d";
        }

        // The proxy reaches the database containers through the networks they share.
        foreach ($networks as $network) {
            $safe = escapeshellarg($network);
            $commands[] = "docker network connect {$safe} ".DatabaseProxyConfiguration::CONTAINER_NAME.' >/dev/null 2>&1 || true';
        }

        if ($async) {
            return remote_process($commands, $server, queue: deployment_queue());
        }

        instant_remote_process($commands, $server);

        $server->proxy->set('db_status', 'running');
        $server->save();

        return 'OK';
    }
}
