<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class SSHService
{
    public function run(
        string $ip,
        ?string $user,
        string $privateKey,
        string $command,
        int $timeout = 15,
        ?string $input = null,
        ?\Closure $onOutput = null,
    ) {
        $formattedKey = trim($privateKey)."\n";

        $keyFile = tempnam(sys_get_temp_dir(), 'ssh_');
        file_put_contents($keyFile, $formattedKey);
        chmod($keyFile, 0600);

        try {
            $sshFlags = '-o StrictHostKeyChecking=no -o ConnectTimeout=5 -o BatchMode=yes -i '.escapeshellarg($keyFile);

            $fullCommand = "ssh {$sshFlags} $user@{$ip} ".escapeshellarg($command);

            $result = Process::timeout($timeout)
                ->when($input !== null, function ($p) use ($input) {
                    return $p->input($input);
                })
                ->run($fullCommand, function ($type, $output) use ($onOutput) {
                    if ($onOutput) {
                        $onOutput($output);
                    }
                });

            if ($result->failed()) {
                throw new \RuntimeException(
                    trim($result->errorOutput() ?: $result->output()) ?: "SSH command failed with exit code {$result->exitCode()}"
                );
            }

            return $result->output();
        } finally {
            @unlink($keyFile);
        }

    }
}
