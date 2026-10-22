<?php

use App\Livewire\Project\Database\Postgresql\General as PostgresqlGeneral;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0]);
    InstanceSettings::find(0)->update(['is_dns_validation_enabled' => false]);
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

test('make db public via tcp proxy', function () {
    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->actingAs($user);

    $database = create_standalone_postgresql($this->environment->id, $this->destination, [
        'is_public' => false,
        'enable_ssl' => false,
        'status' => 'running:healthy',
        'postgres_user' => 'postgres',
        'postgres_db' => 'postgres',
        'is_log_drain_enabled' => false,
        'public_port' => 5432,
    ]);

    $c = Livewire::test(PostgresqlGeneral::class, ['database' => $database])
        ->set('isPublic', true)
        ->call('instantSave');

    dump(['errors' => $c->errors()->toArray(), 'isPublic-after' => $c->get('isPublic')]);
    dump(['is_public-db' => $database->refresh()->is_public]);
});
