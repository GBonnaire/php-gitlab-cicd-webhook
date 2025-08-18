<?php

namespace App;

class Deployer
{
    private Logger $logger;
    private string $repositoriesPath = './repositories';

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        if (!is_dir($this->repositoriesPath)) {
            mkdir($this->repositoriesPath, 0755, true);
        }
    }

    public function deploy(array $repository, array $webhookData): array
    {
        $repoName = $repository['name'];
        $this->logger->info("Starting deployment for {$repoName}", $repoName);

        try {
            // Get current commit before deployment
            $currentCommit = $this->getCurrentCommit($repository['local_path']);
            
            // Load deployment class
            $deploymentFile = $repository['local_path'] . '/deployment.php';
            if (!file_exists($deploymentFile)) {
                throw new \Exception("Deployment file not found: {$deploymentFile}");
            }

            require_once $deploymentFile;
            $deployment = new \Deployment($repository, $this->logger);

            // Try deployment
            $result = $deployment->up($webhookData);
            
            if ($result['success']) {
                $this->logger->info("Deployment successful: " . $result['message'], $repoName);
                return ['success' => true, 'message' => 'Deployment completed successfully'];
            } else {
                throw new \Exception($result['message'] ?? 'Deployment failed');
            }

        } catch (\Exception $e) {
            $this->logger->error("Deployment failed: " . $e->getMessage(), $repoName);

            // Try rollback
            if (isset($deployment) && isset($currentCommit)) {
                try {
                    $this->logger->info("Starting rollback to commit: {$currentCommit}", $repoName);
                    $rollbackResult = $deployment->down($currentCommit);
                    
                    if ($rollbackResult['success']) {
                        $this->logger->info("Rollback successful", $repoName);
                    } else {
                        $this->logger->error("Rollback failed: " . ($rollbackResult['message'] ?? 'Unknown error'), $repoName);
                    }
                } catch (\Exception $rollbackException) {
                    $this->logger->error("Rollback exception: " . $rollbackException->getMessage(), $repoName);
                }
            }

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function getCurrentCommit(string $repositoryPath): string
    {
        $command = "cd {$repositoryPath} && git rev-parse HEAD";
        $output = shell_exec($command);
        return trim($output ?? '');
    }

    public function cloneRepository(string $gitUrl, string $localPath, string $branch = 'main'): bool
    {
        if (is_dir($localPath)) {
            return false; // Directory already exists
        }

        $command = "git clone -b {$branch} {$gitUrl} {$localPath}";
        $this->logger->info("Cloning repository: {$command}");
        
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode === 0) {
            $this->logger->info("Repository cloned successfully to {$localPath}");
            return true;
        } else {
            $this->logger->error("Failed to clone repository: " . implode("\n", $output));
            return false;
        }
    }
}