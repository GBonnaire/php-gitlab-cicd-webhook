<?php

namespace Gbonnaire\PhpGitlabCicdWebhook\Service;

use Gbonnaire\PhpGitlabCicdWebhook\Service\Deployment\BaseDeploymentService;
use Gbonnaire\PhpGitlabCicdWebhook\Service\Deployment\SymfonyWebpackDeploymentService;
use Gbonnaire\PhpGitlabCicdWebhook\Service\Deployment\SymfonyAssetMapperDeploymentService;
use Gbonnaire\PhpGitlabCicdWebhook\Service\Deployment\SymfonyApiDeploymentService;
use Gbonnaire\PhpGitlabCicdWebhook\Service\Deployment\SimpleDeploymentService;

class DeployerService
{
    private string $repositoriesPath = ROOT_FOLDER.'/repositories';

    public function __construct(
        private LoggerService $logger
    )
    {
        if (!is_dir($this->repositoriesPath)) {
            mkdir($this->repositoriesPath, 0755, true);
        }
    }

    public function deploy(array $repository, array $webhookData): array
    {
        $repoName = $repository['name'];
        $this->logger->info("Starting deployment for {$repoName}", $repoName);

        try {
            $deployer = $this->createDeployer($repository);
            
            if (!$deployer) {
                throw new \Exception("Unknown deployment type: {$repository['type']}");
            }

            $result = $deployer->up();
            
            if ($result['success']) {
                $this->logger->info("Deployment successful", $repoName);
                return ['success' => true, 'message' => 'Deployment completed successfully', 'results' => $result['results'] ?? []];
            } else {
                $this->logger->error("Deployment failed: " . ($result['rollback']['success'] ? 'Rollback successful' : 'Rollback failed'), $repoName);
                return [
                    'success' => false, 
                    'message' => "Deployment failed at step: {$result['failed_step']}",
                    'rollback' => $result['rollback'] ?? []
                ];
            }

        } catch (\Exception $e) {
            $this->logger->error("Deployment failed: " . $e->getMessage(), $repoName);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function createDeployer(array $repository): ?BaseDeploymentService
    {
        return match ($repository['type']) {
            'symfony-webpack' => new SymfonyWebpackDeploymentService($repository, $this->logger),
            'symfony-asset-mapper' => new SymfonyAssetMapperDeploymentService($repository, $this->logger),
            'symfony-api' => new SymfonyApiDeploymentService($repository, $this->logger),
            'simple' => new SimpleDeploymentService($repository, $this->logger),
            default => null
        };
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