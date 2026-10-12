<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class RenameServerHostname
{
    use AsAction;

    public function handle(Server $server, string $newName): void
    {
        $hostname = trim(substr(preg_replace('/[^a-zA-Z0-9-]/', '-', $newName), 0, 63), '-');
        if ($hostname === '') {
            return;
        }

        $oldHostname = trim(instant_remote_process(['hostname'], $server, false) ?? '');
        $commands = ['hostnamectl set-hostname '.escapeshellarg($hostname).' 2>/dev/null || echo '.escapeshellarg($hostname).' > /etc/hostname'];

        // Old hostname comes from the remote host: regex-escape it and shell-quote the whole sed expression.
        if (preg_match('/^[a-zA-Z0-9.-]+$/', $oldHostname) && $oldHostname !== $hostname) {
            $expr = 's/\b'.preg_quote($oldHostname, '/').'\b/'.$hostname.'/g';
            $commands[] = 'sed -i '.escapeshellarg($expr).' /etc/hosts 2>/dev/null || true';
        }

        instant_remote_process($commands, $server, false);
    }
}
