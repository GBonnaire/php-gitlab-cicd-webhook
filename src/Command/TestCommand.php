<?php

namespace Gbonnaire\PhpGitlabCicdWebhook\Command;

use Gbonnaire\PhpGitlabCicdWebhook\Service\ConfigService;
use Gbonnaire\PhpGitlabCicdWebhook\Service\LoggerService;
use Gbonnaire\PhpGitlabCicdWebhook\Service\Deployment\BaseDeploymentService;
use Gbonnaire\PhpGitlabCicdWebhook\Service\Deployment\SymfonyWebpackDeploymentService;
use Gbonnaire\PhpGitlabCicdWebhook\Service\Deployment\SymfonyAssetMapperDeploymentService;
use Gbonnaire\PhpGitlabCicdWebhook\Service\Deployment\SymfonyApiDeploymentService;
use Gbonnaire\PhpGitlabCicdWebhook\Service\Deployment\SimpleDeploymentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test',
    description: 'Test deployment for a repository'
)]
class TestCommand extends Command
{
    public function __construct(
        private ConfigService $config,
        private LoggerService $logger
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('repository', InputArgument::OPTIONAL, 'Repository name to test')
            ->setHelp('This command allows you to test deployment for a repository...');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $repositoryName = $input->getArgument('repository');
        
        if (!$repositoryName) {
            return $this->selectRepository($io);
        }
        
        return $this->testRepository($io, $repositoryName);
    }

    private function selectRepository(SymfonyStyle $io): int
    {
        $repositories = $this->config->getAllRepositories();
        
        if (empty($repositories)) {
            $io->error('No repositories configured. Use app:install to add repositories.');
            return Command::FAILURE;
        }
        
        $io->title('Available Repositories');
        
        $choices = [];
        foreach ($repositories as $name => $repo) {
            $choices[$name] = "{$name} ({$repo['type']}) - {$repo['branch']}";
        }
        
        $question = new ChoiceQuestion(
            'Select repository to test:',
            $choices
        );
        
        $selectedRepo = $io->askQuestion($question);
        
        return $this->testRepository($io, $selectedRepo);
    }

    private function testRepository(SymfonyStyle $io, string $repositoryName): int
    {
        $repository = $this->config->getRepository($repositoryName);
        
        if (!$repository) {
            $io->error("Repository '{$repositoryName}' not found.");
            return Command::FAILURE;
        }
        
        $io->title("Testing Deployment: {$repositoryName}");
        
        $io->definitionList(
            ['Name' => $repository['name']],
            ['Type' => $repository['type']],
            ['Branch' => $repository['branch']],
            ['Local Path' => $repository['local_path']]
        );
        
        $deployer = $this->createDeployer($repository);
        
        if (!$deployer) {
            $io->error("Unknown deployment type: {$repository['type']}");
            return Command::FAILURE;
        }
        
        $io->section('Running Deployment Test');
        
        $result = $deployer->up();
        
        if ($result['success']) {
            $io->success('Deployment test completed successfully!');
            
            if (isset($result['results'])) {
                $io->section('Step Results');
                foreach ($result['results'] as $step => $stepResult) {
                    $status = $stepResult['success'] ? '✓' : '✗';
                    $io->writeln("  {$status} {$step}");
                }
            }
        } else {
            $io->error('Deployment test failed!');
            
            if (isset($result['failed_step'])) {
                $io->writeln("Failed at step: {$result['failed_step']}");
            }
            
            if (isset($result['rollback'])) {
                $io->section('Rollback Results');
                $rollbackSuccess = $result['rollback']['success'] ? '✓' : '✗';
                $io->writeln("  {$rollbackSuccess} Rollback executed");
            }
        }
        
        return $result['success'] ? Command::SUCCESS : Command::FAILURE;
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
}