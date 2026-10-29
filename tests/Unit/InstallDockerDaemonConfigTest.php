<?php

use App\Actions\Server\InstallDocker;
use App\Models\Server;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    Mockery::close();
});

/**
 * Run the parsed lines the way remote_process does (bash -se) with /etc/docker
 * redirected to a scratch dir and sudo replaced by a no-op.
 *
 * @param  list<string>  $commands
 */
function runDaemonConfigCommands(array $commands, string $dir, ?string $existing): array
{
    if ($existing !== null) {
        file_put_contents("$dir/daemon.json", $existing);
    }
    $script = "sudo() { \"\$@\"; }\n".str_replace('/etc/docker', $dir, implode("\n", $commands))."\necho reached-the-end\n";
    $result = Process::input($script)->run('bash -se');

    return [$result->exitCode(), $result->output(), file_exists("$dir/daemon.json") ? json_decode(file_get_contents("$dir/daemon.json"), true) : null];
}

$config = base64_encode('{"log-driver": "json-file", "log-opts": {"max-size": "10m", "max-file": "3"}}');

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/coolify-daemon-'.uniqid();
    mkdir($this->dir);
});

afterEach(function () {
    array_map('unlink', glob("{$this->dir}/*"));
    rmdir($this->dir);
});

it('merges the required settings into an existing daemon.json on a server with a non-root user', function () use ($config) {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
    $parsed = parseCommandsByLineForSudo(collect(InstallDocker::daemonConfigCommands($config)), $server);

    [$exit, $output, $daemon] = runDaemonConfigCommands($parsed, $this->dir, '{"bip": "172.17.0.1/16"}');

    expect($exit)->toBe(0)
        ->and($output)->toContain('reached-the-end')
        ->and($daemon)->toEqualCanonicalizing(['bip' => '172.17.0.1/16', 'log-driver' => 'json-file', 'log-opts' => ['max-size' => '10m', 'max-file' => '3']])
        ->and(glob("{$this->dir}/daemon.json.original-*"))->toHaveCount(1);
});

it('creates daemon.json when the server has none, as root and as a non-root user', function (?string $user) use ($config) {
    $commands = InstallDocker::daemonConfigCommands($config);
    if ($user) {
        $server = Mockery::mock(Server::class)->makePartial();
        $server->shouldReceive('getAttribute')->with('user')->andReturn($user);
        $commands = parseCommandsByLineForSudo(collect($commands), $server);
    }

    [$exit, $output, $daemon] = runDaemonConfigCommands($commands, $this->dir, null);

    expect($exit)->toBe(0)
        ->and($output)->toContain('reached-the-end')
        ->and($daemon['log-driver'])->toBe('json-file');
})->with(['root' => [null], 'ubuntu' => ['ubuntu']]);
