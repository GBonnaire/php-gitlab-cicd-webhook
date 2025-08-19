<?php

namespace Gbonnaire\PhpGitlabCicdWebhook\Command;

use Gbonnaire\PhpGitlabCicdWebhook\Service\ConfigService;
use Gbonnaire\PhpGitlabCicdWebhook\Service\DeployerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test',
    description: 'Test deployment for a repository'
)]
class TestCommand extends Command
{
    public function __construct(
        private ConfigService $config,
        private DeployerService $deployer
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Repository name to test')
            ->setHelp('This command allows you to test deployment for a repository...');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = $input->getArgument('name');

        $repository = $this->config->getRepository($name);
        if (!$repository) {
            $io->error("Repository '{$name}' not found");
            return Command::FAILURE;
        }

        $io->section("Testing deployment for: {$name}");

        $mockData = [
            'ref' => 'refs/heads/' . $repository['branch'],
            'checkout_sha' => 'test-commit'
        ];

        $result = $this->deployer->deploy($repository, $mockData);

        if ($result['success']) {
            $io->success('Test deployment successful: ' . $result['message']);
            return Command::SUCCESS;
        } else {
            $io->error('Test deployment failed: ' . $result['message']);
            return Command::FAILURE;
        }
    }
}