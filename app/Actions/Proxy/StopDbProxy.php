<?php

namespace App\Actions\Proxy;

use App\Models\Server;
use App\Services\DatabaseProxy\DatabaseProxyConfiguration;
use Lorisleiva\Actions\Concerns\AsAction;

class StopDbProxy
{
    use AsAction;

    /**
     * Stop the database proxy of the server.
     */
    public function handle(Server $server): string
    {
        $commands = [
            'docker rm -f '.DatabaseProxyConfiguration::CONTAINER_NAME.' 2>/dev/null || true',
        ];

        if ($server->isSwarmManager()) {
            $commands[] = 'docker stack rm coolify-db-proxy 2>/dev/null || true';
        }

        instant_remote_process($commands, $server);

        $server->proxy->set('db_status', 'stopped');
        $server->save();

        return 'OK';
    }
}
