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


    public function getService(string $name): ?array
    {
        $response = Http::get("{$this->baseUrl}/services", [
            'filters' => json_encode(['name' => [$name]]),
        ]);

        if (!$response->successful()) {
            return null;
        }

        foreach ($response->json() as $service) {
            if ($service['Spec']['Name'] === $name) {
                return $service;
            }
        }

        return null;
    }

    public function deployService(string $name, array $spec): void
    {
        $spec['Name'] = $name;

        $existing = $this->getService($name);

        if ($existing) {
            $version = $existing['Version']['Index'];
            $forceUpdate = ($existing['Spec']['TaskTemplate']['ForceUpdate'] ?? 0) + 1;
            $spec['TaskTemplate']['ForceUpdate'] = $forceUpdate;

            $response = Http::post("{$this->baseUrl}/services/{$existing['ID']}/update?version={$version}", $spec);

            if (!$response->successful()) {
                throw new RuntimeException("Failed to update service '{$name}': " . $response->body());
            }
        } else {
            $response = Http::post("{$this->baseUrl}/services/create", $spec);

            if (!$response->successful()) {
                throw new RuntimeException("Failed to create service '{$name}': " . $response->body());
            }
        }
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
