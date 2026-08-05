<?php

namespace App\Services;

use App\Models\Node;

class DockerService
{
    public function __construct(
        private SSHService $ssh,
    ) {}

    public function run(Node $node, string $command, int $timeout = 30): string
    {
        return trim($this->ssh->run(
            ip: $node->ip_address,
            user: $node->user,
            privateKey: $node->sshKey->private_key,
            command: "docker {$command}",
            timeout: $timeout,
        ));
    }

    public function removeContainer(Node $node, string $name): void
    {
        $this->run($node, "rm -f {$name}");
    }

    public function listContainers(Node $node): array
    {
        $output = $this->run($node, 'ps -a --format "{{json .}}"');

        if (empty($output)) {
            return [];
        }

        $containers = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            $data = json_decode($line, true);
            if ($data) {
                $containers[] = $data;
            }
        }

        return $containers;
    }
}
