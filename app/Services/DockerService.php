<?php

namespace App\Services;

use App\Models\Node;

class DockerService
{
    public function __construct(
        private SSHService $ssh,
    ) {}

    /**
     * Run a docker command on a node via SSH.
     */
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

    /**
     * Deploy a container: pull image, remove old container if exists, run new one.
     */
    public function deployContainer(Node $node, string $name, string $image, array $options = []): void
    {
        if(!app()->isLocal()) {
            $this->run($node, "pull {$image}", 120);
        }

        // Stop and remove existing container (ignore errors if not found)
        $this->run($node, "rm -f {$name}");

        $cmd = "run -d --name {$name} --restart unless-stopped";

        foreach ($options['env'] ?? [] as $env) {
            $cmd .= ' -e '.escapeshellarg($env);
        }

        foreach ($options['volumes'] ?? [] as $volume) {
            $cmd .= ' -v '.escapeshellarg($volume);
        }

        foreach ($options['ports'] ?? [] as $port) {
            $cmd .= " -p {$port}";
        }

        foreach ($options['labels'] ?? [] as $label) {
            $cmd .= ' -l '.escapeshellarg($label);
        }

        if (isset($options['network'])) {
            $cmd .= " --network {$options['network']}";
        }

        if (isset($options['cpus'])) {
            $cmd .= " --cpus={$options['cpus']}";
        }

        if (isset($options['memory'])) {
            $cmd .= " --memory={$options['memory']}";
        }

        if (isset($options['command'])) {
            $cmd .= " {$image} {$options['command']}";
        } else {
            $cmd .= " {$image}";
        }

        $this->run($node, $cmd, 60);
    }

    /**
     * Remove a container by name.
     */
    public function removeContainer(Node $node, string $name): void
    {
        $this->run($node, "rm -f {$name}");
    }

    /**
     * List containers with their status on a node.
     * Returns array of containers with name, state, status fields.
     */
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

    /**
     * Get containers status grouped by node ID.
     */
    public function getServicesStatus(): array
    {
        $nodes = Node::with('sshKey')->get();
        $result = [];

        foreach ($nodes as $node) {
            try {
                $containers = $this->listContainers($node);
            } catch (\Throwable) {
                continue;
            }

            $prefix = 'nukevideo_';

            foreach ($containers as $container) {
                $name = $container['Names'] ?? '';
                if (! str_starts_with($name, $prefix)) {
                    continue;
                }

                $state = $container['State'] ?? 'unknown';
                $running = $state === 'running' ? 1 : 0;

                $result[$node->id][] = [
                    'name' => $name,
                    'running' => $running,
                    'desired' => null,
                    'state' => $running ? 'running' : 'down',
                ];
            }
        }

        return $result;
    }
}
