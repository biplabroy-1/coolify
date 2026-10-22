<?php

use App\Actions\Database\ApplyDatabaseDomain;
use App\Livewire\Project\Database\Postgresql\General as PostgresqlGeneral;
use App\Models\DnsProviderZone;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\DatabaseProxy\DatabaseProxyConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);

    $this->team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

test('the database proxy publishes one port per protocol', function () {
    create_standalone_postgresql($this->environment->id, $this->destination, [
        'is_public' => true,
        'domain' => 'db.example.com',
    ]);

    $compose = DatabaseProxyConfiguration::compose($this->server);

    expect($compose['services']['traefik']['ports'])->toContain('5432:5432')
        ->and($compose['services']['traefik']['command'])->toContain('--entrypoints.db-postgres.address=:5432')
        ->and($compose['services']['traefik']['container_name'])->toBe('coolify-db-proxy');
});

test('every database of a protocol shares the port of the protocol', function (string $factory, string $entrypoint, int $port) {
    create_standalone_redis($this->environment->id, $this->destination, [
        'is_public' => true,
        'domain' => 'redis.example.com',
    ]);
    create_standalone_keydb($this->environment->id, $this->destination, [
        'is_public' => true,
        'domain' => 'keydb.example.com',
    ]);

    $compose = DatabaseProxyConfiguration::compose($this->server);

    expect($compose['services']['traefik']['ports'])->toContain("{$port}:{$port}")
        ->and($compose['services']['traefik']['command'])->toContain("--entrypoints.{$entrypoint}.address=:{$port}")
        ->and($compose['services']['traefik']['ports'])->toHaveCount(1);
})->with([
    ['create_standalone_redis', 'db-redis', 6379],
]);

test('the routing sends the domain to the database container', function () {
    $database = create_standalone_postgresql($this->environment->id, $this->destination, [
        'is_public' => true,
        'domain' => 'db.example.com',
    ]);

    $routing = DatabaseProxyConfiguration::routing($this->server);
    $router = $routing['tcp']['routers']['db-'.$database->uuid];

    expect($router['rule'])->toBe('HostSNI(`db.example.com`)')
        ->and($router['entryPoints'])->toBe(['db-postgres'])
        ->and($router['service'])->toBe('db-'.$database->uuid)
        ->and($routing['tcp']['services']['db-'.$database->uuid]['loadBalancer']['servers'][0]['address'])->toBe($database->uuid.':5432');
});

test('a private database is not routed by the database proxy', function () {
    create_standalone_postgresql($this->environment->id, $this->destination, [
        'domain' => 'db.example.com',
    ]);

    expect(DatabaseProxyConfiguration::databases($this->server))->toHaveCount(0)
        ->and(DatabaseProxyConfiguration::routing($this->server)['tcp']['routers'])->toBe([]);
});

test('the routing passes the tls through when the zone is not managed by cloudflare', function () {
    $database = create_standalone_postgresql($this->environment->id, $this->destination, [
        'is_public' => true,
        'domain' => 'db.example.com',
        'enable_ssl' => true,
    ]);

    $routing = DatabaseProxyConfiguration::routing($this->server);

    expect($routing['tcp']['routers']['db-'.$database->uuid]['tls'])->toBe(['passthrough' => true]);
});

test('the routing uses a public certificate when the zone is managed by cloudflare', function () {
    $token = IntegrationToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'capabilities' => ['dns'],
        'token' => 'cf-token',
    ]);
    DnsProviderZone::factory()->create([
        'integration_token_id' => $token->id,
        'name' => 'example.com',
        'provider_zone_id' => 'zone-1',
    ]);

    $database = create_standalone_postgresql($this->environment->id, $this->destination, [
        'is_public' => true,
        'domain' => 'db.example.com',
    ]);

    $routing = DatabaseProxyConfiguration::routing($this->server);
    $compose = DatabaseProxyConfiguration::compose($this->server);

    expect($routing['tcp']['routers']['db-'.$database->uuid]['tls'])->toBe(['certResolver' => 'letsencrypt'])
        ->and($compose['services']['traefik']['environment'])->toHaveKey('CF_DNS_API_TOKEN_zone-1');
});

test('the action starts the database proxy', function () {
    Process::fake();

    $database = create_standalone_postgresql($this->environment->id, $this->destination, [
        'is_public' => true,
        'domain' => 'db.example.com',
        'enable_ssl' => true,
        'status' => 'running:healthy',
    ]);

    ApplyDatabaseDomain::run($database);

    expect($this->server->refresh()->proxy->get('db_status'))->toBe('running');
    Process::assertRan(fn ($process) => str_contains($process->command, 'coolify-db-proxy'));
});

test('the general component saves the domain', function () {
    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->actingAs($user);

    $database = create_standalone_postgresql($this->environment->id, $this->destination, [
        'is_public' => true,
        'enable_ssl' => true,
        'status' => 'running:healthy',
        'postgres_user' => 'postgres',
        'postgres_db' => 'postgres',
        'is_log_drain_enabled' => false,
    ]);

    Livewire::test(PostgresqlGeneral::class, ['database' => $database])
        ->set('domain', 'DB.Example.com')
        ->call('submit')
        ->assertHasNoErrors();

    expect($database->refresh()->domain)->toBe('db.example.com');
    ApplyDatabaseDomain::assertPushed();
});

test('the general component refuses a domain without a certificate', function () {
    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->actingAs($user);

    $database = create_standalone_postgresql($this->environment->id, $this->destination, [
        'is_public' => true,
        'status' => 'running:healthy',
        'postgres_user' => 'postgres',
        'postgres_db' => 'postgres',
        'is_log_drain_enabled' => false,
    ]);

    Livewire::test(PostgresqlGeneral::class, ['database' => $database])
        ->set('domain', 'db.example.com')
        ->call('submit');

    expect($database->refresh()->domain)->toBeNull();
});
