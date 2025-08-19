<?php

namespace Gbonnaire\PhpGitlabCicdWebhook\Service\Deployment;

use Gbonnaire\PhpGitlabCicdWebhook\Service\LoggerService;

class BaseDeploymentService
{
    protected array $rollbackStack = [];
    protected string $currentCommit = '';
    
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

    protected function getCurrentCommit(): string
    {
        $result = $this->runCommand("git rev-parse HEAD");
        return $result['success'] ? trim($result['output']) : '';
    }

    protected function addRollbackStep(string $stepName, callable $rollbackAction): void
    {
        $this->rollbackStack[] = [
            'step' => $stepName,
            'action' => $rollbackAction
        ];
    }

    public function up(): array
    {
        $this->rollbackStack = [];
        $this->currentCommit = $this->getCurrentCommit();
        
        $steps = $this->getUpSteps();
        $results = [];
        
        foreach ($steps as $stepName => $stepAction) {
            $this->logger->info("Executing step: {$stepName}", $this->repository['name']);
            
            $result = $stepAction();
            $results[$stepName] = $result;
            
            if (!$result['success']) {
                $this->logger->error("Step failed: {$stepName}", $this->repository['name']);
                $rollbackResult = $this->down();
                return [
                    'success' => false,
                    'failed_step' => $stepName,
                    'results' => $results,
                    'rollback' => $rollbackResult
                ];
            }
        }
        
        return ['success' => true, 'results' => $results];
    }

    public function down(): array
    {
        $rollbackResults = [];
        
        if (!empty($this->currentCommit)) {
            $this->logger->info("Rolling back to commit: {$this->currentCommit}", $this->repository['name']);
            $rollbackResults['git_reset'] = $this->gitReset($this->currentCommit);
        }
        
        while (!empty($this->rollbackStack)) {
            $rollbackStep = array_pop($this->rollbackStack);
            $stepName = $rollbackStep['step'];
            $rollbackAction = $rollbackStep['action'];
            
            $this->logger->info("Rolling back step: {$stepName}", $this->repository['name']);
            
            try {
                $result = $rollbackAction();
                $rollbackResults["rollback_{$stepName}"] = $result;
            } catch (\Exception $e) {
                $this->logger->error("Rollback failed for step {$stepName}: " . $e->getMessage(), $this->repository['name']);
                $rollbackResults["rollback_{$stepName}"] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return ['success' => true, 'rollback_results' => $rollbackResults];
    }

    protected function getUpSteps(): array
    {
        return [
            'git_pull' => function() {
                $result = $this->gitPull();
                if ($result['success']) {
                    $this->addRollbackStep('git_pull', fn() => $this->gitReset($this->currentCommit));
                }
                return $result;
            },
            'composer_install' => function() {
                $result = $this->composerInstall();
                if ($result['success']) {
                    $this->addRollbackStep('composer_install', fn() => $this->runCommand('composer install --no-dev --optimize-autoloader'));
                }
                return $result;
            }
        ];
    }
}