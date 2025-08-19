<?php

namespace Gbonnaire\PhpGitlabCicdWebhook\Service;

class BaseDeploymentService
{
    public function __construct(
        private array $repository,
        private LoggerService $logger
    )
    {}

    protected function runCommand(string $command): array
    {
        $fullCommand = "cd {$this->repository['local_path']} && {$command}";
        $this->logger->info("Executing: {$fullCommand}", $this->repository['name']);

        $output = [];
        $returnCode = 0;
        exec($fullCommand . ' 2>&1', $output, $returnCode);

        $result = [
            'success' => $returnCode === 0,
            'output' => implode("\n", $output),
            'command' => $command
        ];

        if (!$result['success']) {
            $this->logger->error("Command failed [{$returnCode}]: {$command} - " . $result['output'], $this->repository['name']);
        }

        return $result;
    }

    protected function gitPull(): array
    {
        return $this->runCommand("git pull origin {$this->repository['branch']}");
    }

    protected function composerInstall(): array
    {
        if (!file_exists($this->repository['local_path'] . '/composer.json')) {
            return ['success' => true, 'message' => 'No composer.json found'];
        }
        return $this->runCommand('composer install --no-dev --optimize-autoloader');
    }

    protected function npmInstall(): array
    {
        if (!file_exists($this->repository['local_path'] . '/package.json')) {
            return ['success' => true, 'message' => 'No package.json found'];
        }
        return $this->runCommand('npm ci');
    }

    protected function npmBuild(): array
    {
        return $this->runCommand('npm run build');
    }

    protected function yarnInstall(): array
    {
        if (!file_exists($this->repository['local_path'] . '/yarn.lock')) {
            return ['success' => true, 'message' => 'No yarn.lock found'];
        }
        return $this->runCommand('yarn install --frozen-lockfile');
    }

    protected function yarnBuild(): array
    {
        return $this->runCommand('yarn build');
    }

    protected function doctrineMigrate(): array
    {
        return $this->runCommand('php bin/console doctrine:migrations:migrate --no-interaction');
    }

    protected function clearCache(): array
    {
        return $this->runCommand('php bin/console cache:clear --env=prod');
    }

    protected function assetMapCompile(): array
    {
        return $this->runCommand('php bin/console asset-map:compile');
    }

    protected function updateEnvFile(array $changes): array
    {
        if (empty($changes)) {
            return ['success' => true, 'message' => 'No environment changes'];
        }

        $envFile = $this->repository['local_path'] . '/.env.local';
        $envContent = [];

        if (file_exists($envFile)) {
            $envContent = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        }

        foreach ($changes as $key => $value) {
            $updated = false;
            foreach ($envContent as $index => $line) {
                if (strpos($line, $key . '=') === 0) {
                    $envContent[$index] = $key . '=' . $value;
                    $updated = true;
                    break;
                }
            }
            if (!$updated) {
                $envContent[] = $key . '=' . $value;
            }
        }

        file_put_contents($envFile, implode("\n", $envContent) . "\n");
        $this->logger->info("Environment file updated", $this->repository['name']);
        
        return ['success' => true, 'message' => 'Environment updated'];
    }

    protected function gitReset(string $commit): array
    {
        return $this->runCommand("git reset --hard {$commit}");
    }
}