<?php

namespace App;

class Config
{
    private string $configFile = './repositories.json';
    private array $repositories = [];

    public function __construct()
    {
        $this->loadConfig();
    }

    public function addRepository(array $config): void
    {
        $this->repositories[$config['name']] = $config;
        $this->saveConfig();
    }

    public function removeRepository(string $name): void
    {
        unset($this->repositories[$name]);
        $this->saveConfig();
    }

    public function getRepository(string $name): ?array
    {
        return $this->repositories[$name] ?? null;
    }

    public function getAllRepositories(): array
    {
        return $this->repositories;
    }

    public function hasRepository(string $name): bool
    {
        return isset($this->repositories[$name]);
    }

    private function loadConfig(): void
    {
        if (file_exists($this->configFile)) {
            $content = file_get_contents($this->configFile);
            $this->repositories = json_decode($content, true) ?? [];
        }
    }

    private function saveConfig(): void
    {
        file_put_contents($this->configFile, json_encode($this->repositories, JSON_PRETTY_PRINT));
    }
}