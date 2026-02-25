<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class SSHService
{
    public function run(string $ip, string $command, int $timeout = 15)
    {
        $result = Process::timeout($timeout)->run(
            "ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 nodexd@{$ip} " .
            escapeshellarg($command)
        );

        $result->throw();

        return $result->output();
    }

}
