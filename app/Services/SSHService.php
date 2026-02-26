<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class SSHService
{
    public function run(
        string $ip,
        string $privateKey,
        string $command,
        int $timeout = 15,
        ?string $input = null,
        ?\Closure $onOutput = null,
    ) {
        $keyFile = null;

        $formattedKey = trim($privateKey) . "\n";

        $keyFile = storage_path('app/ssh_key_' . Str::random(10));

        file_put_contents($keyFile, $formattedKey);
        chmod($keyFile, 0600);

        $sshFlags = "-o StrictHostKeyChecking=no -o ConnectTimeout=5 -o BatchMode=yes -i " . escapeshellarg($keyFile);

        $fullCommand = "ssh {$sshFlags} root@{$ip} " . escapeshellarg($command);

        $result = Process::timeout($timeout)
            ->when($input !== null, function ($p) use ($input) {
                return $p->input($input);
            })
            ->run($fullCommand, function ($type, $output) use ($onOutput) {
                if ($onOutput) {
                    $onOutput($output);
                }
            });
        return $result->output();
    }
}