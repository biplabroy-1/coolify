<?php

use Symfony\Component\Process\Process;

function boostMcpFakeDocker(string $dir): string
{
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/docker', <<<'BASH'
#!/usr/bin/env bash
printf 'docker %s\n' "$*" >> "$DEV_TEST_LOG"
if [[ "$1" == "inspect" ]]; then echo "${DEV_TEST_RUNNING:-true}"; fi
exit 0
BASH);
    chmod($dir.'/docker', 0755);

    return $dir;
}

/**
 * @return array{0: Process, 1: string} The process result and the fake docker call log.
 */
function runBoostMcpScript(array $env = []): array
{
    $root = sys_get_temp_dir().'/boost-mcp-script-'.bin2hex(random_bytes(4));
    $bin = boostMcpFakeDocker($root.'/bin');
    $log = $root.'/docker.log';

    $process = new Process(['bash', 'scripts/boost-mcp'], dirname(__DIR__, 2), [
        'PATH' => $bin.':'.getenv('PATH'),
        'DEV_TEST_LOG' => $log,
        'COOLIFY_DEV_INSTANCE' => 'test-boost',
        ...$env,
    ]);
    $process->run();

    $calls = file_exists($log) ? file_get_contents($log) : '';
    Process::fromShellCommandline('rm -rf '.escapeshellarg($root))->run();

    return [$process, $calls];
}

it('runs the boost mcp server inside the dev container of the instance', function () {
    [$process, $calls] = runBoostMcpScript();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($calls)->toContain('exec -i coolify-dev-test-boost php artisan boost:mcp');
});

it('fails with a clear message when the dev container is not running', function () {
    [$process, $calls] = runBoostMcpScript(['DEV_TEST_RUNNING' => 'false']);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain("container 'coolify-dev-test-boost' is not running")
        ->and($calls)->not->toContain('exec -i');
});

it('points every mcp config at the container wrapper', function () {
    $commands = [];

    foreach (['opencode.json', '.mcp.json', '.cursor/mcp.json'] as $file) {
        $config = json_decode(file_get_contents(dirname(__DIR__, 2).'/'.$file), true, flags: JSON_THROW_ON_ERROR);
        $server = $config['mcp']['laravel-boost'] ?? $config['mcpServers']['laravel-boost'];
        $command = $server['command'];
        $commands[$file] = is_array($command) ? implode(' ', $command) : $command;
    }

    expect($commands)->toBe([
        'opencode.json' => './scripts/boost-mcp',
        '.mcp.json' => './scripts/boost-mcp',
        '.cursor/mcp.json' => './scripts/boost-mcp',
    ]);
});
