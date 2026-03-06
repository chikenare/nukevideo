<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class DockerService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = 'http://docker-manager:2375';
    }

    public function getSwarmInfo(): array
    {
        $response = Http::get("{$this->baseUrl}/swarm");

        if (!$response->successful()) {
            throw new RuntimeException('Failed to get swarm info: ' . $response->body());
        }

        return $response->json();
    }

    public function getSwarmManagerIp(): string
    {
        $response = Http::get("{$this->baseUrl}/info");

        if (!$response->successful()) {
            throw new RuntimeException('Failed to get docker info: ' . $response->body());
        }

        $info = $response->json();

        return $info['Swarm']['NodeAddr'];
    }

    public function getSwarmJoinToken(): string
    {
        $swarm = $this->getSwarmInfo();

        return $swarm['JoinTokens']['Worker'];
    }

    public function getConfigContent(string $name): string
    {
        $response = Http::get("{$this->baseUrl}/configs", [
            'filters' => json_encode(['name' => [$name]]),
        ]);

        if (!$response->successful()) {
            throw new RuntimeException("Failed to list docker configs: " . $response->body());
        }

        $configs = $response->json();

        if (empty($configs)) {
            throw new RuntimeException("Docker config '{$name}' not found");
        }

        $data = $configs[0]['Spec']['Data'] ?? null;

        if (!$data) {
            throw new RuntimeException("Docker config '{$name}' has no data");
        }

        return base64_decode($data);
    }
}
