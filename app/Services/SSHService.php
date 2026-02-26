<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class SSHService
{
    public function upload(string $ip, string $localPath, string $remotePath, int $timeout = 30): void
    {
        $result = Process::timeout($timeout)->run(
            "scp -o StrictHostKeyChecking=no -o ConnectTimeout=5 " .
            escapeshellarg($localPath) . " nodexd@{$ip}:" . escapeshellarg($remotePath),
            function ($type, $output) {
                Log::debug($output);
            }
        );

        $result->throw();
    }

    public function run(string $ip, string $command, int $timeout = 15, ?string $input = null)
    {
        $process = Process::timeout($timeout);

        if ($input !== null) {
            $process = $process->input($input);
        }

        $result = $process->run(
            "ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 root@{$ip} " .
            escapeshellarg($command),
            function ($type, $output) {
                Log::debug($output);
            }
        );

        return $result->output();
    }

}
